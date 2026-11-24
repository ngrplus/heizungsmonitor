<?php
/**
 * Dauerlauf der Überwachung.
 *
 *   C:\xampp\php\php.exe monitor.php           läuft dauerhaft (Strg+C beendet)
 *   C:\xampp\php\php.exe monitor.php once      ein Messzyklus (für die Windows-Aufgabenplanung)
 *   C:\xampp\php\php.exe monitor.php test      schickt eine Testnachricht über alle aktiven Kanäle
 */
require __DIR__ . '/lib/Hargassner.php';
require __DIR__ . '/lib/Monitor.php';
require __DIR__ . '/lib/Notifier.php';
require __DIR__ . '/lib/Sonne.php';
require __DIR__ . '/lib/HargassnerCloud.php';
require __DIR__ . '/lib/Dashboard.php';
require __DIR__ . '/lib/Online.php';
$config  = require __DIR__ . '/config.php';
$mcfg    = require __DIR__ . '/monitor_config.php';

$dev = $config['devices'][$mcfg['device']] ?? null;
if (!$dev) { fwrite(STDERR, "Gerät '{$mcfg['device']}' fehlt in config.php\n"); exit(1); }

$notifier = new Notifier($mcfg['notify'], $mcfg['ruhezeit'], Notifier::onlineLink(__DIR__));
$mode = $argv[1] ?? 'loop';

if ($mode === 'test') {
    $r = $notifier->send('Heizung Test', 'Testnachricht vom Heizungsmonitor (' . date('H:i') . ').', 'info', true);
    echo $r ? json_encode($r, JSON_UNESCAPED_UNICODE) . "\n" : "Kein Kanal aktiviert (monitor_config.php -> notify).\n";
    exit;
}

$cloudCfg = $mcfg['cloud'] ?? ['enabled' => false];
$cloud = !empty($cloudCfg['enabled']) && is_file(__DIR__ . '/cloud_config.php')
    ? new HargassnerCloud(require __DIR__ . '/cloud_config.php', __DIR__ . '/data') : null;
if ($cloud && !$cloud->configured()) { echo "Hinweis: Hargassner-Cloud nicht eingerichtet (cloud_config.php)\n"; $cloud = null; }
$hv = null; $hvGeholt = 0; $hvFehler = null;
$sonne = new Sonne($mcfg['sonne'] ?? ['enabled' => false], __DIR__ . '/data');
$online = new Online(is_file(__DIR__ . '/online_config.php') ? require __DIR__ . '/online_config.php' : [], __DIR__ . '/data');
$monitor = new Monitor($mcfg, __DIR__ . '/data', fn($e) => $notifier->send($e['title'], $e['text'], $e['level']));
echo "Heizungsmonitor " . Version::NR . " (" . Version::DATUM . "): {$dev['name']} ({$dev['host']}), alle {$mcfg['interval']} s\n";

do {
    $t0 = time();
    $r = Hargassner::read($dev['host'], (int) $dev['port'], $config['connect_timeout'], $config['read_seconds']);
    $ghi = $sonne->wert($t0);
    if ($cloud && $t0 - $hvGeholt >= ($cloudCfg['intervall_s'] ?? 300)) {
        $hvGeholt = $t0;
        try {
            $hv = scheitholzAusCloud($cloud->widgets($cloudCfg['scheitholz_id']), $t0);
            $hvFehler = null;
        } catch (Throwable $e) {
            $hvFehler = $e->getMessage();
        }
    }
    $hvAktuell = ($hv && $t0 - $hv['ts'] <= 60 * ($cloudCfg['max_alter_min'] ?? 15)) ? $hv : null;
    $events = $monitor->tick($r['ok'] ? $r['values'] : null, $t0, $r['error'] ?? '', $ghi, $hvAktuell);
    if ($r['ok'] && ($mcfg['rohdaten_tage'] ?? 0) > 0) rohdaten($t0, $r['raw'], (int) $mcfg['rohdaten_tage']);
    $c = $monitor->state()['current'];
    $line = date('H:i:s') . '  ';
    $line .= $r['ok']
        ? sprintf('Puffer %s/%s/%s °C  %s %%  Trend %s K/h (netto %s)  Sonne %s  Heizung %s  Pellet %s  Holz: %s',
            $c['TPO'] ?? '?', $c['TPM'] ?? '?', $c['TPU'] ?? '?', $c['PFD'] ?? '?',
            $c['trend'] ?? '–', $c['netto'] ?? '–', $ghi === null ? '? (' . ($sonne->fehler() ?? 'keine Daten') . ')' : round($ghi) . ' W/m²',
            $c['heizung'] === null ? '?' : ($c['heizung'] ? 'an' : 'aus'),
            $c['pellet_aus'] ? 'aus' : 'an', $monitor->state()['holz'])
          . ($cloud ? '  HV-Cloud: ' . ($hvAktuell ? Monitor::hvText($hvAktuell['zustand']) . ', Rauchgas ' . $hvAktuell['rauchgas'] . ' °C' : '? ' . ($hvFehler ?? '')) : '')
        : 'FEHLER ' . $r['error'];
    echo $line . "\n";
    foreach ($events as $e) {
        echo "   >>> {$e['title']}: {$e['text']}" . (!empty($e['versand']) ? '  [' . json_encode($e['versand'], JSON_UNESCAPED_UNICODE) . ']' : '') . "\n";
    }
    // Online-Anzeige (falls eingerichtet): Dashboard-Daten an den Webserver schicken
    if ($online->faellig(time())) {
        $f = $online->senden(Dashboard::daten(__DIR__ . '/data', $mcfg, $online->stunden()), time());
        if ($f !== null) echo "   Online-Anzeige: $f\n";
    }
    if ($mode === 'once') break;
    sleep(max(5, $mcfg['interval'] - (time() - $t0)));
} while (true);

/** Rohzeile anhängen; alte Rohdaten-Dateien aufräumen (einmal pro Tag). */
function rohdaten(int $ts, string $raw, int $tage): void
{
    $dir = __DIR__ . '/data';
    $file = "$dir/raw_" . date('Y-m-d', $ts) . '.txt';
    $neu = !is_file($file);
    file_put_contents($file, $ts . ' ' . $raw . "\n", FILE_APPEND);
    if ($neu) {
        foreach (glob("$dir/raw_*.txt") ?: [] as $f) {
            if (preg_match('/raw_(\d{4}-\d{2}-\d{2})\.txt$/', $f, $m) && strtotime($m[1]) < $ts - $tage * 86400) @unlink($f);
        }
    }
}

/** Kesselwerte des Scheitholzkessels aus der Widget-Antwort der Cloud. */
function scheitholzAusCloud(array $w, int $ts): ?array
{
    foreach ($w['data'] ?? [] as $widget) {
        if (($widget['widget'] ?? '') !== 'HEATER') continue;
        $v = $widget['values'] ?? [];
        return ['zustand' => $v['state'] ?? null, 'rauchgas' => $v['smoke_temperature'] ?? null,
                'kessel' => $v['heater_temperature_current'] ?? null, 'soll' => $v['heater_temperature_target'] ?? null,
                'leistung' => $v['efficiency'] ?? null, 'ts' => $ts];
    }
    return null;
}
