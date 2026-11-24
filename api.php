<?php
/**
 * JSON-API für die Web-UI.
 *   api.php?action=devices
 *   api.php?action=probe&id=<geraet>
 *   api.php?action=read&id=<geraet>
 *   api.php?action=save_labels&id=<geraet>   (POST, JSON-Body)
 */
declare(strict_types=1);
require __DIR__ . '/lib/Hargassner.php';
$config = require __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
set_time_limit(30);

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function device(array $config): array
{
    $id = (string) ($_GET['id'] ?? '');
    if (!isset($config['devices'][$id])) out(['ok' => false, 'error' => 'Unbekanntes Gerät'], 404);
    return [$id, $config['devices'][$id]];
}

function mapping(string $id, array $dev): array
{
    $name = preg_replace('/[^a-z0-9_\-]/i', '', $dev['mapping'] ?? 'generic');
    $file = __DIR__ . "/mappings/$name.json";
    $map = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (!is_array($map)) $map = ['name' => 'leer', 'fields' => []];
    $labelFile = __DIR__ . "/data/labels_$id.json";
    if (is_file($labelFile)) {
        $own = json_decode((string) file_get_contents($labelFile), true) ?: [];
        foreach ($own as $i => $f) {
            $map['fields'][$i] = array_merge($map['fields'][$i] ?? [], $f, ['own' => true]);
        }
    }
    return $map;
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'devices':
        $list = [];
        foreach ($config['devices'] as $id => $d) {
            $list[] = ['id' => $id, 'name' => $d['name'], 'host' => $d['host'], 'port' => $d['port'],
                       'mapping' => mapping($id, $d)];
        }
        out(['ok' => true, 'devices' => $list, 'readSeconds' => $config['read_seconds']]);

    case 'probe':
        [$id, $dev] = device($config);
        $ports = array_values(array_unique(array_merge([(int) $dev['port']], $config['probe_ports'])));
        $res = [];
        foreach ($ports as $p) $res[] = Hargassner::probe($dev['host'], (int) $p, 1.5);
        out(['ok' => true, 'host' => $dev['host'], 'ports' => $res]);

    case 'read':
        [$id, $dev] = device($config);
        $r = Hargassner::read($dev['host'], (int) $dev['port'],
                              (float) $config['connect_timeout'], (float) $config['read_seconds']);
        $r['time'] = date('Y-m-d H:i:s');
        out($r);

    case 'save_labels':
        [$id, $dev] = device($config);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST erwartet'], 405);
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) out(['ok' => false, 'error' => 'Ungültiges JSON'], 400);
        $clean = [];
        foreach ($in as $i => $f) {
            if (!is_numeric($i) || !is_array($f)) continue;
            $clean[(int) $i] = [
                'key'   => mb_substr(trim((string) ($f['key'] ?? '')), 0, 20),
                'label' => mb_substr(trim((string) ($f['label'] ?? '')), 0, 80),
                'unit'  => mb_substr(trim((string) ($f['unit'] ?? '')), 0, 10),
            ];
        }
        ksort($clean);
        if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
        file_put_contents(__DIR__ . "/data/labels_$id.json",
            json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        out(['ok' => true, 'saved' => count($clean)]);

    case 'monitor':
        require __DIR__ . '/lib/Dashboard.php';
        $hours = max(1, min(168, (int) ($_GET['hours'] ?? 24)));
        $bis = isset($_GET['bis']) && (int) $_GET['bis'] > 0 && (int) $_GET['bis'] < time() - 120 ? (int) $_GET['bis'] : null;
        out(Dashboard::daten(__DIR__ . '/data', require __DIR__ . '/monitor_config.php', $hours, $bis));

    case 'notify_test':
        require __DIR__ . '/lib/Notifier.php';
        $mcfg = require __DIR__ . '/monitor_config.php';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(['ok' => false, 'error' => 'POST erwartet'], 405);
        $r = (new Notifier($mcfg['notify'], $mcfg['ruhezeit'], Notifier::onlineLink(__DIR__)))->send('Heizung: Testnachricht',
            'Test vom Heizungsmonitor um ' . date('H:i') . ' Uhr. Wenn du das liest, funktioniert die Benachrichtigung.', 'info', true);
        out(['ok' => (bool) $r && !array_filter($r, fn($x) => $x !== 'ok'), 'result' => $r,
             'error' => $r ? null : 'Kein Kanal aktiviert (monitor_config.php → notify).']);

    case 'pause':
        require_once __DIR__ . '/lib/Monitor.php';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $in = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $std = (float) ($in['stunden'] ?? 0);
            if (isset($in['bis']) && is_numeric($in['bis'])) $bis = (int) $in['bis'];
            elseif ($std > 0) $bis = time() + (int) round(min($std, 24 * 14) * 3600);
            else $bis = null;
            Monitor::setPause(__DIR__ . '/data', $bis, mb_substr((string) ($in['grund'] ?? ''), 0, 80));
        }
        out(['ok' => true, 'pause' => Monitor::pause(__DIR__ . '/data')]);

    case 'sonne':
        require __DIR__ . '/lib/Sonne.php';
        $scfg = (require __DIR__ . '/monitor_config.php')['sonne'] ?? ['enabled' => false];
        $so = new Sonne($scfg, __DIR__ . '/data');
        $w = $so->wert(time());
        out(['ok' => $w !== null, 'w_m2' => $w, 'fehler' => $so->fehler(), 'ort' => [$scfg['lat'] ?? null, $scfg['lon'] ?? null]]);

    case 'mqtt_scan':
        require __DIR__ . '/lib/Mqtt.php';
        $mq = (require __DIR__ . '/monitor_config.php')['mqtt'] ?? null;
        if (!$mq) out(['ok' => false, 'error' => 'MQTT ist in monitor_config.php nicht eingerichtet.']);
        $secs = max(2, min(60, (int) ($_GET['secs'] ?? 15)));
        $filter = trim((string) ($_GET['filter'] ?? '#')) ?: '#';
        set_time_limit($secs + 15);
        try {
            $c = new Mqtt($mq['host'], (int) $mq['port'], (string) $mq['user'], (string) $mq['pass']);
            $c->connect();
            $msgs = $c->collect([$filter], $secs);
            $c->close();
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'abgelehnt') && trim((string) $mq['user']) === '') {
                $msg .= ' – der Broker verlangt Benutzername und Passwort. Bitte in monitor_config.php unter \'mqtt\' bei user und pass eintragen.';
            }
            out(['ok' => false, 'error' => $msg]);
        }
        ksort($msgs);
        $list = [];
        foreach ($msgs as $t => [$p, $n, $ret]) {
            $utf = mb_check_encoding($p, 'UTF-8') ? $p : '(binär, ' . strlen($p) . ' Bytes)';
            $list[] = ['topic' => $t, 'payload' => mb_substr($utf, 0, 400), 'count' => $n, 'retained' => $ret];
        }
        out(['ok' => true, 'broker' => "{$mq['host']}:{$mq['port']}", 'secs' => $secs, 'filter' => $filter, 'topics' => $list]);

    case 'cloud_installations':
    case 'cloud_widgets':
        require __DIR__ . '/lib/HargassnerCloud.php';
        $cloud = new HargassnerCloud(require __DIR__ . '/cloud_config.php', __DIR__ . '/data');
        if (!$cloud->configured()) out(['ok' => false, 'error' => 'Bitte E-Mail und Passwort in cloud_config.php eintragen.']);
        try {
            if ($action === 'cloud_installations') {
                out(['ok' => true, 'installations' => array_map(fn($i) => array_diff_key($i, ['raw' => 1]), $cloud->installations())]);
            }
            $id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($_GET['inst'] ?? ''));
            if ($id === '') out(['ok' => false, 'error' => 'Anlage fehlt'], 400);
            $w = $cloud->widgets($id);
            // Schnappschuss speichern, damit sich Verläufe später vergleichen lassen
            file_put_contents(__DIR__ . "/data/cloud_widgets_{$id}_" . date('Ymd_His') . '.json',
                json_encode($w, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $widgets = [];
            foreach (($w['data'] ?? []) as $i => $widget) {
                $name = $widget['widget'] ?? $widget['name'] ?? $widget['type'] ?? $widget['key'] ?? "Widget $i";
                $widgets[] = ['name' => is_string($name) ? $name : "Widget $i", 'values' => HargassnerCloud::flatten($widget)];
            }
            out(['ok' => true, 'time' => date('Y-m-d H:i:s'), 'widgets' => $widgets,
                 'other' => HargassnerCloud::flatten(array_diff_key($w, ['data' => 1]))]);
        } catch (Throwable $e) {
            out(['ok' => false, 'error' => $e->getMessage()]);
        }

    default:
        out(['ok' => false, 'error' => 'Unbekannte Aktion'], 400);
}
