<?php
/**
 * Einstellungen der Überwachung (Weg 1: Stückholz-Ausbrand indirekt über den Puffer).
 * Alle Schwellen sind Startwerte und müssen am echten Verlauf nachjustiert werden.
 */
return [
    // Gerät aus config.php, das gelesen wird (Pelletkessel mit Telnet)
    'device'   => 'hsv1',
    // Abfrageintervall des Dauerlaufs in Sekunden
    'interval' => 60,

    // Position der Werte in der pm-Zeile (bestätigt am 21.10.26, siehe ERKENNTNISSE.md)
    'fields' => [
        'ZK'   => 0,   // Kesselzustand Pelletkessel (1 = Aus, 7 = Leistungsbrand)
        'O2'   => 1,   // O2 im Rauchgas Pelletkessel (bei ausgeschaltetem Kessel unzuverlässig)
        'TK'   => 3,   // Kesseltemperatur Pelletkessel
        'TPO'  => 11,  // Puffer oben
        'TPM'  => 12,  // Puffer mitte
        'TPU'  => 13,  // Puffer unten
        'PFD'  => 14,  // Pufferfüllgrad %
        'TRG'  => 8,   // Rauchgastemperatur Pelletkessel
        'TAUS' => 56,  // Außentemperatur (durch Nachtverlauf bestätigt)
        'HK1_VL'  => 66, // Heizkreis 1 Vorlauf ist
        'HK1_VLS' => 67, // Heizkreis 1 Vorlauf soll (0 = Heizkreis aus)
        'HK2_VL'  => 72, // Heizkreis 2 Vorlauf ist
        'HK2_VLS' => 73, // Heizkreis 2 Vorlauf soll
        'WW_TAG'   => 118, // Frischwasserstation: Warmwasser heute (Liter, per Cloud bestätigt)
        'WW_PUMPE' => 117, // Frischwasserstation: Pumpe aktiv (vermutet)
    ],
    // Statuswörter (Hex), Pos. 131 = Statuswort 2 mit den Heizkreispumpen
    'hex_fields' => [
        'PUMPEN' => 131,
    ],

    // Verbrauchsausgleich: Laufende Heizung zieht Wärme aus dem Puffer. Der typische Abfall wird gelernt
    // (nur aus Zeiten ohne Holz-/Pelletfeuer) und vom Trend abgezogen -> "Netto-Trend".
    // Warmwasser (Frischwasserstation) ist im Datensatz nicht sichtbar und wird nicht ausgeglichen.
    'verbrauch' => [
        // VORERST AUS (22.10.): Die Solarthermie erwärmt den Puffer bei Sonne ebenfalls. Mit Ausgleich würde
        // Sonne + laufende Heizung als Holzfeuer erscheinen. Wieder einschalten, sobald Solar erkannt wird.
        'ausgleich'         => false,
        'lernen'            => true,  // Verbrauch wird trotzdem schon gelernt
        'heizung_pumpen_bits' => [0, 3, 6, 9, 12, 21], // HKP A, 1, 2, 3, 4, B im Statuswort 2
        'standard_k_pro_h'  => -4.0, // angenommen, bis genug gelernt ist (22.10. vormittags ca. -5 K/h gemessen)
        'lern_min'          => 120,  // Minuten Lerndaten, ab denen der gelernte Wert gilt
        'ema_min'           => 180,  // Mittelungszeit des gelernten Werts
        'nach_pellet_min'   => 60,   // nicht lernen, solange/kurz nachdem der Pelletkessel heizte
        'nach_ausbrand_min' => 120,  // nicht lernen während der Restwärme nach einem Holzabbrand
    ],

    // Sonnenausgleich: Die Solarthermie lädt den Puffer bei Sonne. Einstrahlung von Open-Meteo (Kempten, PLZ 87439).
    // 22.10. (klar, Heizung aus): ab ca. 200 W/m² steigt der Puffer, bei 450-500 W/m² um 3-4 K/h.
    'sonne' => [
        'enabled'   => true,
        'ausgleich' => true,
        'lat'       => 47.74,
        'lon'       => 10.31,
        'ab_w_m2'   => 150,   // darunter liefert die Solarthermie praktisch nichts
        'k_pro_h_je_100w' => 1.2, // je 100 W/m² über der Schwelle ca. so viel K/h (bewusst eher reichlich geschätzt)
        'max_alter_min'   => 60,  // ältere Wetterdaten gelten als unbekannt
        'start_ohne_sonnendaten' => 7.0, // Startschwelle, falls Open-Meteo nicht erreichbar ist
    ],

    // Scheitholzkessel aus der Hargassner-Cloud (Zugangsdaten in cloud_config.php). Die Cloud aktualisiert alle 5 min.
    // Vorerst nur Aufzeichnung (Zustand, Rauchgas, Kessel), damit wir einen Abbrand damit vergleichen können.
    'cloud' => [
        'enabled'       => true,
        'scheitholz_id' => 41049,
        'intervall_s'   => 300,
        'max_alter_min' => 15,   // ältere Cloud-Werte gelten als unbekannt
    ],

    // Stückholz-Erkennung über die mittlere Puffertemperatur (Mittel aus oben/mitte/unten)
    'holz' => [
        // Kesselzustände (Pos. 0), in denen der Pelletkessel heizt bzw. startet.
        // Alle anderen Zustände (z. B. 1 = Aus, 8 = Bereitschaft) gelten als "Pelletkessel aus".
        'pellet_an_zustaende' => [2, 3, 4, 5, 6, 7, 15, 17],
        'pellet_aus_ab_o2' => 20,   // nur Ersatz, falls der Kesselzustand fehlt
        'fenster_min'      => 20,   // Zeitfenster für die Trendberechnung
        'start_k_pro_h'    => 4.0,  // Netto-Anstieg (nach Abzug von Heizung und Sonne) -> Holz brennt
                                    // (22.10.: 2.0 löste durch Restwärme nach dem Ausbrand einen Fehlstart aus;
                                    //  echter Start stieg mit ~9 K/h, Restwärme-Pulse nur bis ~3.5 K/h)
        'start_bestaetigen_min' => 10, // so lange muss der Anstieg anhalten
        'ende_k_pro_h'     => 0.0,  // Anstieg fällt darunter -> Ausbrand-Kandidat
        'ende_bestaetigen_min'  => 10, // so lange muss das anhalten -> Ausbrand melden
                                       // (22.10.: kleinster Trend während des Abbrands 1.9 K/h -> 10 min sicher genug)
        'min_brenndauer_min'    => 30, // kürzere "Abbrände" werden ignoriert
        // Nach dem Ausbrand kommen ca. 1,5 h Restwärme-Stöße (22.10.: kurz bis 4,8 K/h) -> so lange höhere Startschwelle
        'restwaerme_sperre_min' => 90,
        'restwaerme_faktor'     => 2.0,
    ],

    // Tageszeit, in der Push-Nachrichten fürs Nachlegen/Anheizen verschickt werden
    'zeitfenster' => ['von' => '07:00', 'bis' => '22:00'],

    // "Jetzt nachlegen" beim Ausbrand nur, wenn der Puffer noch Platz hat
    'nachlegen' => [
        'puffer_unten_max' => 80,   // Puffer unten muss kälter sein (°C) – Schätzwert
    ],

    // "Holz anheizen", bevor der Pelletkessel anspringt
    'puffer_niedrig' => [
        'fuellgrad_unter' => 30,    // Meldung, wenn der Füllgrad darunter fällt (%) – Schätzwert,
                                    // Erfahrung über die Protokoll-Einträge "Pelletkessel startet"
        'zurueck_ab'      => 45,    // erst nach Wiederaufladen über diesen Wert erneut melden
    ],

    // Welche Ereignisse eine Push-Nachricht auslösen (alle anderen nur ins Protokoll)
    'push' => [
        'holz_ende'      => true,   // Ausbrand mit Platz im Puffer, im Zeitfenster
        'puffer_niedrig' => true,   // Füllgrad niedrig, im Zeitfenster
        'verbindung_weg' => true,   // keine Daten von der Heizung (rund um die Uhr)
        'holz_start'     => false,
        'pellet_start'   => false,
        'puffer_kalt'    => false,
        'puffer_ok'      => false,
        'verbindung_ok'  => false,
        'hv_zustand'     => false,  // Zustandswechsel Scheitholzkessel laut Cloud (nur Protokoll)
    ],

    // Puffer-Warnung (ältere Regel, nur Protokoll)
    'puffer' => [
        'fuehler'     => 'TPO', // welcher Fühler überwacht wird
        'kalt_unter'  => 45,    // Warnung, wenn darunter
        'kalt_zurueck'=> 50,    // Warnung erst wieder scharf, wenn darüber
    ],

    // MQTT-Broker im Heimnetz (HomeServer / Raspberry Pi), z. B. für PV-Daten des Deye-Wechselrichters
    'mqtt' => [
        'host' => '192.168.178.66',
        'port' => 1883,
        'user' => '',   // falls der Broker eine Anmeldung verlangt
        'pass' => '',
    ],

    // Jede gelesene Rohzeile in data/raw_<datum>.txt ablegen (für spätere Auswertungen), so viele Tage aufheben
    'rohdaten_tage' => 30,

    // Warnung, wenn so lange keine Daten kommen
    'verbindung_alarm_min' => 10,

    // Zusätzliche Ruhezeit für ALLE Push-Nachrichten (auch Verbindungsfehler); null = aus
    'ruhezeit' => null, // z. B. ['von' => '22:30', 'bis' => '06:00']

    // Benachrichtigungen. Das Protokoll (data/events.jsonl) wird immer geschrieben.
    'notify' => [
        // ntfy: kostenlose Push-App (Android/iOS), https://ntfy.sh
        // Wer Nachrichten bekommen soll, abonniert in der ntfy-App genau dieses Thema.
        // Auf ntfy.sh ist ein Thema öffentlich – wer den Namen kennt, kann mitlesen. Daher zufällig und geheim halten.
        // Später auf dem Raspberry Pi kann ein eigener ntfy-Server laufen (server-URL hier ändern, ggf. token setzen).
        'ntfy' => [
            'enabled' => true,
            'server'  => 'https://ntfy.sh',
            'topic'   => 'heizung-DEIN-GEHEIMES-THEMA',
            'token'   => '', // nur für eigenen Server mit Zugangsschutz
        ],
        // Allgemeiner Webhook, z. B. CallMeBot für Signal/WhatsApp.
        // {text} wird URL-codiert durch die Meldung ersetzt.
        'webhook' => [
            'enabled' => false,
            'url'     => '',
        ],
    ],
];
