# Erkenntnisse zu den Hargassner-Anlagen

Stand: 21.10.2026, 22:30 · Quelle: Port-Test, Telnet-Daten und Display-Fotos im Ordner `pictures/`

## Betrieb auf dem Raspberry Pi (seit 22.10.26, 20:43)

- Raspberry Pi 192.168.178.66 (Raspbian 11 Bullseye, armhf, Kernel 5.15.61, **ei23-Smart-Home-System** mit Docker,
  Node-RED, Grafana, Mosquitto). Benutzer `pi`, Programm in `/home/pi/heizung`.
- PHP 8.2 von packages.sury.org (Bullseye bringt nur 7.4 mit); `update-alternatives` auf php8.2 gestellt.
- Dienste (systemd, starten automatisch, Neustart nach Absturz):
  `heizung-monitor` (Messung jede Minute) und `heizung-web` (Dashboard **http://192.168.178.66:8088/monitor.html**,
  eingebauter PHP-Webserver, interne Dateien über `raspi/router.php` gesperrt).
- Installation/Update: Ordner nach `/home/pi/heizung` kopieren, `bash ~/heizung/raspi/install.sh`.
- Wartungspause im Dashboard (z. B. für Service): misst weiter, schickt keine Push-Nachrichten.
- **Ab jetzt ist der Pi das Original.** Der Ordner auf dem Laptop (`C:\xampp\htdocs\abacus\heizung\monitor`)
  ist nur noch eine Kopie; Änderungen müssen auf den Pi.
- Offen für später: Lebenszeichen an externen Wächter (z. B. healthchecks.io), falls der Pi ausfällt;
  Messwerte in InfluxDB/Grafana (ei23); eigener ntfy-Server als Container.

## Geräte im Netz

| Gerät | IP / MAC | Typ | Software | Telnet 23 | Port 80 | Modbus 502 |
|---|---|---|---|---|---|---|
| HSV1 | 192.168.178.198 / 00-24-BD-02-E4-41 | **Pelletkessel Rennergy Mini.2 20** (Marke von Hargassner), Touchtronic-Display | V14.0REN.p2, I/O-Firmware V1.2.0, Komm.-Nr. 2109749 | **offen, liefert Daten** | offen | zu |
| hargasmart | 192.168.178.77 / 80-1F-12-4D-90-56 | **Stückholzkessel MidiHV-17**, neue Touch-Steuerung | midi_hv_3.4.0 (Update 23.06.26), System-ID 01147953A1, Komm.-Nr. 2109588 | zu | offen, aber leere Seite | zu |
| HARGA.IGW.0044631 | 192.168.178.30 / 44-B7-D0-83-95-EF | Internet-Gateway S/N 0044631 | – | zu | zu | zu |

- Das Internet-Gateway hängt am Pelletkessel (Pellet-Display: „Internetgateway verbunden“).
- Trotz laufendem Gateway liefert der Pelletkessel parallel Telnet-Daten – beides funktioniert gleichzeitig.
- Die Web-Oberfläche des Stückholzkessels liefert im Browser nur eine leere Seite; die üblichen API-Pfade antworten nicht.

## Telnet-Datensatz des Pelletkessels

Die Steuerung sendet ca. alle 0,5 s eine Zeile `pm …` mit **137 Werten**: 129 Messwerte (Pos. 0–128)
und 8 Statuswörter in Hex (Pos. 129–136).

Beispiel (21.10.26, 21:49):

    pm 1 21 7.4 71.2 0 69.3 36 5 82.9 24 25 70.8 70.0 65.1 100 35 0 2 0 71 …

**Quelle der Belegung:** Community-Kanalliste `channel-nanoPK-v14.0HAR.p.txt` aus github.com/Jahislove/Hargassner
(Firmware V14.0HAR.p – unser Kessel: V14.0REN.p2, gleiche Versionsreihe) und die Statusbits aus
github.com/TheRealKillaruna/nano_pk (laut Nutzern auch mit Rennergy-Mini-Kesseln im Einsatz).
Umgesetzt in `mappings/rennergy_mini2_v14p.json`.

### Pos. 0–47: Kanalliste passt

| Pos. | Wert 21:49 | Bedeutung | Status |
|---|---|---|---|
| 0 | 1 | Kesselzustand (Code, s. u.) | Liste |
| 1 | 21 | O2 im Rauchgas % (21 = kein Feuer) | ✓ Display |
| 2 | 7.4 | O2 soll | Liste |
| 3 | 71.2 | Kesseltemperatur ist | ✓ Display |
| 5 | 69.3 | Rücklauftemperatur | Liste |
| 7 | 5 | Spreizung Vor-/Rücklauf | Liste |
| 8 | 82.9 | **Rauchgastemperatur** – vermutlich die 78 °C unter dem Kessel auf dem Display | Liste |
| 9 / 10 | 24 / 25 | Saugzug ist / soll % | Liste |
| 11–13 | 70.8 / 70.0 / 65.1 | Puffer oben / mitte / unten | ✓ Display |
| 14 | 100 | Pufferfüllgrad % | ✓ Display |
| 17 | 2 | Pufferzustand (Code) | Liste |
| 20 | 0 | Kesselleistung % | Liste |
| 29 | 3 | Programm (Code) | Liste |
| 30 | 0 | **Störungsnummer** (0 = keine) | Liste |

