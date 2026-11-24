<?php
/** Verschickt Ereignisse über die in monitor_config.php aktivierten Kanäle. */
class Notifier
{
    /** @param string|null $link Adresse, die beim Antippen der Nachricht geöffnet wird (Online-Anzeige) */
    public function __construct(private array $cfg, private ?array $ruhezeit = null, private ?string $link = null) {}

    /** Adresse der Online-Anzeige aus online_config.php ('anzeige_url' oder der Ordner von empfang.php), sonst null. */
    public static function onlineLink(string $appDir): ?string
    {
        $f = "$appDir/online_config.php";
        if (!is_file($f)) return null;
        $o = require $f;
        if (empty($o['enabled'])) return null;
        $u = (string) ($o['anzeige_url'] ?? preg_replace('#/[^/]*$#', '/', (string) ($o['url'] ?? '')));
        return str_starts_with($u, 'https://') && !str_contains($u, 'DEINE-DOMAIN') ? $u : null;
    }

    /** Liefert eine Liste "kanal => ok|Fehlertext". */
    public function send(string $title, string $text, string $level = 'info', bool $ignoreQuiet = false): array
    {
        $res = [];
        if (!$ignoreQuiet && $this->isQuiet()) return ['ruhezeit' => 'unterdrückt'];

        $n = $this->cfg['ntfy'] ?? [];
        if (!empty($n['enabled'])) {
            // JSON-Variante: Umlaute in Titel und Text kommen sicher an
            $daten = [
                'topic'    => $n['topic'],
                'title'    => $title,
                'message'  => $text,
                'priority' => $level === 'alarm' ? 4 : 3,   // 4 = hoch (eigener Ton), 3 = normal
                'tags'     => [$level === 'alarm' ? 'warning' : 'fire'],
            ];
            if ($this->link) {
                $daten['click'] = $this->link;              // Antippen öffnet die Online-Anzeige
                $daten['actions'] = [['action' => 'view', 'label' => 'Heizungsmonitor', 'url' => $this->link]];
            }
            $msg = json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $headers = ['Content-Type: application/json'];
            if (!empty($n['token'])) $headers[] = 'Authorization: Bearer ' . $n['token'];
            $res['ntfy'] = $this->http(rtrim($n['server'], '/') . '/', 'POST', $msg, $headers);
        }
        $w = $this->cfg['webhook'] ?? [];
        if (!empty($w['enabled'])) {
            $url = str_replace('{text}', rawurlencode("$title: $text"), $w['url']);
            $res['webhook'] = $this->http($url, 'GET');
        }
        return $res;
    }

    private function isQuiet(): bool
    {
        if (!$this->ruhezeit) return false;
        $now = date('H:i');
        [$von, $bis] = [$this->ruhezeit['von'], $this->ruhezeit['bis']];
        return $von <= $bis ? ($now >= $von && $now < $bis) : ($now >= $von || $now < $bis);
    }

    private function http(string $url, string $method, string $body = '', array $headers = []): string
    {
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => 10, 'ignore_errors' => true,
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        if ($r === false) {
            $err = error_get_last()['message'] ?? 'unbekannt';
            if (stripos($err, 'ssl') !== false || stripos($err, 'wrapper') !== false) $err .= ' (php.ini: extension=openssl aktiv?)';
            return 'Fehler: ' . $err;
        }
        $status = $http_response_header[0] ?? '';
        return preg_match('/\s2\d\d\s/', $status) ? 'ok' : "Fehler: $status";
    }
}
