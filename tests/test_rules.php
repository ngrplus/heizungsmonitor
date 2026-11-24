<?php
/**
 * Prüft die Benachrichtigungsregeln mit festen Uhrzeiten.
 *   php tests/test_rules.php
 */
require __DIR__ . '/../lib/Hargassner.php';
require __DIR__ . '/../lib/Monitor.php';
$cfg = require (is_file(__DIR__ . '/../monitor_config.php') ? __DIR__ . '/../monitor_config.php' : __DIR__ . '/../monitor_config.beispiel.php');
// Die Tests prüfen die Logik mit fester Startschwelle, unabhängig vom aktuellen Einstellwert
$cfg['holz']['start_k_pro_h'] = 4.0;
// Die älteren Szenarien D/E/G rechnen mit einer Anheiz-Schwelle von 30 % (echter Wert: 82 %, eigenes Szenario R)
$cfgEcht = $cfg;
$cfg['pellet_warnung'] = ['fuellgrad_unter' => 30, 'zurueck_ab' => 45, 'puffer_oben_bis' => -99];

$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $fail;
    echo ($ok ? '  ok    ' : '  FEHLER ') . $name . ($detail ? "  ($detail)" : '') . "\n";
    if (!$ok) $fail++;
}

/**
 * Spielt einen Verlauf ab. $phasen: Liste [dauer_min, K/h, zustand_pellet, heizung, sonne, cloud_zustand] ab Startzeit.
 * cloud_zustand: Zustand des Scheitholzkessels laut Cloud (z. B. 'STATE_EFFICIENCY_FIRE'), null/fehlt = keine Cloud-Daten.
 * Liefert [ereignisse, gesendete Pushs].
 */
/** $tpuOffset: Puffer unten = Mittel + Wert; 'gemischt' = alle Schichten gleich, Füllgrad 91 % (nach Holzfeuer) */
function run(array $cfg, string $start, float $mean, array $phasen, float|string $tpuOffset = -5): array
{
    $dir = sys_get_temp_dir() . '/hg_rules_' . uniqid();
    $pushed = [];
    $m = new Monitor($cfg, $dir, function ($e) use (&$pushed) { $pushed[] = $e; return ['test' => 'ok']; });
    $ts = strtotime($start);
    $events = [];
    foreach ($phasen as $ph) {
        [$dauer, $rate, $zk] = $ph;
        $heizung = $ph[3] ?? false;                       // Heizkreis 2 läuft (Pumpe HKP2 = Bit 6)
        $sonne = array_key_exists(4, $ph) ? $ph[4] : 0.0; // Globalstrahlung W/m² (null = Open-Meteo nicht erreichbar)
        $hvZ = $ph[5] ?? null;
        for ($i = 0; $i < $dauer; $i++, $ts += 60) {
            $mean += $rate / 60;
            $pfd = max(0, min(100, round(($mean - 35) * 2.5)));
            $v = array_fill(0, 137, '0');
            [$v[0], $v[1], $v[3], $v[8]] = [$zk, '1.2', '70', '80'];
            [$v[11], $v[12], $v[13], $v[14]] = $tpuOffset === 'gemischt'
                ? [sprintf('%.1f', $mean), sprintf('%.1f', $mean), sprintf('%.1f', $mean), 91]
                : [sprintf('%.1f', $mean + 4), sprintf('%.1f', $mean + 1), sprintf('%.1f', $mean + $tpuOffset), $pfd];
            $v[73] = $heizung ? '35' : '0';
            $v[131] = $heizung ? '40' : '0';
            $line = 'pm ' . implode(' ', $v);
            $hv = $hvZ === null ? null : ['zustand' => $hvZ, 'rauchgas' => 150.0, 'kessel' => 80.0, 'ts' => $ts];
            $events = array_merge($events, $m->tick(Hargassner::parse($line), $ts, '', $sonne, $hv));
        }
    }
    array_map('unlink', glob("$dir/*")); rmdir($dir);
    return [$events, $pushed];
}
$types = fn($list) => array_column($list, 'type');