**Wichtig (21.10.26, 23:20):** Bei ausgeschaltetem Pelletkessel zeigt O2 (Pos. 1) dauerhaft 1.2 statt 21 –
die Lambdasonde ist dann offenbar nicht aktiv. „Pelletkessel aus“ wird deshalb über den **Kesselzustand (Pos. 0)**
erkannt, nicht über O2.

### Ab Pos. 37: Rennergy-Firmware weicht ab

- **Lagerstand = Pos. 43, Pelletverbrauch = Pos. 44** (von Hand bestätigt) → ab ca. Pos. 41 **+2 versetzt**
  gegenüber der Kanalliste. Passt auch zu Netzteilspannung Pos. 49 (24089 mV = 24 V) und Außentemperatur Pos. 56.
- Pos. 37–42 unklar (Bereich, in dem die zwei zusätzlichen Kanäle stecken).

- Die Heizkreis-Blöcke beginnen bei uns bei **Pos. 60** statt 57 (Liste) → ab hier **+3 versetzt**.
  Je Heizkreis 6 Werte: Vorlauf ist, Vorlauf soll, Raum ist, Raum soll, Zustand, Fernbedienung.
  Reihenfolge der Blöcke laut Liste: HK A, HK 1, HK 2, HK B. Um 21:49 aktiv: HK 1 (36,1 °C) und HK 2 (34,6 °C, soll 35).
- Außentemperatur Pos. 56 (7.3; Display 7 °C) – durch Nachtverlauf bestätigt, gemittelt Pos. 57.
- Pos. 84–128 nicht in der Kanalliste. Pos. 122 = 258 passt zu „Betriebsstunden Zündung 258,9 h“.

### Pos. 129–136: Statuswörter (Hex)

Wort 0 = `41E` → keine Störung (Bit 0 = 0), STB ok, Füllstand ok, Rücklauf-/Pufferpumpe an.
Wort 2 = `40` → Heizkreispumpe 2 an (passt zum aktiven HK 2).
**Wort 0, Bit 0 = Störung** – brauchbar für eine Störungsmeldung des Pelletkessels.

### Kesselzustand (Pos. 0), aus dem französischen Projekt übersetzt

0 Initialisierung · 1 Aus · 2 Startvorbereitung · 3 Kesselstart · 4 Zündüberwachung · 5 Zündung ·
6 Übergang Leistungsbrand · 7 Leistungsbrand · 8 Bereitschaft · 9 Halt für Entaschung · 10 Entaschung ·
11 Abkühlen · 12 Reinigung · 15 Handbetrieb · 17 Verbrennungsassistent

### Zuverlässigste Quelle für den Rest

Die Steuerung kann auf SD-Karte protokollieren. Die Datei `DAQ00000.DAQ` beginnt mit einem `<DAQPRJ>…</DAQPRJ>`-Block,
der die **offizielle Kanalliste genau dieser Firmware** enthält (Anleitung bei nano_pk). Damit ließen sich auch
Pos. 48–128 sicher zuordnen.

Display-Zähler zum Abgleich: Betriebsstunden Steuerung 44005 h, Heizung 5020 h, Zündungen 2417,
Saugzug 8390,8 h, Einschub 2326,1 h, Ascheschnecke 485,0 h, Wärmemenge 68737 kWh.

## Erster echter Abbrand (Nacht 21./22.10.26)

Angeheizt ca. 23:00–23:15, Pelletkessel die ganze Nacht aus (Zustand 1), außen 4–8 °C.

| Zeit | Puffer oben / mitte / unten | Mittel | Trend | Phase |
|---|---|---|---|---|
| 23:21 | 70 / 69 / 62 | 67 | −0,7 K/h | vor dem Feuer |
| 23:41 | 70 / 69 / 68 | 69 | +4 K/h | Puffer beginnt zu laden (unten zuerst) |
| 23:51 | 73 / 69 / 68 | 70 | +10 K/h | **erkannt: Holz brennt** |
| 00:00–01:10 | → 82 / 79 / 78 | → 80 | 6–9 K/h | Hauptbrand |
| 01:10–04:50 | → 90,5 / 89,7 / 88,5 | → 89,6 | 2–3 K/h | Puffer nahezu voll, Ladung wird langsamer |
| 04:53 | 90,5 / 89,7 / 88,5 | 89,6 | +1,4 K/h | **Höchststand = Ende der Wärmeabgabe** |
| 04:57 | | | < 0 | Puffer beginnt zu sinken |
| 05:07 | 87 / 86 / 86 | 86,4 | −10 K/h | **erkannt: ausgebrannt** (neue Einstellung) |
| 05:15–06:35 | 84–90 schwankend | | | Restwärme-Pulse des Holzkessels |
| ab 07:00 | | | ca. −5 K/h | Verbrauch am Tag |

