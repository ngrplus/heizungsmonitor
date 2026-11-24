<?php
/**
 * Zugang zur Hargassner-Cloud (dieselben Daten wie in der App / web.hargassner.at).
 *
 * E-Mail und Passwort deines Hargassner-App-Kontos hier eintragen.
 * Diese Datei liegt nur lokal auf deinem Rechner – nicht weitergeben und
 * den XAMPP-Ordner nicht ins Internet freigeben.
 */
return [
    'email'    => '',
    'password' => '',

    'url'           => 'https://web.hargassner.at',
    // Feste Werte der Hargassner-Web-App (aus github.com/ofg/hargassner-monitoring)
    'client_id'     => '1',
    'client_secret' => 'aSYsAYj7Gsl5v9tPEDdS1hhXTUezgJk1NhOZ1bHR',

    // Token zwischenspeichern, um das Login-Limit der Cloud nicht zu reißen
    'cache_token'   => true,
];