echo "A) Abbrand am Tag, Puffer unten kühl -> Nachlegen-Push\n";
[$ev, $push] = run($cfg, '2026-10-20 08:00', 55, [[60, -1, 1], [180, 6, 1], [120, -2, 1]]);
check('holz_start erkannt', in_array('holz_start', $types($ev)));
check('holz_ende als Push', in_array('holz_ende', $types($push)), implode(', ', $types($push)));
$e = array_values(array_filter($push, fn($x) => $x['type'] === 'holz_ende'))[0] ?? null;
check('Titel "jetzt nachlegen"', $e && str_contains($e['title'], 'nachlegen'), $e['title'] ?? '');
check('Ausbrand zwischen 12:00 und 12:45', $e && date('H:i', $e['ts']) >= '12:00' && date('H:i', $e['ts']) <= '12:45', $e ? date('H:i', $e['ts']) : '');

echo "B) Abbrand am Tag, Puffer unten schon >= 80 °C -> kein Push\n";
[$ev, $push] = run($cfg, '2026-10-20 08:00', 75, [[60, -1, 1], [180, 6, 1], [120, -2, 1]], +3);
check('holz_ende protokolliert', in_array('holz_ende', $types($ev)));
check('kein Push', !in_array('holz_ende', $types($push)));
$e = array_values(array_filter($ev, fn($x) => $x['type'] === 'holz_ende'))[0] ?? null;
check('Grund: kein Platz', $e && str_contains($e['versand']['kein Push'] ?? '', 'kein Platz'), $e['versand']['kein Push'] ?? '');

echo "C) Ausbrand um ca. 23 Uhr -> kein Push (Zeitfenster)\n";
[$ev, $push] = run($cfg, '2026-10-20 18:30', 55, [[60, -1, 1], [180, 6, 1], [120, -2, 1]]);
$e = array_values(array_filter($ev, fn($x) => $x['type'] === 'holz_ende'))[0] ?? null;
check('holz_ende protokolliert', (bool) $e, $e ? date('H:i', $e['ts']) : '');
check('kein Push wegen Zeitfenster', $e && str_contains($e['versand']['kein Push'] ?? '', 'außerhalb'), $e['versand']['kein Push'] ?? '');

echo "D) Füllgrad fällt nachts unter 30 % -> Push erst um 07:00\n";
[$ev, $push] = run($cfg, '2026-10-20 21:00', 52, [[720, -1.0, 1]]);
$e = array_values(array_filter($push, fn($x) => $x['type'] === 'puffer_niedrig'))[0] ?? null;
check('puffer_niedrig als Push', (bool) $e);
check('gesendet um 07:00', $e && date('H:i', $e['ts']) === '07:00', $e ? date('H:i', $e['ts']) : '');
check('nur einmal', count(array_filter($push, fn($x) => $x['type'] === 'puffer_niedrig')) === 1);

echo "E) Füllgrad fällt am Tag unter 30 % -> sofort Push, nach Laden erneut möglich\n";
[$ev, $push] = run($cfg, '2026-10-20 09:00', 50, [[300, -1.5, 1], [120, 6, 1], [60, -2, 1], [200, -4, 1]]);
$n = array_values(array_filter($push, fn($x) => $x['type'] === 'puffer_niedrig'));
check('zweimal gemeldet (vor und nach dem Holzfeuer)', count($n) === 2, implode(', ', array_map(fn($x) => date('H:i', $x['ts']), $n)));

echo "F) Pelletkessel startet -> Protokoll mit Füllgrad, kein Push; Holz nicht fälschlich erkannt\n";
[$ev, $push] = run($cfg, '2026-10-20 10:00', 45, [[60, -1, 1], [120, 5, 7], [60, -1, 1]]);
$e = array_values(array_filter($ev, fn($x) => $x['type'] === 'pellet_start'))[0] ?? null;
check('pellet_start protokolliert', (bool) $e, $e['text'] ?? '');
check('kein Push dafür', !in_array('pellet_start', $types($push)));
check('kein holz_start während Pelletbetrieb', !in_array('holz_start', $types($ev)));

echo "G) Pelletkessel springt nachts an -> um 07:00 keine Anheiz-Meldung\n";
[$ev, $push] = run($cfg, '2026-10-20 21:00', 52, [[360, -1.0, 1], [400, 3, 7]]);
check('kein puffer_niedrig-Push', !in_array('puffer_niedrig', $types($push)), implode(', ', $types($push)));