Erkenntnisse:
- **Holzbrand wird zuverlässig erkannt**, ca. 10–15 min nach Beginn der Pufferladung.
- **Abbranddauer ca. 5 h**, Puffer von Mittel 67 °C auf 89,6 °C. Voller Puffer ≈ 88–90 °C in allen Schichten.
- **Ausbrand** zeigt sich als Höchststand mit anschließendem schnellem Abfall. Nach dem Ausbrand schickt
  der Holzkessel ca. 1,5 h lang Restwärme-Pulse (Puffer oben springt um ±3 K) – das hatte mit der alten Einstellung
  (2 K/h) einen **Fehlstart** um 05:37 und eine zweite Ausbrand-Meldung um 06:06 ausgelöst.
- **Füllgrad (Pos. 14) stand die ganze Zeit auf 100 %**, schon bei 67 °C Mittel. Er ist oberhalb davon gesättigt
  und taugt nur für den unteren Bereich. Wann er unter 100 % fällt und wann der Pelletkessel startet, ist noch offen.
- Bei diesem Abbrand war der Puffer beim Ausbrand voll (unten 86 °C) → richtigerweise **keine** „Nachlegen“-Meldung.
- **Wärmeverlust am Vormittag ca. 5 K/h** (Puffer-Mittel 83,7 → 69,2 °C zwischen 07:00 und 10:00).
- Außentemperatur Pos. 56 passt zum Nachtverlauf (7,7 → 3,6 °C) → bestätigt.

Geänderte Einstellungen (`monitor_config.php`): Start-Schwelle 2 → **4 K/h** (verhindert den Fehlstart, echter Start
nur 2 min später erkannt), Ausbrand-Bestätigung 15 → **10 min** (Meldung 05:07 statt 05:12, ca. 14 min nach dem
Höchststand). Nachprüfbar mit `php tests/replay.php data/history_*.csv`.

## Wärmeabnehmer: Heizung und Warmwasser (22.10.26)

Heizung und Warmwasser ziehen Wärme aus dem Puffer. Läuft die Heizung während eines Holzfeuers, steigt der Puffer
deutlich langsamer – im Extremfall gar nicht. Nachts (Heizung aus, kein Warmwasser) stört das nicht.

**Heizung – im Datensatz des Pelletkessels sichtbar:**
- Statuswort 2 (Pos. 131) enthält die Heizkreispumpen. Am 21.10. 21:49: Wert `40` = Bit 6 = **Pumpe Heizkreis 2 an**,
  gleichzeitig Vorlauf-Soll HK 2 (Pos. 73) = 35 °C, HK 1 Vorlauf-Soll (Pos. 67) = 0 und Pumpe HK 1 aus → passt zusammen.
- Aktiv sind offenbar Heizkreis 1 und 2 (Vorlauf Pos. 66 / 72). Heizkreis A und B haben keinen Fühler (−20).

**Warmwasser – nicht direkt sichtbar:**
- Warmwasser kommt über eine **Frischwasserstation** (FWS-Symbol am Pellet-Display). Boilerfühler (Pos. 84) = −20,
  Boilerpumpen (Statuswort 3) = 0 → die Steuerung kennt den Warmwasserverbrauch nicht.
- Eine Zapfung (Duschen) zeigt sich höchstens indirekt: Puffer oben fällt kurz, unten kommt kaltes Wasser zurück.
  Kurze Zapfungen glättet das 20-min-Trendfenster weitgehend; ein langes Bad während des Abbrands könnte die
  Ausbrand-Erkennung theoretisch zu früh auslösen. → beobachten.

**Umsetzung (Verbrauchsausgleich):**
- *(Seit 22.10. 10:40 vorerst ausgeschaltet, siehe Solarthermie.)*
- Der Monitor erkennt „Heizung an“ über die Pumpenbits und **lernt den typischen Pufferabfall bei laufender Heizung**
  – nur aus Zeiten ohne Holz-/Pelletfeuer und nicht während der Restwärme. Bis genug gelernt ist (2 h), gilt −4 K/h
  (am 22.10. vormittags wurden ca. −5 K/h gemessen).
- Für die Holzerkennung wird der **Netto-Trend** verwendet: gemessener Trend minus erwarteter Verbrauch.
  Beispiel: Feuer liefert +6,5 K/h, Heizung zieht −5 K/h ab → Puffer selbst nur +1,5 K/h, netto +6,5 K/h → erkannt.
