<?php
/**
 * Spielt einen künstlichen Tagesverlauf durch die Überwachungslogik:
 * Puffer kühlt ab -> Holz wird angeheizt -> Ausbrand -> Puffer kühlt bis zur Warnschwelle ab
 * -> Pelletkessel startet -> kurzer Verbindungsausfall.
 *
 *   php tests/simulate.php [zielordner]      (Standard: tests/sim_data, wird geleert)
 */
require __DIR__ . '/../lib/Hargassner.php';
require __DIR__ . '/../lib/Monitor.php';
$cfg = require (is_file(__DIR__ . '/../monitor_config.php') ? __DIR__ . '/../monitor_config.php' : __DIR__ . '/../monitor_config.beispiel.php');

$dir = $argv[1] ?? __DIR__ . '/sim_data';
if (is_dir($dir)) array_map('unlink', glob("$dir/*"));

$m = new Monitor($cfg, $dir);
$start = (int) (floor(time() / 60) * 60) - 20 * 3600;
$mean = 68.0; $o2 = 21.0;
mt_srand(7);

for ($min = 0; $min < 20 * 60; $min++) {
    $ts = $start + $min * 60;
    $h = $min / 60;
    // Szenario: Steigung der mittleren Puffertemperatur in K/h
    if ($h < 2)        { $rate = -1.2; $o2 = 21; }          // Abkühlen
    elseif ($h < 5.5)  { $rate = 7.0;  $o2 = 21; }          // Holz brennt
    elseif ($h < 6.5)  { $rate = 7.0 - ($h - 5.5) * 9; $o2 = 21; } // Abbrand klingt aus
    elseif (empty($pelletLaeuft) && $mean > 41 && $h < 17) { $rate = -6.0; $o2 = 21; }    // Verbrauch
    else               { $rate = 4.0;  $o2 = 7.5; $pelletLaeuft = true; } // Pelletkessel heizt
    $mean += $rate / 60 + (mt_rand(-10, 10) / 100);          // ±0.1 K Rauschen
    if ($h >= 9 && $h < 9.25) { foreach ($m->tick(null, $ts, "Verbindung abgelehnt") as $e) printf("%s  [%s] %s – %s\n", date("H:i", $e["ts"]), $e["level"], $e["title"], $e["text"]); continue; } // 15 min Ausfall

    $tpo = $mean + 4; $tpm = $mean + 1; $tpu = $mean - 5;
    $pfd = max(0, min(100, round(($mean - 35) * 3)));
    // Wie beim echten Kessel: im Aus-Zustand zeigt O2 einen unzuverlässigen Wert (1.2), maßgeblich ist der Zustand
    $zk = $o2 < 20 ? 7 : 1;
    $line = sprintf('pm %d %.1f 7.4 %.1f 0 60 36 6 120 0 0 %.1f %.1f %.1f %d 35', $zk, $zk === 7 ? $o2 : 1.2, $mean + 2, $tpo, $tpm, $tpu, $pfd);
    foreach ($m->tick(Hargassner::parse($line), $ts, '', 0.0) as $e) {
        printf("%s  [%s] %s – %s\n", date('H:i', $e['ts']), $e['level'], $e['title'], $e['text']);
    }
}
echo "Zustand am Ende: holz=" . $m->state()['holz'] . ", puffer_kalt=" . var_export($m->state()['puffer_kalt'], true) . "\n";
