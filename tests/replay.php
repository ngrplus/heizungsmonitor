<?php
/**
 * Spielt aufgezeichnete Verläufe (data/history_*.csv) mit der aktuellen oder einer
 * abgewandelten Konfiguration erneut durch die Erkennung.
 *
 *   php tests/replay.php data/history_2026-10-21.csv data/history_2026-10-22.csv
 *   php tests/replay.php --set holz.start_k_pro_h=4 data/history_*.csv
 *   php tests/replay.php --ohne-cloud data/history_*.csv     nur Puffer-Erkennung (Ersatzweg) prüfen
 */
require __DIR__ . '/../lib/Hargassner.php';
require __DIR__ . '/../lib/Monitor.php';
$cfg = require (is_file(__DIR__ . '/../monitor_config.php') ? __DIR__ . '/../monitor_config.php' : __DIR__ . '/../monitor_config.beispiel.php');

$files = [];
$ohneCloud = false;
for ($i = 1; $i < count($argv); $i++) {
    if ($argv[$i] === '--ohne-cloud') { $ohneCloud = true; continue; }
    if ($argv[$i] === '--set') {
        [$path, $val] = explode('=', $argv[++$i], 2);
        $ref = &$cfg; foreach (explode('.', $path) as $k) $ref = &$ref[$k];
        $ref = is_numeric($val) ? $val + 0 : $val; unset($ref);
    } else $files[] = $argv[$i];
}
$rows = [];
foreach ($files as $f) {
    $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $head = explode(';', array_shift($lines));
    foreach ($lines as $l) { $r = array_combine($head, array_pad(explode(';', $l), count($head), '')); $rows[(int) $r['ts']] = $r; }
}
ksort($rows);
$dir = sys_get_temp_dir() . '/hg_replay_' . uniqid();
mkdir($dir);
require_once __DIR__ . '/../lib/Sonne.php';
// Sonnenwerte: aus der Spalte SONNE der Verläufe (fehlt sie, gilt die Sonne als unbekannt)
$hatSonne = array_filter($rows, fn($r) => ($r['SONNE'] ?? '') !== '');
$m = new Monitor($cfg, $dir, fn($e) => ['replay' => 'push']);
$pos = $cfg['fields'] + ['PVERBR' => 44, 'PLAGER' => 43, 'PLEIST' => 20];
foreach ($rows as $ts => $r) {
    $v = array_fill(0, 137, '0');
    foreach ($pos as $name => $i) if (isset($r[$name]) && $r[$name] !== '') $v[$i] = $r[$name];
    foreach ($cfg['hex_fields'] ?? [] as $name => $i) if (isset($r[$name]) && $r[$name] !== '') $v[$i] = $r[$name];
    if (($r['ZK'] ?? '') === '') $v[$pos['ZK']] = '1';
    $ghi = ($r['SONNE'] ?? '') !== '' ? (float) $r['SONNE'] : null;
    if (!$hatSonne && !empty($cfg['sonne']['enabled'])) $ghi = null;
    // Scheitholzkessel aus der Cloud (Spalte leer = Cloud hatte keine aktuellen Daten)
    $hv = (!$ohneCloud && ($r['HV_ZUSTAND'] ?? '') !== '')
        ? ['zustand' => $r['HV_ZUSTAND'], 'rauchgas' => (float) $r['HV_RAUCHGAS'], 'kessel' => (float) $r['HV_KESSEL'], 'ts' => $ts] : null;
    foreach ($m->tick(Hargassner::parse('pm ' . implode(' ', $v)), $ts, '', $ghi, $hv) as $e) {
        printf("%s  %-14s %-5s %s – %s%s\n", date('d.m. H:i', $e['ts']), $e['type'], $e['level'], $e['title'], $e['text'],
            isset($e['versand']['kein Push']) ? '  [kein Push: ' . $e['versand']['kein Push'] . ']' : '  [PUSH]');
    }
}
array_map('unlink', glob("$dir/*")); @rmdir($dir);