- Beim Ein-/Ausschalten der Heizung wird der Verbrauch über das Trendfenster gemittelt, damit kein Fehlalarm entsteht.
- Zusätzlich **Restwärme-Sperre**: 90 min nach einem Ausbrand gilt die doppelte Startschwelle (Restwärme-Stöße
  erreichten am 22.10. kurz 4,8 K/h).
- Ab sofort wird außerdem **jede Rohzeile** in `data/raw_<datum>.txt` gespeichert (30 Tage), damit spätere
  Auswertungen alle 137 Werte haben.

## Solarthermie (22.10.26)

Es gibt eine Solarthermie, die ebenfalls in den Puffer lädt. Am 22.10. vormittags (sonnig):
Puffer fiel bis 09:45 mit ca. −5 K/h (Heizung an), ab ca. 09:50 stieg er mit bis zu **+1,5 K/h**.

- **Problem:** Mit Verbrauchsausgleich sieht „Sonne + laufende Heizung“ genauso aus wie „Holzfeuer + laufende Heizung“
  (netto ca. +6 K/h) → Fehlalarm „Holz brennt“ und am Nachmittag ein falsches „Jetzt nachlegen“.
- **Sofortmaßnahme:** Verbrauchsausgleich **vorerst aus** (`monitor_config.php`). Ohne Ausgleich bleibt der
  Sonnenanstieg (max. 1,5 K/h) weit unter der Startschwelle von 4 K/h – geprüft am echten Verlauf.
  Der Verbrauch wird im Hintergrund trotzdem gelernt.
- **Solar im Datensatz?** Vergleich Nacht (21.10. 21:49) mit Sonnenvormittag (22.10. 10:33):
  Pos. 107/108 70,4/70,8 → 85,4/85,4 (nachts ≈ Puffer oben), Pos. 109/110 45/45,3 → 50/60,8,
  Pos. 118 129,6 → 35,3. 107/108 blieben über eine Minute konstant – ein Kollektorfühler würde sich eher bewegen.
  **Noch nicht zugeordnet.** Möglicherweise hat die Solaranlage einen eigenen Regler.
- **Mittags (Heizung aus) brachte die Sonne allein +3,0 K/h** → zu nah an der Startschwelle 4 K/h.
  Startschwelle vorübergehend auf **6 K/h** (echter Holzstart: 9–10 K/h, wird nur 3 min später erkannt).
- PV-Daten per MQTT verworfen (Werte unklar, Abregelung bei vollem Speicher). MQTT-Explorer (`mqtt.html`) bleibt.
- **Umgesetzt: Sonnenausgleich mit Open-Meteo** (Kempten, 47,74° N / 10,31° E, Viertelstundenwerte der Globalstrahlung).
  Abgleich 22.10. (klarer Himmel):

  | Uhrzeit | Einstrahlung | Puffer-Trend | Heizung |
  |---|---|---|---|
  | bis 09:30 | bis 190 W/m² | −4 bis −5 K/h | an |
  | 09:45–10:30 | 230–370 W/m² | ±0 bis +1,5 K/h | an |
  | 10:45–12:15 | 400–500 W/m² | +1 bis +4,1 K/h | aus |

  Schätzung Solarertrag = (Einstrahlung − 150 W/m²) / 100 × 1,2 K/h (bei 500 W/m² ≈ 4,2 K/h, bewusst reichlich).
  Er wird vom Trend abgezogen → Netto-Trend. Am 22.10. vormittags max. Netto 0,4 K/h (Schwelle 4) → großer Abstand.
  Startschwelle wieder **4 K/h netto**; ohne aktuelle Wetterdaten gilt vorsichtig **7 K/h**.
- **Verbrauchsausgleich bleibt aus:** Im Replay mit Sonnen- und Verbrauchsausgleich gab es einen Fehlalarm, weil die
  Heizungspumpe lief, der Puffer aber (noch) nicht fiel – z. B. bei zugefahrenem Mischer. Bei Bedarf später neu bewerten.
- **PV-Anlage:** 10 kWp, 2 Strings (3 + 1 Anschlüsse), gleiche Ausrichtung wie die Solarthermie.
  Bei vollem Speicher wird abgeregelt → dann liefert die PV weniger, als die Sonne hergibt.
- **MQTT (22.10. 12:10, Broker 192.168.178.66 mit Anmeldung):** Der ESP8266 sendet ca. alle 30–60 s
  `deye10kW/status` = `{"power":…, "total_energy":148373, "bat_soc":51, "bat_power":56366}`.
  `power` wechselte zwischen exakt 5466 und 17821 – 17821 W ist bei 10 kWp unmöglich → **Bedeutung unklar**.
  `bat_soc` ist plausibel (51 → 52 %). `bat_power` (≈56 360) sieht nach einem Rohregister aus.
  `deye10kW/pvPower` (14000) und `deye10kW/soc` (89) sind alte gespeicherte Werte.
  Weitere Themen: Tasmota-Steckdosen (Wallbox, Wärmepumpe Johannes), Stromzähler `tele/tasmota_Strom2a/SENSOR`
  (Watt_Summe negativ = Einspeisung).