echo "H) Holzfeuer bei laufender Heizung (Puffer steigt nur schwach) -> trotzdem erkannt\n";
// 3 h Heizung ohne Feuer (-5 K/h, wird gelernt), dann Feuer: Holz +6,5, Heizung -5 => nur +1,5 K/h sichtbar
$cfgMit = $cfg; $cfgMit['verbrauch']['ausgleich'] = true;
[$ev, $push] = run($cfgMit, '2026-10-20 06:00', 70, [[180, -5, 1, true], [240, 1.5, 1, true], [120, -5, 1, true]]);
$s = array_values(array_filter($ev, fn($x) => $x['type'] === 'holz_start'))[0] ?? null;
$e = array_values(array_filter($ev, fn($x) => $x['type'] === 'holz_ende'))[0] ?? null;
check('holz_start erkannt', (bool) $s, $s ? date('H:i', $s['ts']) . ' ' . $s['text'] : '');
check('Ausbrand erkannt (Abbrand endet 13:00)', $e && date('H:i', $e['ts']) >= '13:00' && date('H:i', $e['ts']) <= '13:40', $e ? date('H:i', $e['ts']) : '');
$cfgOhne = $cfg; $cfgOhne['verbrauch']['ausgleich'] = false;
[$ev2] = run($cfgOhne, '2026-10-20 06:00', 70, [[180, -5, 1, true], [240, 1.5, 1, true], [120, -5, 1, true]]);
check('ohne Ausgleich wäre es übersehen worden', !in_array('holz_start', $types($ev2)));

echo "I) Heizung schaltet morgens ein, kein Feuer -> kein Fehlalarm\n";
[$ev, $push] = run($cfgMit, '2026-10-20 04:00', 80, [[120, -0.5, 1, false], [240, -5, 1, true], [60, -0.5, 1, false], [120, -5, 1, true]]);
check('kein holz_start', !in_array('holz_start', $types($ev)), implode(', ', $types($ev)));

echo "J) Restwärme-Stöße nach dem Ausbrand (bis 5 K/h, je 15 min) -> kein zweiter Start\n";
[$ev, $push] = run($cfg, '2026-10-20 08:00', 60, [[60, -1, 1], [240, 6, 1], [30, -6, 1], [15, 5, 1], [15, -4, 1], [15, 5, 1], [60, -2, 1]]);
check('genau ein holz_start', count(array_filter($ev, fn($x) => $x['type'] === 'holz_start')) === 1, implode(', ', array_map(fn($x) => $x['type'] . ' ' . date('H:i', $x['ts']), $ev)));

echo "K) Sonne erwärmt Puffer bei laufender Heizung (-5 -> +1,5 K/h) -> mit Ausgleich AUS kein Fehlalarm\n";
[$ev] = run($cfg, '2026-10-20 07:00', 70, [[180, -5, 1, true], [240, 1.5, 1, true], [180, -5, 1, true]]);
check('kein holz_start (Ausgleich aus)', !in_array('holz_start', $types($ev)), implode(', ', $types($ev)));
[$ev] = run($cfgMit, '2026-10-20 07:00', 70, [[180, -5, 1, true], [240, 1.5, 1, true], [180, -5, 1, true]]);
check('mit Ausgleich wäre es ein Fehlalarm (bekannte Grenze, bis Solar erkannt wird)', in_array('holz_start', $types($ev)));

echo "L) Sonniger Tag ohne Feuer: Sonne bringt +4 K/h -> kein Fehlalarm\n";
// Sonnenertrag laut Schätzung bei 500 W/m²: (500-150)/100*1,2 = 4,2 K/h
[$ev] = run($cfg, '2026-10-20 08:00', 60, [[120, -0.5, 1, false, 100.0], [240, 4.0, 1, false, 500.0], [120, -0.5, 1, false, 100.0]]);
check('kein holz_start', !in_array('holz_start', $types($ev)), implode(', ', $types($ev)));

