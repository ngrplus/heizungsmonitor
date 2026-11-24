<?php
/**
 * Simulator einer Hargassner-Steuerung zum Testen ohne Heizung.
 * Start (Windows):  C:\xampp\php\php.exe mock_server.php
 * Lauscht auf 127.0.0.1:2323 und sendet alle 500 ms eine pm-Zeile.
 */
$addr = $argv[1] ?? 'tcp://127.0.0.1:2323';
$srv = stream_socket_server($addr, $errno, $errstr);
if (!$srv) die("Kann $addr nicht öffnen: $errstr\n");
stream_set_blocking($srv, false);
echo "Simulator läuft auf $addr  (Strg+C beendet)\n";

// Echter Datensatz des Rennergy Mini.2 20 vom 21.10.26 21:49
$base = '1 21 7.4 71.2 0 69.3 36 5 82.9 24 25 70.8 70.0 65.1 100 35 0 2 0 71 0 0 30 100 30 30 0 96.1 89 3 0 35 0 2 12 1 0 0 0 100 0 0 6198 1733 14319 8.00 13.07 612 -7.0 24089 140.0 120.6 42 -20.0 -20.0 0.0 7.3 8.8 0 0 -20.0 0 20.0 20.0 0 1 36.1 0 20.0 20.5 4 1 34.6 35 20.0 19.0 1 1 -20.0 0 20.0 20.0 0 1 -20.0 0 140.0 0 -20.0 0 0.0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 70.4 70.8 45 45.3 4.0 0.0 29.1 -9.765 0.252 11.8 1 129.6 102327 0 0 258 0 3 132.5 132.5 0.0 0.00 41E 1001 40 0 2007 0 0 0';
$tpl = explode(' ', $base);
$clients = [];
$t = 0;

while (true) {
    while ($c = @stream_socket_accept($srv, 0)) {
        stream_set_blocking($c, false);
        fwrite($c, "\xFF\xFB\x01\xFF\xFB\x03"); // IAC WILL ECHO, IAC WILL SGA – wie viele Telnet-Server
        $clients[(int) $c] = $c;
        echo date('H:i:s') . " Client verbunden\n";
    }
    $t += 0.5;
    $v = $tpl;
    $burn = (int) ($t / 60) % 2 === 1;                           // jede Minute Wechsel Aus/Leistungsbrand
    $v[0] = $burn ? '7' : '1';                                   // Kesselzustand
    $v[1] = $burn ? number_format(7 + sin($t / 3), 1, '.', '') : '21'; // O2
    $v[3] = number_format(70 + 2 * sin($t / 40), 1, '.', '');    // Kessel
    $v[8] = number_format($burn ? 150 : 80, 1, '.', '');         // Rauchgas
    $v[11] = number_format(70.8 - $t / 600, 1, '.', '');         // Puffer oben sinkt langsam
    $line = 'pm ' . implode(' ', $v) . "\r\n";
    foreach ($clients as $k => $c) {
        @fread($c, 1024); // Eingaben verwerfen
        if (@fwrite($c, $line) === false || feof($c)) { fclose($c); unset($clients[$k]); echo date('H:i:s') . " Client getrennt\n"; }
    }
    usleep(500000);
}
