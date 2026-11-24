#!/usr/bin/env bash
# Installiert den Heizungsmonitor auf dem Raspberry Pi als Dauerdienst.
#
#   bash ~/heizung/raspi/install.sh
#
# Richtet ein:
#   heizung-monitor  – misst jede Minute, startet nach Absturz/Stromausfall selbst neu
#   heizung-web      – Dashboard unter http://<pi>:8088/  (eingebauter PHP-Webserver)
# Mehrfaches Ausführen ist unschädlich (z. B. nach einem Update der Dateien).
set -euo pipefail

PORT="${HEIZUNG_PORT:-8088}"
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
NUTZER="$(id -un)"
PHP=""

gruen() { printf '\033[32m%s\033[0m\n' "$*"; }
gelb()  { printf '\033[33m%s\033[0m\n' "$*"; }
rot()   { printf '\033[31m%s\033[0m\n' "$*"; }
schritt() { printf '\n\033[1m== %s\033[0m\n' "$*"; }

[ "$(id -u)" -eq 0 ] && [ -z "${HEIZUNG_TEST:-}" ] && { rot "Bitte NICHT mit sudo starten, sondern als normaler Benutzer: bash $0"; exit 1; }
[ -f "$APP/monitor.php" ] || { rot "monitor.php nicht gefunden in $APP – liegt raspi/ im richtigen Ordner?"; exit 1; }

schritt "1/6 PHP prüfen"
php_ok() { command -v php >/dev/null && php -r 'exit(version_compare(PHP_VERSION, "8.0.0", ">=") ? 0 : 1);'; }
if ! php_ok; then
    . /etc/os-release
    gelb "PHP 8 fehlt (System: $PRETTY_NAME). Installiere php-cli und php-mbstring …"
    sudo apt-get update -qq
    if ! sudo apt-get install -y -qq php-cli php-mbstring || ! php_ok; then
        if [ "${VERSION_CODENAME:-}" = "bullseye" ] || [ "${VERSION_CODENAME:-}" = "buster" ]; then
            gelb "Dieses Raspberry Pi OS ($VERSION_CODENAME) bringt nur PHP 7 mit. Füge die PHP-Paketquelle von packages.sury.org hinzu …"
            sudo apt-get install -y -qq lsb-release ca-certificates curl gnupg
            curl -fsSL https://packages.sury.org/php/apt.gpg | sudo gpg --dearmor -o /usr/share/keyrings/sury-php.gpg
            echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $VERSION_CODENAME main" | sudo tee /etc/apt/sources.list.d/sury-php.list >/dev/null
            sudo apt-get update -qq
            sudo apt-get install -y -qq php8.2-cli php8.2-mbstring
        fi
    fi
fi
php_ok || { rot "PHP 8 konnte nicht installiert werden. Bitte die Ausgabe oben an Claude schicken."; exit 1; }
php -r 'exit(function_exists("mb_substr") ? 0 : 1);' || sudo apt-get install -y -qq "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-mbstring"
php -r 'exit(function_exists("mb_substr") ? 0 : 1);' || { rot "PHP-Erweiterung mbstring fehlt."; exit 1; }
php -r 'exit(extension_loaded("openssl") ? 0 : 1);' || { rot "PHP-Erweiterung openssl fehlt (für Push, Wetter und Cloud nötig)."; exit 1; }
PHP="$(command -v php)"
gruen "OK: $("$PHP" -r 'echo "PHP ".PHP_VERSION;')"

