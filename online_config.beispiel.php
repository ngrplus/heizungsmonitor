<?php
/**
 * Online-Anzeige: Der Raspi schickt die Dashboard-Daten regelmäßig an einen Webserver (Ordner online/).
 * 1. Ordner online/ auf den Webserver hochladen (Anleitung in online/LIESMICH.md).
 * 2. Hier bei 'url' die Adresse von empfang.php eintragen und 'enabled' auf true setzen.
 * 3. sudo systemctl restart heizung-monitor
 * Der Schlüssel muss mit 'token' in online/config.php übereinstimmen (beide schon passend vorbelegt).
 */
return [
    'enabled'     => false,
    'url'         => 'https://DEINE-DOMAIN.de/heizung/empfang.php',
    'token'       => '',
    'intervall_s' => 180,   // alle 3 Minuten senden
    'stunden'     => 48,    // so viel Verlauf wird mitgeschickt (Auswahl im Dashboard: 6 / 24 / 48 h)
];