- Nebenbefund 10:34: Heizkreis 2 schaltete ab (Vorlauf-Soll 36 → 0, Statuswort 2 von `40` auf `100` =
  Pumpe HK 2 aus, Mischer 2 „zu“) → bestätigt die Pumpenbits.

## Hargassner-Cloud (22.10.26, nach der Wartung wieder online)

Zugang über `cloud.html` / `lib/HargassnerCloud.php` mit den App-Zugangsdaten (`cloud_config.php`) funktioniert.
Die Cloud aktualisiert laut Antwort alle 300 s (`meta.refresh`).

**Scheitholzkessel MidiHV-17 (Anlage 41049)** – ein Widget `HEATER`:
`state` (z. B. `STATE_OFF`), `smoke_temperature` (Rauchgas), `heater_temperature_current` / `_target` (Kessel ist/soll, 76,2 / 85),
`efficiency` (Leistung %), `program`. → **direktes Signal für Brand und Ausbrand**, viel genauer als der Umweg über den Puffer.
Bekannte Zustände (aus ofg/hargassner-monitoring): `STATE_OFF`, `STATE_IGNITION`, `STATE_EFFICIENCY_FIRE`, `STATE_DEASHING`.
Welche Zustände der Holzkessel beim Abbrand durchläuft, zeigt der nächste Abbrand.

**Pelletkessel Mini.2 20 (Anlage 36608, „Heizung 2/2a“)** – Widgets HEATER, BUFFER, 2 × HEATING_CIRCUIT_RADIATOR,
FRESH_WATER. Abgleich mit der Telnet-Zeile von 14:15:

| Pos. | Telnet | Cloud | Bedeutung |
|---|---|---|---|
| 3 | 37,0 | 37 | Kesseltemperatur |
| 8 | 42,3 | 42,1 | Rauchgas (= „78 °C“ am Display am 21.10.) |
| 11–14 | 79,6 / 79,3 / 79,0 / 100 | gleich | Puffer oben/mitte/unten, Füllgrad (Puffer 1000 l) |
| 43 | 3739 | 3739 | Pellet-Lagerstand |
| 56 / 57 | 12,8 / 10,8 | gleich | Außentemperatur / gemittelt |
| 66 / 69 | 35,1 / 20,5 | 35 / 20,5 | Heizkreis 1 **ALTBAU**: Vorlauf ist, Raum soll |
| 72 / 75 | 27,5 / 19,0 | 27,5 / 19 | Heizkreis 2 **NEUBAU**: Vorlauf ist, Raum soll |
| 70 / 76 | 4 / 4 | DAILY_NIGHTLY_DEACTIVATION | Heizkreis-Zustand: 4 = Tagesabschaltung, 1 = heizen |
| 108 / 109 | 79,6 / 45 | gleich | Frischwasserstation: Vorlauf, Warmwasser soll |
| 118 / 119 | 42,6 / 102373 | gleich | **Warmwasser heute / gesamt (Liter)** |

- Heizzeiten laut Cloud: ALTBAU 06:30–09:30 und 16:45–21:45, NEUBAU 06:15–10:45 und 15:45–22:00
  (passt zum Abschalten von HK 2 am 22.10. um 10:34).
- **Warmwasser ist damit doch sichtbar:** Der Tageszähler (Pos. 118) steigt bei jeder Zapfung. Vermutlich zeigt Pos. 117
  die Pumpe der Frischwasserstation und Pos. 120 den Durchfluss. Wird jetzt mit aufgezeichnet.

**Umgesetzt:** Der Monitor fragt den Scheitholzkessel alle 5 min ab und speichert Zustand, Rauchgas und Kessel im Verlauf;
Zustandswechsel werden protokolliert (noch ohne Push). Dashboard-Kachel „Scheitholzkessel“. Die Telnet-Belegung
(`mappings/rennergy_mini2_v14p.json`) ist mit den Cloud-Namen ergänzt.
**Nächster Schritt:** Beim nächsten Abbrand vergleichen, wann die Cloud „Aus“ meldet und wann der Puffer-Trend kippt,
und dann die Ausbrand-Meldung auf die Cloud umstellen (Puffer-Erkennung als Absicherung, falls die Cloud ausfällt).

## Stückholzkessel: Weg zur Ausbrand-Erkennung

Kein direkter Datenzugang (kein Telnet, Web leer, Modbus zu). Gewählt: **Weg 1 – indirekt über den Puffer**,
dessen Fühler der Pelletkessel liest:

