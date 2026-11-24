<?php
/**
 * Kommandozeilen-Logger: schreibt Datensätze fortlaufend in data/log_<gerät>.csv
 * Basis für spätere Auswertungen / Benachrichtigungen.
 *
 *   C:\xampp\php\php.exe logger.php hsv1 60      (alle 60 s, Strg+C beendet)
 *   C:\xampp\php\php.exe logger.php hsv1 once    (ein Datensatz, z. B. für die Aufgabenplanung)
 */
require __DIR__ . '/lib/Hargassner.php';
$config = require __DIR__ . '/config.php';

$id = $argv[1] ?? '';
$every = $argv[2] ?? '60';
if (!isset($config['devices'][$id])) {
    fwrite(STDERR, "Aufruf: php logger.php <gerät> [sekunden|once]\nGeräte: " . implode(', ', array_keys($config['devices'])) . "\n");
    exit(1);
}
$dev = $config['devices'][$id];
if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
$file = __DIR__ . "/data/log_$id.csv";

do {
    $r = Hargassner::read($dev['host'], (int) $dev['port'], $config['connect_timeout'], $config['read_seconds']);
    $ts = date('Y-m-d H:i:s');
    if ($r['ok']) {
        $vals = array_map(fn($v) => str_replace('.', ',', (string) $v['v']), $r['values']);
        if (!is_file($file)) {
            file_put_contents($file, "\xEF\xBB\xBFZeit;" . implode(';', array_map(fn($v) => 'W' . $v['i'], $r['values'])) . "\r\n");
        }
        file_put_contents($file, $ts . ';' . implode(';', $vals) . "\r\n", FILE_APPEND);
        echo "$ts  ok  " . count($vals) . " Werte\n";
    } else {
        echo "$ts  FEHLER  {$r['error']}\n";
    }
    if ($every === 'once') break;
    sleep(max(5, (int) $every));
} while (true);
