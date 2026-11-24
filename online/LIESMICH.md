# Online-Anzeige des Heizungsmonitors

Reine Anzeige auf einem Webserver (PHP ab 7.0, keine Datenbank). Der Raspi schickt alle 3 Minuten die Dashboard-Daten
an `empfang.php`. Von hier aus lässt sich am Raspi nichts auslösen (keine Pause, keine Testnachricht).

## Einrichten

1. Den ganzen Ordner `online/` auf den Webserver hochladen, z. B. als `https://DEINE-DOMAIN.de/heizung/`
   (inkl. der versteckten Dateien `.htaccess` und `data/.htaccess`).
2. Der Ordner `data/` muss für PHP beschreibbar sein (bei den meisten Hostern automatisch, sonst Rechte 755 bzw. 775).
3. **Passwortschutz** einrichten – am einfachsten im Verwaltungsbereich des Hosters („Verzeichnisschutz“) für den
   Ordner `heizung`. Wichtig: `empfang.php` und `waechter.php` müssen ohne Passwort erreichbar bleiben; die `.htaccess`
   gibt sie frei. Alternativ die vorbereiteten Zeilen in `.htaccess` nutzen (Pfad zu `.htpasswd` eintragen).
4. Auf dem Raspi in `~/heizung/online_config.php` die Adresse eintragen und einschalten:
   `'enabled' => true,` und `'url' => 'https://DEINE-DOMAIN.de/heizung/empfang.php',`
   (bearbeiten mit `nano ~/heizung/online_config.php`, speichern mit Strg+O, Enter, Strg+X), dann
   `sudo systemctl restart heizung-monitor`.
5. Nach ein paar Minuten zeigt das Dashboard auf dem Raspi oben „Online-Anzeige: gesendet hh:mm“ und
   `https://DEINE-DOMAIN.de/heizung/` die Daten.

Der geheime Schlüssel (`token`) steht schon passend in `config.php` und in `online_config.php` auf dem Raspi.
Nicht weitergeben – wer ihn kennt, könnte falsche Daten einspielen.

## Verlauf

Der Webserver sammelt den Verlauf selbst in `data/verlauf_JJJJ-MM-TT.php` (eine Datei pro Tag, ca. 0,5 MB, gesperrt gegen
direkten Abruf). Standard: 400 Tage, danach werden alte Tage gelöscht – änderbar mit `'verlauf_tage' => …` in `config.php`.
Beim ersten Kontakt holt der Raspi den ganzen Verlauf seit Beginn seiner Aufzeichnung nach (2 Tage pro Minute).

## Wächter (optional)

Meldet per ntfy, wenn der Raspi länger als 20 Minuten keine Daten schickt (Stromausfall, Internet weg, Raspi abgestürzt),
und noch einmal, wenn er wieder da ist.

1. In `config.php` unter `waechter` `'enabled' => true` setzen und bei `ntfy_topic` dasselbe Thema wie auf dem Raspi eintragen.
2. `waechter.php?schluessel=…` (Schlüssel steht in `config.php`) alle 5 Minuten aufrufen lassen:
   per Cron-Job des Hosters oder kostenlos über cron-job.org.

## Aktualisieren

`index.html` ist eine Kopie von `monitor.html` vom Raspi. Bei einer neuen Version des Monitors `index.html` (und ggf. die
PHP-Dateien) neu hochladen. `config.php` und `data/` dabei **nicht** überschreiben.