- Pelletkessel aus (O2 ≥ 20 %) **und** mittlere Puffertemperatur steigt → Stückholz brennt.
- Anstieg hört auf (Trend ≤ 0 K/h über 15 min) **oder** Pelletkessel startet → Ausbrand.
- Umgesetzt in `monitor.php` / `lib/Monitor.php`, Schwellen in `monitor_config.php`.

## Recherche Zugriff Stückholzkessel (21.10.26)

Die MidiHV-17 mit `midi_hv_3.4.0` gehört sehr wahrscheinlich zur neuen „Smart“-Steuerungsgeneration.
Was andere Nutzer dazu herausgefunden haben:

- **Kein Telnet, kein Modbus mehr.** Bei der neuen Generation wurde Telnet durch SSH mit Schlüssel-Login
  ersetzt; Modbus unterstützt der Hersteller laut Forum nicht (holzheizer-forum.de, Thread „Hargassner Smart Duo: MQTT + VNC“).
- **Cloud über MQTT.** Die Steuerung verbindet sich selbst mit `mqtt.hargassner.at:8883` (Topics
  `smart/harg/in|out/<Heizungs-ID>`). Daten kommen nur auf Anfrage. Den Kessel per DNS auf einen eigenen
  Broker umzuleiten scheitert laut Forum an der Zertifikatsprüfung.
- **VNC** lässt sich im Service-Menü (4-stelliger Servicecode) einschalten, schaltet aber das Display ab –
  nicht für Dauerbetrieb geeignet.
- **Hargassner-Cloud-API** (wie die App): `https://web.hargassner.at/api/installations/{id}/widgets`,
  Login mit App-Zugangsdaten, Bearer-Token. Nutzen u. a. github.com/ofg/hargassner-monitoring,
  github.com/lithium73fr/hargassner-ha, github.com/knirzinger/hargassner-ha. Getestet bisher nur mit Pelletkesseln.
- **App-Push** gibt es nur für Info, Störung und Offline – nicht für Nachlegen oder Ausbrand.

**Stand 21.10.26, 22:55:** Der Stückholzkessel erscheint in der Hargassner-App → Cloud-Weg ist möglich.
Abfrage vorbereitet in `cloud.html` (Zugangsdaten in `cloud_config.php`). Test steht aus, da Hargassner-Web
und App wegen Wartung offline waren.

### Mögliche Wege (Reihenfolge = Empfehlung)

1. **Weg 1 Puffer-Trend** (läuft bereits).
2. **Prüfen, ob die MidiHV in der Cloud ist:** Im Display unter System-Info nach dem Server-Status suchen
   („MQTT connected …“) und in der Hargassner-App nachsehen, ob der Kessel unter „Meine Anlagen“ erscheint.
   Falls ja → Werte über die Cloud-API abfragen (Kesselzustand, Rauchgas, Kesseltemperatur).
3. **Eigener Fühler am Stückholzkessel:** Anlegefühler am Kesselvorlauf (DS18B20, bis 125 °C, z. B. an
   einem Shelly-Modul mit Fühler-Add-on) oder am Rauchrohr (Rauchgas wird zu heiß für DS18B20 → Thermoelement
   Typ K mit passendem Messmodul). Lokal per HTTP abfragbar. Temperatur fällt unter einen Schwellwert → Ausbrand.
   Unabhängig von Hargassner, günstig und sehr direkt.
4. **Hersteller/Heizungsbauer fragen:** Schnittstelle, Störmelde- bzw. Relaisausgang oder Modbus-Option
   für die Smart-Steuerung.
5. Nicht empfohlen: MQTT-Umleitung, VNC-Dauerbetrieb, SSH-Zugriff ohne Hersteller.

## Auswertung 22.10.–03.11.26 (Daten vom Raspberry Pi)

Datengrundlage: Verlauf (eine Zeile pro Minute) und Meldungsprotokoll vom Pi, Lücke 22.10. 22:30 – 23.10. 11:00 (Wartung).
Echte Holzfeuer laut Nutzer und Cloud: **23.10. ab 11:02** und **03.11. ab 12:57** (22.10. 15:43 nur kurze Zündung).

**1. Puffer-Erkennung schlägt fast immer beim Pelletkessel an.**
21 Meldungen „Scheitholzkessel heizt“, nur 2 echt. 17 kamen direkt nach einem Pelletbrand: Der Pelletkessel geht von
Zustand 7 (Leistungsbrand) in **9 „Halt für Entaschung“, 10 „Entaschung“, 12 „Reinigung“**. Diese Zustände zählten als
„Pellet aus“, der Puffer lädt aber noch mit 10–25 K/h weiter → falsches „Holz brennt“ und ca. 20–30 min später
ein falsches **„Jetzt nachlegen“ (10 Push-Meldungen ohne Holzfeuer)**. Zwei weitere Fehlerkennungen ohne Pelletkessel
(22.10. 05:37 Restwärme, 01.11. 13:01 vermutlich Sonne).