echo "M) Holzfeuer an einem sonnigen Tag -> erkannt; Sonne verschwindet während des Abbrands -> kein falscher Ausbrand\n";
[$ev, $push] = run($cfg, '2026-10-20 10:00', 50, [[60, 3.5, 1, false, 450.0], [120, 12, 1, false, 450.0], [120, 8.5, 1, false, 150.0], [90, -2, 1, false, 100.0]]);
$s = array_values(array_filter($ev, fn($x) => $x['type'] === 'holz_start'))[0] ?? null;
$e = array_values(array_filter($ev, fn($x) => $x['type'] === 'holz_ende'));
check('holz_start erkannt', (bool) $s, $s ? date('H:i', $s['ts']) . ' ' . $s['text'] : '');
check('genau ein Ausbrand, erst nach 15:00', count($e) === 1 && date('H:i', $e[0]['ts']) >= '15:00', implode(', ', array_map(fn($x) => date('H:i', $x['ts']), $e)));

echo "N) Open-Meteo nicht erreichbar -> vorsichtige Schwelle (7 K/h): Sonne +5 K/h ohne Daten ist kein Feuer\n";
[$ev] = run($cfg, '2026-10-20 10:00', 60, [[60, -0.5, 1, false, null], [180, 5.0, 1, false, null]]);
check('kein holz_start', !in_array('holz_start', $types($ev)), implode(', ', $types($ev)));
[$ev] = run($cfg, '2026-10-20 22:00', 60, [[60, -0.5, 1, false, null], [120, 9.0, 1, false, null], [60, -3, 1, false, null]]);
check('echtes Feuer (9 K/h) wird trotzdem erkannt', in_array('holz_start', $types($ev)));

echo "O) Pelletkessel nach dem Brand in Entaschung/Reinigung (9/10/12), Puffer lädt weiter -> kein Holzfeuer (Fehler bis 03.11.)\n";
[$ev, $push] = run($cfg, '2026-10-27 08:30', 52, [[90, 12, 7], [20, 15, 9], [10, 8, 10], [5, 4, 12], [120, -1, 1]]);
check('kein holz_start', !in_array('holz_start', $types($ev)), implode(', ', $types($ev)));
check('keine Nachlegen-Meldung', !in_array('holz_ende', $types($push)));

echo "P) Cloud: Zündung -> Leistungsbrand -> Ausbrand am Nachmittag, Puffer unten < 80 °C -> Nachlegen-Push beim Ausbrand\n";
$F = 'STATE_EFFICIENCY_FIRE';
[$ev, $push] = run($cfg, '2026-11-03 12:45', 66, [[10, -3, 1, false, 0.0, 'STATE_OFF'], [10, -3, 1, false, 0.0, 'STATE_IGNITION'],
    [30, -1, 1, false, 0.0, $F], [150, 6, 1, false, 0.0, $F], [120, 0, 1, false, 0.0, $F], [40, 0.5, 1, false, 0.0, 'STATE_BURN_OUT'],
    [120, -5, 1, false, 0.0, 'STATE_RESIDUAL_HEATING']]);
$s = array_values(array_filter($ev, fn($x) => $x['type'] === 'holz_start'));
$e = array_values(array_filter($push, fn($x) => $x['type'] === 'holz_ende'));
check('holz_start bei Zündung (12:55)', count($s) === 1 && date('H:i', $s[0]['ts']) === '12:55', implode(', ', array_map(fn($x) => date('H:i', $x['ts']), $s)));
check('genau ein Nachlegen-Push, beim Ausbrand (18:05)', count($e) === 1 && date('H:i', $e[0]['ts']) === '18:05' && str_contains($e[0]['title'], 'nachlegen'),
    implode(', ', array_map(fn($x) => date('H:i', $x['ts']) . ' ' . $x['title'], $e)));
check('kein Fehl-Ausbrand, obwohl der volle Puffer nicht mehr steigt', count(array_filter($ev, fn($x) => $x['type'] === 'holz_ende')) === 1);

echo "Q) Cloud: Zündung bricht nach 15 min ab -> keine Nachlegen-Meldung\n";
[$ev, $push] = run($cfg, '2026-10-22 15:30', 70, [[10, -1, 1, false, 0.0, 'STATE_OFF'], [15, -1, 1, false, 0.0, 'STATE_IGNITION'], [60, -1, 1, false, 0.0, 'STATE_OFF']]);
check('kein holz_ende', !in_array('holz_ende', $types($ev)), implode(', ', $types($ev)));

