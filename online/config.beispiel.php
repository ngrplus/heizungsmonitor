<?php
// Einstellungen der Online-Anzeige (Webserver). Nur der Raspi mit diesem Schlüssel darf Daten abliefern.
return [
    // muss mit 'token' in online_config.php auf dem Raspi übereinstimmen
    'token' => '',

    // Wächter: meldet per ntfy, wenn sich der Raspi nicht mehr meldet (waechter.php regelmäßig aufrufen, s. LIESMICH.md)
    'waechter' => [
        'enabled'    => false,
        'schluessel' => '',       // muss beim Aufruf als ?schluessel=… mitgegeben werden
        'nach_min'   => 20,            // so lange ohne Daten -> Meldung
        'ntfy_server'=> 'https://ntfy.sh',
        'ntfy_topic' => '',            // dasselbe Thema wie auf dem Raspi eintragen
    ],
];