**2. Die Cloud erkennt beide Holzfeuer sauber und früh.**

| | 23.10. | 03.11. |
|---|---|---|
| Zündung (Cloud) | 11:02 | 12:57 |
| Leistungsbrand (Cloud) | 11:07 (Rauchgas 221 °C) | 13:07 (201 °C) |
| Puffer-Erkennung „heizt“ | 11:49 | 13:50 |
| Puffer-Erkennung „ausgebrannt“ | 15:44 | 16:15 (Push „nachlegen“) |
| Ausbrand `STATE_BURN_OUT` (Cloud) | 16:37 (Rauchgas 124 °C) | 17:57 (166 °C) |
| Restwärme `STATE_RESIDUAL_HEATING` | 17:12 | 18:37 |
| Aus | 23:12 | – |
| Puffer unten beim Ausbrand | 81,8 °C | 79,4 °C |

- Die Puffer-Erkennung ist beim Start ca. 40 min zu spät (der Holzkessel heizt erst sich selbst auf)
  und beim Ausbrand **1–1¾ h zu früh**: Ist der Puffer bei ca. 80 °C voll, steigt er nicht mehr, obwohl das Feuer noch brennt.
- Neue Cloud-Zustände: `STATE_BURN_OUT` (Ausbrand) und `STATE_RESIDUAL_HEATING` (Restwärme), Ablauf
  Aus → Zündung → Leistungsbrand → Ausbrand → Restwärme → Aus. Leistungsbrand dauerte 4 h 50 min bis 5 h 30 min.
- Beim Ausbrand war der Puffer beide Male praktisch voll (unten ≈ 80 °C).

**3. Pelletkessel:** 22 Starts in 12 Tagen, fast immer morgens 06–08 Uhr und abends 16–20 Uhr. Er startet immer, wenn
**Puffer oben auf ca. 55 °C** fällt; der Füllgrad liegt dann bei 62–73 %. Zeit von Füllgrad 80 % bis Pelletstart:
0–108 min, Mittel ca. 60 min (morgens oft kurz, weil die Heizkreise um 06:15/06:30 anlaufen). Ein Holzfeuer braucht
ca. 40 min, bis Wärme im Puffer ankommt.

**4. „Puffer kühlt ab“ (45 °C)** kam dreimal direkt nach einem Pelletstart (Puffer oben fällt kurz auf 44,x °C) → unnötig.

## Umgesetzt am 03.11.26 (neue Version)

- **Holzfeuer und Ausbrand kommen aus der Hargassner-Cloud.** Start bei Zündung/Leistungsbrand, „Jetzt nachlegen“ beim
  Verlassen des Leistungsbrands (in der Regel `STATE_BURN_OUT`), weiterhin nur bei Puffer unten < 80 °C und 07–22 Uhr.
  Eine abgebrochene Zündung (Zündung → Aus) meldet nichts.
- **Puffer-Erkennung nur noch als Ersatz**, wenn die Cloud länger als 15 min keine Daten liefert. Pelletkessel gilt nur in
  Zustand 0/1/8 als aus, danach zusätzlich **30 min Nachlauf** (`holz.pellet_nachlauf_min`).
- **Neu: „Bald startet der Pelletkessel – jetzt Holz anheizen“** bei Füllgrad < **82 %**, wenn weder Holz noch Pellet heizt,
  07–22 Uhr (nachts aufgelaufen → Meldung um 07:00), erneut erst nach Wiederaufladen auf ≥ 95 %
  (`pellet_warnung`, Standardwerte im Programm; der alte Abschnitt `puffer_niedrig` wird nicht mehr verwendet).
- „Puffer kühlt ab“ in den ersten 30 min nach einem Pelletstart unterdrückt.
- Nachgespielt mit den Daten 21.10.–03.11.: Holz-Meldungen genau zu den echten Feuern, **ein** Nachlegen-Push (03.11. 17:57)
  statt zehn falscher. Die Anheiz-Hinweise wären ca. 1–2 × pro Tag gekommen (meist 15:30–18:00 und um 07:00),
  nachmittags 40 min bis gut 2 h vor dem Pelletstart.
- Ersatzweg ohne Cloud: keine Pellet-Fehlerkennungen mehr; bekannte Grenze: bei vollem Puffer meldet er den Ausbrand
  bis zu 1¾ h zu früh.

## Abend-Hinweis „Heute Abend noch Holz anheizen?“ (Auswertung 23.10.–04.11.26, umgesetzt in 1.3)

Der Pelletkessel startet fast jeden Morgen zwischen 03:49 und 08:31 – meist kurz nachdem die Heizkreise um 06:15/06:30
anlaufen, sobald Puffer oben auf ca. 55 °C fällt. Wichtig ist der **Wärmevorrat (Puffer-Mittel) am Morgen**:

