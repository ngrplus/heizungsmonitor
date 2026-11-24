# Versionen des Heizungsmonitors

## 1.9 – 18.11.2026
- Liefert der Pelletkessel keine Daten (z. B. neue IP-Adresse wie am 18.11.), meldet der Monitor Holzfeuer und
  „Jetzt nachlegen“ trotzdem über die Hargassner-Cloud. Die Pufferwerte fehlen dann; die Meldung weist darauf hin.
- Pelletkessel hat jetzt die IP 192.168.178.123 (config.php); in der FritzBox fest zuweisen.

## 1.8.1 – 12.11.2026
- Online-Anzeige: Solange der Raspi den Verlauf noch nachholt, schickt er weiter den 48-h-Ausschnitt mit und die
  Anzeige mischt ihn ein – vorher blieb das Diagramm in dieser Zeit leer.
- Dashboard Raspi: zeigt beim Nachholen den Stand („Verlauf nachholen, Stand 28.10.“).

## 1.8 – 12.11.2026
- Online-Anzeige sammelt den Verlauf selbst (Tagesdateien `data/verlauf_JJJJ-MM-TT.php`, 400 Tage, einstellbar mit
  `'verlauf_tage'` in `online/config.php`). Der Raspi schickt nur noch, was dem Webserver fehlt; beim ersten Mal und nach
  Unterbrechungen holt er den Verlauf in 2-Tages-Etappen (1 pro Minute) nach. Online lässt sich damit genauso weit
  zurückblättern wie auf dem Raspi. Sendungen danach ca. 6 kB statt 25 kB.

## 1.7 – 12.11.2026
- Diagramme lassen sich verschieben: ziehen (Maus/Finger), Trackpad seitlich bzw. Shift+Mausrad, Schieberegler,
  Knöpfe ◀ ▶ und „jetzt“. Zurück bis zum Beginn der Aufzeichnung (Online-Anzeige: so weit, wie der Raspi mitschickt).
- Zeitraum zusätzlich 96 h und Woche; Zeitachse mit Wochentag und Datum.
- Datenlücken (> 15 min ohne Messung) werden nicht mehr überbrückt.
- Kachel Pelletkessel kompakter („Pellets heute 8 · Woche 55 · Lager 3500 kg“), Kachel Sonne zeigt die Außentemperatur.

## 1.6 – 07.11.2026
- „Bald startet der Pelletkessel – jetzt Holz anheizen“ kommt jetzt auch, wenn Puffer oben auf 57 °C fällt. Nach einem
  Holzfeuer ist der Puffer durchmischt; der Füllgrad steht dann beim Pelletstart noch bei ca. 91 % (04.11. 05:29, 07.11. 12:52),
  die Füllgrad-Schwelle 82 % griff nicht. Wieder scharf erst, wenn Füllgrad ≥ 95 % und Puffer oben ≥ 62 °C.
- Holz: Flammt die Glut nach dem Ausbrand ohne Zündung kurz wieder auf (07.11.: 3 × 10–15 min), gibt es keine weiteren
  Meldungen. Brennt es länger als 30 min (nachgelegt), kommt beim nächsten Ausbrand wieder „Jetzt nachlegen“.

## 1.5 – 04.11.2026
- ntfy-Nachrichten enthalten einen Link zur Online-Anzeige (Antippen der Nachricht oder Knopf „Heizungsmonitor“).
  Adresse automatisch aus `online_config.php` (Ordner von `url`), abweichend über `'anzeige_url'`. Auch für den Wächter.

## 1.4 – 04.11.2026
- Pelletverbrauch je Brennzeit aus dem Verbrauchszähler (Pos. 44, ganze kg): Protokolleintrag „Pelletkessel aus – 13 kg“,
  Liste in `data/pellet_brennzeiten.csv`, Beschriftung im lila Feld des Diagramms.
- Kachel Pelletkessel: Leistung in %, Pellets heute / letzte 7 Tage, Lagerstand.
- Verlauf zeichnet zusätzlich Verbrauchszähler, Lagerstand und Kesselleistung auf (PVERBR, PLAGER, PLEIST).

## 1.3 – 04.11.2026
- Neu: „Heute Abend noch Holz anheizen?“ um 21:00, wenn der Puffer voraussichtlich nicht bis zum Morgen reicht
  (Puffer-Mittel bis 06:15 unter 60 °C bei 1,5 K/h Abkühlung) und weder Holz noch Pellet heizt.
- Neu: Online-Anzeige (Ordner `online/` für einen Webserver, ohne Datenbank). Der Raspi sendet alle 3 min
  (`online_config.php`, `lib/Online.php`), Dashboard mit Nur-Anzeige-Modus, optionaler Wächter per ntfy.
- Dashboard-Daten in `lib/Dashboard.php` zusammengefasst (gleich für Raspi und Online-Anzeige).

## 1.2 – 03.11.2026
- Diagramme: Zeiten, in denen der Pelletkessel brennt, sind hinterlegt (lila, „Pellet brennt“).

## 1.1 – 03.11.2026
- Holzfeuer und Ausbrand aus der Hargassner-Cloud (Leistungsbrand → Ausbrand); Puffer-Erkennung nur noch als Ersatz.
- Pelletkessel: Entaschung/Reinigung (Zustand 9/10/12) zählen zum Brand, danach 30 min Nachlauf → keine falschen Holz-Meldungen mehr.
- Neu: „Bald startet der Pelletkessel – jetzt Holz anheizen“ bei Füllgrad < 82 %.
- „Puffer kühlt ab“ direkt nach einem Pelletstart unterdrückt.
- Versionsanzeige im Dashboard, Warnung wenn der Monitor nach einem Update nicht neu gestartet wurde.

## 1.0 – 22.10.2026
- Erste Version auf dem Raspberry Pi: Telnet-Daten des Pelletkessels, Holz-Erkennung über den Puffer,
  Sonnenausgleich (Open-Meteo), Cloud-Daten des Scheitholzkessels (nur Anzeige), ntfy-Push, Wartungspause.