echo "Q2) Cloud meldet Aus, Puffer steigt durch Pellet-Nachlauf -> kein Holzfeuer\n";
[$ev] = run($cfg, '2026-10-29 20:00', 52, [[90, 12, 7, false, 0.0, 'STATE_OFF'], [30, 14, 9, false, 0.0, 'STATE_OFF'], [60, -0.5, 1, false, 0.0, 'STATE_OFF']]);
check('kein holz_start', !in_array('holz_start', $types($ev)), implode(', ', $types($ev)));

echo "R) Füllgrad fällt am Nachmittag unter 82 % -> Anheiz-Hinweis (echte Einstellung), nur einmal\n";
[$ev, $push] = run($cfgEcht, '2026-10-24 14:00', 70, [[300, -3, 1]]);
$n = array_values(array_filter($push, fn($x) => $x['type'] === 'puffer_niedrig'));
check('genau ein Push', count($n) === 1, implode(', ', array_map(fn($x) => date('H:i', $x['ts']) . ' ' . $x['text'], $n)));
check('Füllgrad im Text unter 82 %', $n && preg_match('/Füllgrad (\d+)/', $n[0]['text'], $mm) && (int) $mm[1] < 82);
echo "R2) ... aber nicht, solange der Pelletkessel läuft oder nachlädt\n";
[$ev, $push] = run($cfgEcht, '2026-10-24 14:00', 60, [[60, 5, 7], [20, 3, 9], [15, -3, 1]]);
check('kein Push während Pellet/Nachlauf', !in_array('puffer_niedrig', $types($push)), implode(', ', $types($push)));

echo "S) Puffer oben fällt direkt nach einem Pelletstart kurz unter 45 °C -> kein 'Puffer kühlt ab'\n";
$cfgK = $cfg; $cfgK['puffer']['kalt_unter'] = 45; $cfgK['puffer']['kalt_zurueck'] = 50;
[$ev] = run($cfgK, '2026-11-01 17:00', 44, [[60, -1, 1], [10, -6, 2], [30, 10, 7]]);
check('kein puffer_kalt kurz nach dem Start', !in_array('puffer_kalt', array_slice($types($ev), 0)) || date('H:i', array_values(array_filter($ev, fn($x) => $x['type'] === 'puffer_kalt'))[0]['ts']) < '18:00',
    implode(', ', array_map(fn($x) => date('H:i', $x['ts']) . ' ' . $x['type'], $ev)));

echo "T) Abend 21:00, Puffer-Mittel 64 °C, kein Feuer -> Hinweis 'Heute Abend noch anheizen' (einmal)\n";
$cfgA = $cfg; $cfgA['puffer']['kalt_unter'] = -99;
[$ev, $push] = run($cfgA, '2026-11-03 19:00', 66, [[240, -1, 1]]);
$a = array_values(array_filter($push, fn($x) => $x['type'] === 'abend_anheizen'));
check('genau ein Push um 21:00', count($a) === 1 && date('H:i', $a[0]['ts']) === '21:00', implode(', ', array_map(fn($x) => date('H:i', $x['ts']), $a)));
echo "U) ... aber nicht, wenn der Pelletkessel um 21:00 gerade lädt\n";
[$ev, $push] = run($cfgA, '2026-10-30 19:30', 55, [[100, 8, 7], [60, 2, 9], [120, -1, 1]]);
check('kein abend_anheizen', !in_array('abend_anheizen', $types($push)), implode(', ', $types($push)));
echo "V) ... und nicht bei vollem Puffer (Mittel 80 °C nach einem späten Holzfeuer)\n";
[$ev, $push] = run($cfgA, '2026-11-03 20:00', 81, [[120, -0.5, 1]]);
check('kein abend_anheizen', !in_array('abend_anheizen', $types($push)), implode(', ', $types($push)));