| Puffer-Mittel um 06:10 | Pelletstart |
|---|---|
| unter 57 °C (8 Nächte) | 03:49–07:34 |
| über 63 °C (2 Nächte) | 08:10 / 08:31 |

Ein Holzfeuer am Nachmittag reicht nicht über die Nacht: Am 03.11. war der Puffer um 18 Uhr voll (ca. 80 °C, gut durchmischt),
die Heizkreise zogen bis 22 Uhr ca. 5 K/h ab, nachts kühlte er mit ca. 1,1 K/h ab → Pelletstart 04.11. um **05:29**.
Nachts (Heizkreise aus) kühlt das Puffer-Mittel um 0,1–1,1 K/h ab, bis 06:10 insgesamt um 1,0–1,7 K/h.

**Regel:** Um 21:00 Prognose Puffer-Mittel um 06:15 = Mittel jetzt − 1,5 K/h × Stunden. Liegt sie unter 60 °C und heizt
weder Holz noch der Pelletkessel (lädt er gerade, startet er morgens meist später) → Push.
Nachgespielt: Hinweis an 6 Abenden, jedes Mal startete der Pelletkessel am Morgen früh (03:49–07:11).
Kein Hinweis an 6 Abenden mit Start 06:53–08:31; verpasst wurden damit 01.11. (06:53) und 25.10. (07:08),
beide Male lud der Pelletkessel um 21 Uhr noch. Einstellbar unter `abend` (Standardwerte im Programm).

## Online-Anzeige (seit 1.3)

Ordner `online/` für einen Webserver (PHP, ohne Datenbank), Anleitung in `online/LIESMICH.md`. Der Raspi schickt alle 3 min
die Dashboard-Daten (ca. 25 kB komprimiert, 48 h Verlauf) per HTTPS mit geheimem Schlüssel an `empfang.php`. Im Router
muss nichts geöffnet werden. Die Seite ist reine Anzeige. Optional meldet `waechter.php` per ntfy, wenn der Raspi schweigt.

## Pelletverbrauch (04.11.26, seit 1.4)

Pos. 44 (Verbrauchszähler) und Pos. 43 (Lagerstand) zählen in **ganzen kg**. Brennzeit 04.11. 05:29–12:16 (inkl. Entaschung
10:42–11:01): Zähler 14490 → 14503 = **13 kg**, Lagerstand 3567 → 3555 kg. Bei 30 % Leistung ca. 1 kg alle 19 min.
Pos. 20 = Kesselleistung % (100 % beim Anfahren, dann 30–98 %). Genauigkeit je Brennzeit ±1 kg.

## Füllgrad nach einem Holzfeuer (07.11.26, seit 1.6)

Nach einem Holzfeuer ist der Puffer durchmischt (oben = mitte = unten). Der Pelletkessel startet trotzdem bei Puffer oben 55 °C,
der **Füllgrad steht dann aber noch bei 91 %** (04.11. 05:29 und 07.11. 12:52) – der Füllgrad ist kein Maß für den Wärmevorrat,
eher für die Schichtung. Bei geschichtetem Puffer warnt der Füllgrad früh (meist 1–2 h vorher), Puffer oben fällt dagegen erst
in den letzten Minuten. Bei durchmischtem Puffer ist es umgekehrt: Puffer oben ≤ 57 °C lag am 04.11. 2 h vor dem Start.
Deshalb warnt die Anheiz-Meldung seit 1.6 bei **Füllgrad < 82 % oder Puffer oben ≤ 57 °C**.

**07.11.26:** Holzfeuer ca. 00:00–05:07 (Puffer bis 89 °C), danach meldete die Cloud dreimal kurz wieder Leistungsbrand
(05:17–05:32, 05:52–06:02 …) – Glut flammt auf. Pelletstart 12:52 bei Puffer oben 55 °C und Füllgrad 91 %; die neue Regel
(Puffer oben ≤ 57 °C) hätte um 10:41 gewarnt.

## Neue IP des Pelletkessels (18.11.26)

Am 18.11. ab 08:50 keine Telnet-Daten mehr („Connection timed out“, alle Ports zu), die Hargassner-Cloud zeigte die Anlage
weiter online (sie läuft über das Gateway .30). Ursache: Die FritzBox hatte dem Pelletkessel (HSV1, MAC 00:24:BD:02:E4:41)
die neue Adresse **192.168.178.123** gegeben. `config.php` angepasst. Abhilfe auf Dauer: in der FritzBox „Diesem Netzwerkgerät
immer die gleiche IPv4-Adresse zuweisen“ für HSV1, hargasmart, Gateway und Raspberry Pi.
Seit 1.9 meldet der Monitor Holzfeuer/Ausbrand bei solchen Ausfällen trotzdem über die Cloud.
