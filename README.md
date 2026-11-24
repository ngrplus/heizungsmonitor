# Heizungsmonitor (Hargassner)

Überwacht eine Hargassner-Heizung (Pelletkessel Rennergy Mini.2 per Telnet, Scheitholzkessel MidiHV über die
Hargassner-Cloud) und schickt Push-Nachrichten über ntfy: „Jetzt nachlegen“ beim Ausbrand, „Bald startet der
Pelletkessel – jetzt Holz anheizen“, „Heute Abend noch Holz anheizen?“, Verbindungsausfall. Dashboard im Browser,
optional als Nur-Anzeige auf einem Webserver (`online/`). Läuft auf einem Raspberry Pi (`raspi/install.sh`).

Versionen: `CHANGELOG.md` · Erkenntnisse zu den Anlagen und Auswertungen: `ERKENNTNISSE.md`

## Einrichten: Zugangsdaten

Die Dateien mit Zugangsdaten sind nicht im Repository. Vorlagen kopieren und ausfüllen:

    cp monitor_config.beispiel.php monitor_config.php   # ntfy-Thema, MQTT
    cp cloud_config.beispiel.php   cloud_config.php     # Hargassner-App: E-Mail, Passwort
    cp online_config.beispiel.php  online_config.php    # optional: Online-Anzeige (Adresse, Schlüssel)
    cp online/config.beispiel.php  online/config.php    # auf dem Webserver: gleicher Schlüssel wie online_config.php

Einen Schlüssel für die Online-Anzeige erzeugen: `php -r 'echo bin2hex(random_bytes(24)), "\n";'`

---

# Hargassner Telnet-Test

Kleine Web-Anwendung für XAMPP, die die Klartext-Datensätze der Hargassner-Kesselregelung
über TCP-Port 23 liest und als Tabelle anzeigt. Keine Zusatzpakete nötig – nur PHP.

## Installation (Windows / XAMPP)

