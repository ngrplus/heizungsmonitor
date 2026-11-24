<?php
/**
 * Schickt die Dashboard-Daten regelmäßig an die Online-Anzeige (online/empfang.php auf einem Webserver).
 * Nur in diese Richtung: Der Raspi sendet, der Webserver kann nichts am Raspi auslösen.
 * Einstellungen in online_config.php (Adresse, geheimer Schlüssel, Intervall).
 *
 * Verlauf: Der Webserver sammelt ihn selbst (eine Datei pro Tag). Mit jeder Sendung gehen die Messungen seit dem
 * letzten dort gespeicherten Zeitpunkt mit ('nachtrag', höchstens 2 Tage auf einmal) – so wird nach einer Unterbrechung
 * und beim ersten Mal der ganze Verlauf seit Beginn der Aufzeichnung in Etappen nachgeholt.
 */
final class Online
{
    private int $zuletzt = 0;

    public function __construct(private array $cfg, private string $dataDir) {}

    public function aktiv(): bool
    {
        $url = (string) ($this->cfg['url'] ?? '');
        // nur verschlüsselt senden (Ausnahme: Test auf dem eigenen Rechner)
        return !empty($this->cfg['enabled']) && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://127.0.0.1'))
            && !str_contains($url, 'DEINE-DOMAIN')
            && strlen((string) ($this->cfg['token'] ?? '')) >= 20;
    }

    public function faellig(int $ts): bool
    {
        return $this->aktiv() && $ts - $this->zuletzt >= max(60, (int) ($this->cfg['intervall_s'] ?? 180));
    }

    /**
     * Verlauf im Schnappschuss: Hat der Webserver den Verlauf bis vor kurzem gesammelt, genügt 1 h. Solange er noch
     * nachholt (oder eine alte Version ist), geht der Ausschnitt aus online_config.php mit, damit die Anzeige nie leer ist.
     */
    public function stunden(): int
    {
        $st = $this->status();
        $aktuell = ($st['verlauf'] ?? false) && ($st['server_letzter_ts'] ?? 0) >= time() - 3600;
        return $aktuell ? 1 : max(6, min(168, (int) ($this->cfg['stunden'] ?? 48)));
    }

    private function status(): array
    {
        $f = "$this->dataDir/online_status.json";
        return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
    }

    /** Messungen, die dem Webserver noch fehlen (volle Auflösung, höchstens 2 Tage ab seinem letzten Stand). */
    private function nachtrag(int $ts): array
    {
        $st = $this->status();
        if (!array_key_exists('server_letzter_ts', $st)) return [];   // Webserver noch nicht bekannt bzw. alte Version
        $von = $st['server_letzter_ts'] !== null ? (int) $st['server_letzter_ts'] + 1 : (Monitor::historyStart($this->dataDir) ?? $ts - 86400);
        // leere Abschnitte (Raspi war aus) überspringen, bis Messungen kommen
        while ($von < $ts) {
            $bis = min($ts, $von + 2 * 86400);
            $rows = Monitor::history($this->dataDir, (int) ceil(($bis - $von) / 3600) + 1, PHP_INT_MAX, $bis);
            $rows = array_values(array_filter($rows, fn($r) => (int) $r['ts'] >= $von));
            if ($rows) return $rows;
            $von = $bis + 1;
        }
        return [];
    }

    /** Sendet die Daten; Ergebnis wird in data/online_status.json festgehalten. Liefert null oder den Fehlertext. */
    public function senden(array $daten, int $ts): ?string
    {
        $this->zuletzt = $ts;
        $daten['nachtrag'] = $this->nachtrag($ts);
        $body = gzencode(json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 6);
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true,
            'header' => "Content-Type: application/octet-stream\r\nX-Heizung-Token: {$this->cfg['token']}\r\nX-Gzip: 1\r\n"
                      . 'Content-Length: ' . strlen($body) . "\r\nUser-Agent: Heizungsmonitor\r\n",
            'content' => $body,
        ]]);
        $antwort = @file_get_contents($this->cfg['url'], false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m) ? (int) $m[1] : 0;
        $r = is_string($antwort) ? json_decode($antwort, true) : null;
        $fehler = null;
        if ($code !== 200 || empty($r['ok'])) {
            $fehler = $code === 0 ? 'Webserver nicht erreichbar' : "HTTP $code" . (!empty($r['error']) ? ': ' . $r['error'] : '');
        }
        $status = $this->status();
        if ($fehler === null) {
            $status = ['ok_ts' => $ts, 'bytes' => strlen($body)] + $status;
            $status['verlauf'] = array_key_exists('letzter_ts', $r);          // Webserver sammelt den Verlauf selbst
            if ($status['verlauf']) $status['server_letzter_ts'] = $r['letzter_ts'];
            else unset($status['server_letzter_ts']);
        }
        // beim Nachholen nicht 3 min warten, sondern nach 1 min die nächste Etappe schicken
        if ($fehler === null && ($status['verlauf'] ?? false) && ($status['server_letzter_ts'] ?? 0) < $ts - 3600) {
            $this->zuletzt = $ts - max(60, (int) ($this->cfg['intervall_s'] ?? 180)) + 60;
        }
        $status['fehler'] = $fehler; $status['versuch_ts'] = $ts;
        file_put_contents("$this->dataDir/online_status.json", json_encode($status, JSON_UNESCAPED_UNICODE));
        return $fehler;
    }
}
