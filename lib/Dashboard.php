<?php
/**
 * Daten für das Dashboard (monitor.html): aktueller Zustand, Einstellungen (ohne Zugangsdaten), Verlauf, Ereignisse.
 * Dieselben Daten liefert api.php?action=monitor auf dem Raspi und schickt lib/Online.php an die Online-Anzeige.
 */
require_once __DIR__ . '/Monitor.php';
require_once __DIR__ . '/Version.php';

final class Dashboard
{
    /** @param int|null $bis Ende des Diagramm-Ausschnitts (null = jetzt) */
    public static function daten(string $dataDir, array $mcfg, int $hours, ?int $bis = null): array
    {
        $sf = "$dataDir/monitor_state.json";
        $state = is_file($sf) ? json_decode((string) file_get_contents($sf), true) : null;
        if ($state) unset($state['recent']);
        $n = $mcfg['notify']['ntfy'] ?? null;
        return [
            'ok'      => true,
            'now'     => time(),
            'state'   => $state,
            'config'  => ['holz' => $mcfg['holz'], 'puffer' => $mcfg['puffer'], 'interval' => $mcfg['interval'],
                          'zeitfenster' => $mcfg['zeitfenster'] ?? null, 'verbrauch' => $mcfg['verbrauch'] ?? null,
                          'sonne' => $mcfg['sonne'] ?? null, 'cloud' => !empty($mcfg['cloud']['enabled']),
                          'nachlegen' => $mcfg['nachlegen'] ?? null,
                          'pellet_warnung' => ($mcfg['pellet_warnung'] ?? []) + ['fuellgrad_unter' => 82, 'zurueck_ab' => 95, 'puffer_oben_bis' => 57],
                          'abend' => Monitor::abendCfg($mcfg),
                          'notify' => array_map(fn($c) => !empty($c['enabled']), $mcfg['notify']),
                          // Server und Thema für die Anmelde-Hilfe im Dashboard (ohne Token)
                          'ntfy' => !empty($n['enabled']) ? ['server' => rtrim($n['server'], '/'), 'topic' => $n['topic']] : null],
            // mehr Punkte für lange Zeiträume (Woche: ca. alle 7 min ein Wert)
            'history' => Monitor::history($dataDir, $hours, min(1440, max(720, $hours * 10)), $bis),
            'history_von' => Monitor::historyStart($dataDir),
            'history_bis' => $bis,
            'events'  => Monitor::events($dataDir, 30),
            'pause'   => Monitor::pause($dataDir),
            'version' => ['nr' => Version::NR, 'datum' => Version::DATUM],
            'online'  => is_file("$dataDir/online_status.json") ? json_decode((string) file_get_contents("$dataDir/online_status.json"), true) : null,
        ];
    }
}