schritt "2/6 Dateien prüfen"
for f in lib/*.php api.php monitor.php monitor_config.php config.php cloud_config.php; do
    [ -f "$APP/$f" ] || continue
    "$PHP" -l "$APP/$f" >/dev/null || { rot "Syntaxfehler in $f"; exit 1; }
done
mkdir -p "$APP/data"
# Windows-Zeilenenden in den Shell-Dateien entfernen (falls über Windows kopiert)
sed -i 's/\r$//' "$APP"/raspi/*.sh 2>/dev/null || true
gruen "OK: Dateien in $APP"

schritt "3/6 Verbindungen testen"
APP="$APP" "$PHP" -r '
require getenv("APP") . "/lib/Hargassner.php";
$c = require getenv("APP") . "/config.php"; $m = require getenv("APP") . "/monitor_config.php";
$d = $c["devices"][$m["device"]];
$r = Hargassner::read($d["host"], (int) $d["port"], 3, 3);
echo $r["ok"] ? "  Pelletkessel {$d["host"]}: OK, " . count($r["values"]) . " Werte\n" : "  Pelletkessel {$d["host"]}: NICHT erreichbar ({$r["error"]}) – bei abgeschalteter Heizung normal\n";
$w = @file_get_contents("https://api.open-meteo.com/v1/forecast?latitude=47.74&longitude=10.31&current=temperature_2m");
echo $w ? "  Open-Meteo: OK\n" : "  Open-Meteo: nicht erreichbar\n";
$n = $m["notify"]["ntfy"] ?? null;
if ($n && !empty($n["enabled"])) { $h = @get_headers(rtrim($n["server"], "/") . "/v1/health"); echo $h ? "  ntfy: OK\n" : "  ntfy: nicht erreichbar\n"; }
' || true

schritt "4/6 Dienst heizung-monitor einrichten"
sudo tee /etc/systemd/system/heizung-monitor.service >/dev/null <<EOF
[Unit]
Description=Heizungsmonitor (Hargassner, Ausbrand- und Puffer-Meldungen)
After=network-online.target
Wants=network-online.target

[Service]
User=$NUTZER
WorkingDirectory=$APP
Environment=TZ=Europe/Berlin
ExecStart=$PHP $APP/monitor.php
Restart=always
RestartSec=30

[Install]
WantedBy=multi-user.target
EOF
gruen "OK"

schritt "5/6 Dienst heizung-web (Dashboard, Port $PORT) einrichten"
if ss -ltn 2>/dev/null | grep -q ":$PORT " && ! systemctl is-active --quiet heizung-web; then
    rot "Port $PORT ist schon belegt. Anderen Port wählen: HEIZUNG_PORT=8089 bash $0"; exit 1
fi
sudo tee /etc/systemd/system/heizung-web.service >/dev/null <<EOF
[Unit]
Description=Heizungsmonitor Dashboard (PHP-Webserver, Port $PORT)
After=network-online.target

[Service]
User=$NUTZER
WorkingDirectory=$APP
Environment=TZ=Europe/Berlin
Environment=PHP_CLI_SERVER_WORKERS=4
ExecStart=$PHP -S 0.0.0.0:$PORT -t $APP $APP/raspi/router.php
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
gruen "OK"

schritt "6/6 Dienste starten"
sudo systemctl daemon-reload
sudo systemctl enable --now heizung-web.service >/dev/null
sudo systemctl enable heizung-monitor.service >/dev/null
sudo systemctl restart heizung-monitor.service heizung-web.service
sleep 4
ok=1
systemctl is-active --quiet heizung-monitor && gruen "heizung-monitor läuft" || { rot "heizung-monitor läuft NICHT"; ok=0; }
systemctl is-active --quiet heizung-web     && gruen "heizung-web läuft"     || { rot "heizung-web läuft NICHT"; ok=0; }
IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

echo
if [ $ok -eq 1 ]; then
    gruen "Fertig. Dashboard: http://${IP:-192.168.178.66}:$PORT/monitor.html"
else
    rot "Es gab Probleme. Bitte die Ausgabe von"
    echo "  journalctl -u heizung-monitor -u heizung-web -n 40 --no-pager"
    rot "an Claude schicken."
fi
cat <<EOF

Nützliche Befehle:
  journalctl -u heizung-monitor -f          laufende Messungen ansehen (Strg+C beendet)
  sudo systemctl restart heizung-monitor    nach Änderungen an monitor_config.php
  sudo systemctl stop heizung-monitor       Monitor anhalten
EOF
