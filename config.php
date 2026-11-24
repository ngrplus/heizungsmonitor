<?php
/**
 * Konfiguration der Hargassner-Testanwendung.
 *
 * Nur die hier eingetragenen Geräte können über die Web-UI abgefragt werden
 * (die API akzeptiert keine frei eingegebenen IP-Adressen).
 *
 * mapping: Name einer Datei in /mappings (ohne .json). Gespeicherte
 *          Bezeichnungen aus der UI landen in /data/labels_<id>.json und
 *          überschreiben das Mapping.
 */
return [
    'devices' => [
        'hsv1' => [
            'name'    => 'Pelletkessel Rennergy Mini.2 20',
            'host'    => '192.168.178.123',
            'port'    => 23,
            'mapping' => 'rennergy_mini2_v14p',
        ],
        'hargasmart' => [
            'name'    => 'Stückholzkessel MidiHV-17 (kein Telnet)',
            'host'    => '192.168.178.77',
            'port'    => 23,
            'mapping' => 'generic',
        ],
        'gateway' => [
            'name'    => 'Internet-Gateway HARGA.IGW (nur Port-Test, kein Telnet erwartet)',
            'host'    => '192.168.178.30',
            'port'    => 23,
            'mapping' => 'generic',
        ],
        // Simulator zum Testen ohne Heizung:  php mock_server.php
        'simulator' => [
            'name'    => 'Simulator (localhost:2323)',
            'host'    => '127.0.0.1',
            'port'    => 2323,
            'mapping' => 'rennergy_mini2_v14p',
        ],
    ],

    // Verbindungs-Timeout in Sekunden
    'connect_timeout' => 3,
    // Wie lange pro Abfrage maximal Daten gelesen werden (Sekunden).
    // Die Steuerung sendet ca. alle 0,5 s einen Datensatz.
    'read_seconds'    => 2.5,
    // Ports, die beim Port-Test zusätzlich geprüft werden
    'probe_ports'     => [22, 23, 80, 443, 502, 1883, 5900, 8883],
];
