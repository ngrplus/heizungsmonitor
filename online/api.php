<?php
/**
 * Lese-Schnittstelle der Online-Anzeige: liefert die zuletzt vom Raspi empfangenen Daten
 * im selben Format wie api.php?action=monitor auf dem Raspi. Keine Aktionen (Pause, Testnachricht) möglich.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function antwort($code, $daten) { http_response_code($code); echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

if (($_GET['action'] ?? '') !== 'monitor') antwort(403, ['ok' => false, 'error' => 'Nur Anzeige – Aktionen sind nur am Raspi möglich.']);
$f = __DIR__ . '/data/stand.php';
// erste Zeile ist die Zugriffssperre, danach folgt das JSON
$d = is_file($f) ? json_decode((string) substr((string) file_get_contents($f), strpos((string) file_get_contents($f), "\n") + 1), true) : null;
if (!is_array($d)) antwort(200, ['ok' => false, 'nur_anzeige' => true, 'error' => 'Noch keine Daten vom Raspi empfangen.']);

$stunden = max(1, min(168, (int) ($_GET['hours'] ?? 24)));
$bis = isset($_GET['bis']) && (int) $_GET['bis'] > 0 && (int) $_GET['bis'] < time() - 120 ? (int) $_GET['bis'] : null;
$ab = ($bis ?? time()) - $stunden * 3600;
$ende = $bis ?? time();
$d['history_bis'] = $bis;
$tage = glob(__DIR__ . '/data/verlauf_*.php') ?: [];
sort($tage);
if ($tage) {
    // gesammelter Verlauf: Tagesdateien im Zeitraum lesen, wie auf dem Raspi ausdünnen
    preg_match('/verlauf_(\d{4}-\d{2}-\d{2})/', $tage[0], $m);
    $d['history_von'] = strtotime($m[1]);
    $rows = [];
    for ($t = strtotime(date('Y-m-d', $ab)); $t <= $ende; $t += 86400) {
        $f = __DIR__ . '/data/verlauf_' . date('Y-m-d', $t) . '.php';
        if (!is_file($f)) continue;
        $zeilen = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        array_shift($zeilen);   // Zugriffssperre
        foreach ($zeilen as $z) {
            $r = json_decode($z, true);
            if (is_array($r) && (int) $r['ts'] >= $ab && (int) $r['ts'] <= $ende) $rows[(int) $r['ts']] = $r;
        }
    }
    // dazu der Ausschnitt aus der letzten Sendung – deckt die jüngste Zeit ab, solange der Raspi noch nachholt
    foreach ($d['history'] ?? [] as $r) {
        if ((int) $r['ts'] >= $ab && (int) $r['ts'] <= $ende) $rows[(int) $r['ts']] = $rows[(int) $r['ts']] ?? $r;
    }
    ksort($rows);
    $rows = array_values($rows);
    $max = min(1440, max(720, $stunden * 10));
    $schritt = max(1, (int) ceil(count($rows) / $max));
    $d['history'] = $schritt > 1 ? array_values(array_filter($rows, function ($i) use ($schritt) { return $i % $schritt === 0; }, ARRAY_FILTER_USE_KEY)) : $rows;
} else {
    // noch nichts gesammelt: Ausschnitt aus der letzten Sendung
    $alle = $d['history'] ?? [];
    $d['history_von'] = $alle ? (int) $alle[0]['ts'] : null;
    $d['history'] = array_values(array_filter($alle, function ($r) use ($ab, $ende) { return (int) $r['ts'] >= $ab && (int) $r['ts'] <= $ende; }));
}
$d['raspi_now'] = $d['now'] ?? null;
$d['now'] = time();          // Alter der Daten wird an der Uhr des Webservers gemessen
$d['nur_anzeige'] = true;
antwort(200, $d);
