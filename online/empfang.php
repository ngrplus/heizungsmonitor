<?php
/**
 * Nimmt die Daten vom Raspi an (POST, gzip-komprimiertes JSON) und speichert sie in data/stand.php.
 * (PHP-Datei mit Sperre in der ersten Zeile: wird auch ohne .htaccess nie ausgeliefert.)
 * Ohne den richtigen Schlüssel im Header X-Heizung-Token wird nichts angenommen.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function antwort($code, $daten) { http_response_code($code); echo json_encode($daten); exit; }

$cfg = require __DIR__ . '/config.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') antwort(405, ['ok' => false, 'error' => 'POST erwartet']);
$token = isset($_SERVER['HTTP_X_HEIZUNG_TOKEN']) ? (string) $_SERVER['HTTP_X_HEIZUNG_TOKEN'] : '';
if (strlen($cfg['token']) < 20 || !hash_equals($cfg['token'], $token)) antwort(403, ['ok' => false, 'error' => 'Schlüssel falsch']);

$roh = file_get_contents('php://input', false, null, 0, 8 * 1024 * 1024);
if (!empty($_SERVER['HTTP_X_GZIP'])) $roh = @gzdecode($roh);
$d = is_string($roh) ? json_decode($roh, true) : null;
if (!is_array($d) || !isset($d['state'], $d['history'], $d['config'])) antwort(400, ['ok' => false, 'error' => 'Daten unvollständig']);

$d['empfangen'] = time();
$dir = __DIR__ . '/data';
if (!is_dir($dir)) mkdir($dir, 0750, true);
$sperre = "<?php http_response_code(403); exit; ?>\n";

// Verlauf sammeln: neue Messungen an die Tagesdatei data/verlauf_JJJJ-MM-TT.php anhängen (eine JSON-Zeile je Messung)
$sf = $dir . '/verlauf_stand.php';
$letzter = is_file($sf) ? (int) trim(substr((string) file_get_contents($sf), strlen($sperre))) : 0;
$neu = [];
foreach ((array) ($d['nachtrag'] ?? []) as $r) {
    if (!is_array($r) || !isset($r['ts']) || (int) $r['ts'] <= $letzter) continue;
    $neu[date('Y-m-d', (int) $r['ts'])][] = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $letzter = max($letzter, (int) $r['ts']);
}
foreach ($neu as $tag => $zeilen) {
    $f = "$dir/verlauf_$tag.php";
    file_put_contents($f, (is_file($f) ? '' : $sperre) . implode("\n", $zeilen) . "\n", FILE_APPEND | LOCK_EX);
}
if ($neu) file_put_contents($sf, $sperre . $letzter);
unset($d['nachtrag']);
// alte Tage aufräumen (einmal am Tag)
$tage = (int) ($cfg['verlauf_tage'] ?? 400);
if ($neu && date('H') === '03') {
    foreach (glob("$dir/verlauf_*.php") ?: [] as $f) {
        if (preg_match('/verlauf_(\d{4}-\d{2}-\d{2})\.php$/', $f, $m) && strtotime($m[1]) < time() - $tage * 86400) @unlink($f);
    }
}
$tmp = $dir . '/stand.tmp.php';
if (file_put_contents($tmp, $sperre . json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false || !rename($tmp, $dir . '/stand.php')) {
    antwort(500, ['ok' => false, 'error' => 'Kann nicht speichern (Schreibrechte für den Ordner data/?)']);
}
antwort(200, ['ok' => true, 'letzter_ts' => $letzter ?: null]);