1. Die App liegt in `C:\xampp\htdocs\abacus\heizung\monitor\`.
2. Im XAMPP Control Panel **Apache** starten.
3. Im Browser öffnen: **http://localhost/abacus/heizung/monitor/**

Geräte und IP-Adressen stehen in `config.php` (vorbelegt mit HSV1 .198, hargasmart .77,
Gateway .30 und dem Simulator).

## Erste Schritte

1. Gerät links anklicken → **Port-Test**. Grün „23 offen“ = Telnet-Dienst erreichbar.
2. **Jetzt lesen** holt einen Datensatz. Oben steht die Rohzeile (`pm …`), darunter die Werte.
3. **Live** aktivieren und mit dem Kesseldisplay vergleichen. Spalte Δ zählt Änderungen,
   Min/Max zeigen die Spannweite – so lassen sich unbekannte Positionen zuordnen.
4. Bezeichnungen direkt in der Tabelle eintragen → **Bezeichnungen speichern**
   (landet in `data/labels_<gerät>.json`).
5. **CSV** exportiert alle in dieser Sitzung gelesenen Datensätze (Excel-tauglich, Semikolon).

## Ohne Heizung testen

    C:\xampp\php\php.exe C:\xampp\htdocs\abacus\heizung\monitor\mock_server.php

Dann in der UI das Gerät „Simulator“ wählen.

## Dauerhaft mitschreiben

    C:\xampp\php\php.exe logger.php hsv1 60

schreibt jede Minute eine Zeile nach `data/log_hsv1.csv`. Mit `once` statt `60` wird nur ein
Datensatz gelesen – geeignet für die Windows-Aufgabenplanung. Das ist die Grundlage für die
späteren Benachrichtigungen (Ausbrand, Puffer kalt …).

## Heizungsmonitor (Ausbrand- und Puffer-Warnung)

1. `start_monitor.bat` doppelklicken. Das Fenster offen lassen – es misst jede Minute.
2. Dashboard: **http://localhost/abacus/heizung/monitor/monitor.html**
3. Schwellen und Benachrichtigungen in `monitor_config.php` einstellen.
   `C:\xampp\php\php.exe monitor.php test` schickt eine Testnachricht.

Erkennt: Scheitholz brennt / ausgebrannt (mit Sonnenausgleich über Open-Meteo, damit die Solarthermie keinen Fehlalarm auslöst), Puffer fast leer,
Pelletkessel startet, keine Daten von der Heizung.
Ereignisse stehen in `data/events.jsonl`, der Verlauf in `data/history_<datum>.csv`, alle Rohzeilen in `data/raw_<datum>.txt`.
Mit `php tests/simulate.php` lässt sich die Logik an einem künstlichen Tagesverlauf prüfen,
mit `php tests/test_rules.php` die Benachrichtigungsregeln. `php tests/replay.php data/history_*.csv` spielt
aufgezeichnete echte Verläufe erneut durch (mit `--set holz.start_k_pro_h=3` lassen sich Schwellen ausprobieren).
Was über die Anlagen bekannt ist, steht in `ERKENNTNISSE.md`.

## Hargassner-Cloud erkunden (für den Stückholzkessel)

1. In `cloud_config.php` E-Mail und Passwort des Hargassner-App-Kontos eintragen.
2. **http://localhost/abacus/heizung/monitor/cloud.html** öffnen → „Anlagen laden“ → Kessel wählen.
3. Alle Werte, die die Cloud liefert, erscheinen als Tabelle. Jede Abfrage wird in
   `data/cloud_widgets_<anlage>_<zeit>.json` gespeichert.

Nutzt dieselbe inoffizielle Schnittstelle wie die Hargassner-App. Nicht öfter als etwa einmal pro Minute abfragen.

## Wichtig zu wissen

- **Belegung teilweise bestätigt.** `mappings/hsv1_hypothese.json` gilt für den Pelletkessel
  (192.168.178.198); bestätigte Positionen sind mit ✓ markiert, Details in `ERKENNTNISSE.md`.
- **Der Stückholzkessel (192.168.178.77) bietet kein Telnet.** Seine Ausbrand-Erkennung läuft
  indirekt über die Pufferwerte des Pelletkessels.
- **Eine Verbindung zur Zeit?** Manche Steuerungen erlauben nur einen Telnet-Client. Falls
  keine Daten kommen, kurz testen, ob das Internet-Gateway die Verbindung belegt.
- **Kanalliste von der SD-Karte:** Touchtronic-Steuerungen können Daten auf SD-Karte
  schreiben; die Kopfzeile dieser Dateien bzw. eine Datei wie `channel-*.txt` enthält die
  offiziellen Spaltennamen in Telnet-Reihenfolge. Das ist die zuverlässigste Quelle für die Belegung.
- **Nur lesen.** Die Anwendung sendet keine Befehle an die Steuerung.
- **Nicht ins Internet freigeben.** Telnet ist unverschlüsselt; XAMPP-Apache nur im Heimnetz betreiben.

## Dateien

| Datei | Zweck |
|---|---|
| `index.html` | Web-Oberfläche |
| `api.php` | JSON-API (devices, probe, read, save_labels) |
| `lib/Hargassner.php` | Telnet-Leser und Parser |
| `config.php` | Geräte, Timeouts |
| `mappings/*.json` | Bekannte Feldbelegungen |
| `mock_server.php` | Simulator auf Port 2323 |
| `logger.php` | CSV-Logger für die Kommandozeile |
| `monitor.php`, `start_monitor.bat` | Dauerlauf der Überwachung |
| `monitor_config.php` | Schwellen, Feldpositionen, Push-Kanäle |
| `monitor.html` | Dashboard mit Verlauf und Ereignissen |
| `lib/Monitor.php`, `lib/Notifier.php` | Erkennungslogik und Versand |
| `tests/simulate.php` | Test mit künstlichem Tagesverlauf |
| `ERKENNTNISSE.md` | Was über die Anlagen bekannt ist |
| `cloud.html`, `cloud_config.php`, `lib/HargassnerCloud.php` | Abfrage der Hargassner-Cloud |
| `mqtt.html`, `lib/Mqtt.php` | MQTT-Explorer für den Broker im Heimnetz (z. B. PV-Daten) |
| `lib/Sonne.php` | Sonneneinstrahlung von Open-Meteo für den Sonnenausgleich (Cache in `data/sonne.json`) |
