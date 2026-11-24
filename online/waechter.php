<?php
/**
 * Wächter: Meldet per ntfy, wenn der Raspi seit 'nach_min' Minuten keine Daten mehr geschickt hat
 * (Stromausfall, Internet weg, Raspi abgestürzt), und noch einmal, wenn er wieder da ist.
 * Regelmäßig aufrufen, z. B. alle 5 Minuten per Cron des Webhosters oder cron-job.org:
 *   https://DEINE-DOMAIN.de/heizung/waechter.php?schluessel=…
 */
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
$cfg = require __DIR__ . '/config.php';
$w = $cfg['waechter'];
$schluessel = PHP_SAPI === 'cli' ? $w['schluessel'] : (string) ($_GET['schluessel'] ?? '');
if (empty($w['enabled']) || !hash_equals((string) $w['schluessel'], $schluessel)) { http_response_code(403); exit("Kein Zugriff.\n"); }

$dir = __DIR__ . '/data';
$stand = is_file("$dir/stand.php") ? filemtime("$dir/stand.php") : 0;
$sf = "$dir/waechter.php";
$roh = is_file($sf) ? (string) file_get_contents($sf) : '';
$s = $roh !== '' ? (json_decode(substr($roh, strpos($roh, "\n") + 1), true) ?: []) : [];
$alarm = !empty($s['alarm']);
$alterMin = $stand ? (int) floor((time() - $stand) / 60) : null;

/** Adresse dieser Online-Anzeige (Ordner von waechter.php) für den Link in der Nachricht */
function waechterLink() {
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) return null;
    return 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
}

function ntfy($w, $titel, $text, $prio) {
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 10, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode(['topic' => $w['ntfy_topic'], 'title' => $titel, 'message' => $text, 'priority' => $prio, 'tags' => ['warning'],
            'click' => waechterLink()], JSON_UNESCAPED_SLASHES)]]);
    return @file_get_contents(rtrim($w['ntfy_server'], '/') . '/', false, $ctx) !== false;
}

if (!$alarm && ($alterMin === null || $alterMin >= $w['nach_min'])) {
    $ok = $w['ntfy_topic'] !== '' && ntfy($w, 'Heizungsmonitor meldet sich nicht',
        $stand ? 'Seit ' . date('H:i', $stand) . " Uhr ($alterMin min) keine Daten vom Raspberry Pi. Strom, Internet oder Raspi prüfen."
               : 'Noch nie Daten vom Raspberry Pi empfangen.', 4);
    $s = ['alarm' => true, 'seit' => time(), 'gesendet' => $ok];
    echo "ALARM gemeldet (" . ($ok ? 'ntfy ok' : 'ntfy FEHLER') . ")\n";
} elseif ($alarm && $alterMin !== null && $alterMin < $w['nach_min']) {
    ntfy($w, 'Heizungsmonitor wieder da', 'Der Raspberry Pi schickt wieder Daten.', 3);
    $s = ['alarm' => false];
    echo "wieder da\n";
} else {
    echo ($alarm ? 'Alarm besteht' : 'OK') . ', letzte Daten vor ' . ($alterMin ?? '?') . " min\n";
}
file_put_contents($sf, "<?php http_response_code(403); exit; ?>\n" . json_encode($s));