echo "W) Nach Holzfeuer durchmischter Puffer (alle Schichten gleich, Füllgrad bleibt > 82 %) -> Hinweis bei Puffer oben ≤ 57 °C\n";
[$ev, $push] = run($cfgEcht, '2026-11-07 09:00', 62, [[240, -1.5, 1]], 'gemischt');
$n = array_values(array_filter($push, fn($x) => $x['type'] === 'puffer_niedrig'));
check('genau ein Push', count($n) === 1, implode(', ', array_map(fn($x) => date('H:i', $x['ts']) . ' ' . $x['text'], $n)));
check('über Puffer oben', $n && str_contains($n[0]['text'], 'Puffer oben nur noch'));

echo "X) Cloud: nach dem Ausbrand flammt die Glut 3 × kurz auf (07.11.) -> nur EINE Nachlegen-Meldung\n";
$F = 'STATE_EFFICIENCY_FIRE'; $B = 'STATE_BURN_OUT';
[$ev, $push] = run($cfg, '2026-11-07 11:00', 60, [[10, -1, 1, false, 0.0, 'STATE_IGNITION'], [240, 4, 1, false, 0.0, $F],
    [10, 0, 1, false, 0.0, $B], [15, 0, 1, false, 0.0, $F], [20, -1, 1, false, 0.0, $B], [10, -1, 1, false, 0.0, $F], [60, -2, 1, false, 0.0, $B]]);
$e = array_values(array_filter($push, fn($x) => $x['type'] === 'holz_ende'));
check('genau ein Nachlegen-Push, beim ersten Ausbrand (15:10)', count($e) === 1 && date('H:i', $e[0]['ts']) === '15:10',
    implode(', ', array_map(fn($x) => date('H:i', $x['ts']), $e)));
check('nur ein holz_start', count(array_filter($ev, fn($x) => $x['type'] === 'holz_start')) === 1);
echo "X2) ... nachgelegt: brennt nach dem Ausbrand wieder lange -> zweite Nachlegen-Meldung beim zweiten Ausbrand\n";
[$ev, $push] = run($cfg, '2026-11-07 11:00', 50, [[10, -1, 1, false, 0.0, 'STATE_IGNITION'], [120, 4, 1, false, 0.0, $F],
    [10, 0, 1, false, 0.0, $B], [120, 3, 1, false, 0.0, $F], [30, -1, 1, false, 0.0, $B]]);
$e = array_values(array_filter($push, fn($x) => $x['type'] === 'holz_ende'));
check('zwei Nachlegen-Pushes (13:10, 15:20)', count($e) === 2 && date('H:i', $e[1]['ts']) === '15:20', implode(', ', array_map(fn($x) => date('H:i', $x['ts']), $e)));

echo "Y) Pelletkessel liefert keine Daten (neue IP), Cloud läuft -> Holzfeuer und Nachlegen-Meldung trotzdem\n";
$dir = sys_get_temp_dir() . '/hg_test_' . uniqid(); mkdir($dir);
$pushY = [];
$m = new Monitor($cfg, $dir, function ($e) use (&$pushY) { $pushY[] = $e; return ['test' => 'ok']; });
$evY = [];
$t = strtotime('2026-11-18 12:00');
foreach ([[10, 'STATE_IGNITION'], [180, 'STATE_EFFICIENCY_FIRE'], [30, 'STATE_BURN_OUT']] as [$dauer, $z]) {
    for ($i = 0; $i < $dauer; $i++, $t += 60) {
        $evY = array_merge($evY, $m->tick(null, $t, 'Connection timed out', 0.0, ['zustand' => $z, 'rauchgas' => 150.0, 'kessel' => 80.0, 'ts' => $t]));
    }
}
$ty = array_column($evY, 'type');
check('holz_start', in_array('holz_start', $ty));
$e = array_values(array_filter($pushY, fn($x) => $x['type'] === 'holz_ende'));
check('Nachlegen-Push mit Hinweis auf fehlende Pufferwerte', count($e) === 1 && str_contains($e[0]['text'], 'Keine Pufferwerte'),
    implode(' | ', array_map(fn($x) => $x['title'] . ': ' . $x['text'], $e)));

echo $fail ? "\n$fail Prüfung(en) fehlgeschlagen\n" : "\nAlle Prüfungen bestanden\n";
exit($fail ? 1 : 0);
