<?php
/**
 * Minimaler Client für die (inoffiziell genutzte) Hargassner-Cloud-API.
 * Endpunkte nach github.com/ofg/hargassner-monitoring:
 *   POST /api/auth/login                     -> access_token (Bearer)
 *   GET  /api/installations                  -> data[]: id, name, slug
 *   GET  /api/installations/{id}/widgets     -> data[]: Widgets mit Werten
 */
class HargassnerCloud
{
    private ?array $token = null;
    private string $tokenFile;

    public function __construct(private array $cfg, private string $dataDir)
    {
        if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);
        $this->tokenFile = "$dataDir/cloud_token.json";
        if (!empty($cfg['cache_token']) && is_file($this->tokenFile)) {
            $t = json_decode((string) file_get_contents($this->tokenFile), true);
            if (is_array($t) && ($t['expires_at'] ?? 0) > time() + 60) $this->token = $t;
        }
    }

    public function configured(): bool
    {
        return trim($this->cfg['email'] ?? '') !== '' && trim($this->cfg['password'] ?? '') !== '';
    }

    public function login(bool $force = false): void
    {
        if ($this->token && !$force) return;
        if (!$this->configured()) throw new RuntimeException('E-Mail und Passwort fehlen in cloud_config.php.');
        [$code, $json] = $this->request('POST', '/api/auth/login', [
            'email' => $this->cfg['email'], 'password' => $this->cfg['password'],
            'client_id' => (string) $this->cfg['client_id'], 'client_secret' => $this->cfg['client_secret'],
        ], false);
        if ($code !== 200 || empty($json['access_token'])) {
            $msg = $json['message'] ?? $json['error'] ?? '';
            throw new RuntimeException("Login fehlgeschlagen (HTTP $code" . ($msg ? ": $msg" : '') . ').');
        }
        $this->token = ['access_token' => $json['access_token'],
                        'expires_at' => time() + (int) ($json['expires_in'] ?? 3600)];
        if (!empty($this->cfg['cache_token'])) file_put_contents($this->tokenFile, json_encode($this->token));
    }

    public function installations(): array
    {
        $j = $this->get('/api/installations?with=devices.gateway%3Bcreator&sort=name&pageSize=100&page=1');
        return array_map(fn($i) => ['id' => $i['id'] ?? null, 'name' => $i['name'] ?? '', 'slug' => $i['slug'] ?? '',
                                    'raw' => $i], $j['data'] ?? []);
    }

    public function widgets($installationId): array
    {
        return $this->get('/api/installations/' . rawurlencode((string) $installationId) . '/widgets');
    }

    /** GET mit automatischem Neu-Login bei abgelaufenem Token. */
    private function get(string $path): array
    {
        $this->login();
        [$code, $json] = $this->request('GET', $path);
        if ($code === 401) { $this->login(true); [$code, $json] = $this->request('GET', $path); }
        if ($code !== 200) throw new RuntimeException("Cloud-Anfrage $path fehlgeschlagen (HTTP $code).");
        return $json ?? [];
    }

    private function request(string $method, string $path, ?array $body = null, bool $auth = true): array
    {
        $headers = ['Accept: application/json', 'Branding: BRANDING_HARGASSNER'];
        if ($auth && $this->token) $headers[] = 'Authorization: Bearer ' . $this->token['access_token'];
        if ($body !== null) $headers[] = 'Content-Type: application/json';
        $ctx = stream_context_create([
            'http' => ['method' => $method, 'header' => implode("\r\n", $headers),
                       'content' => $body !== null ? json_encode($body) : '', 'timeout' => 15, 'ignore_errors' => true],
        ]);
        $raw = @file_get_contents(rtrim($this->cfg['url'], '/') . $path, false, $ctx);
        if ($raw === false) {
            $err = error_get_last()['message'] ?? 'unbekannt';
            if (stripos($err, 'openssl') !== false || stripos($err, 'https') !== false) {
                $err .= ' – ist in C:\xampp\php\php.ini "extension=openssl" aktiv?';
            }
            throw new RuntimeException('Hargassner-Cloud nicht erreichbar: ' . $err);
        }
        $code = preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $m) ? (int) $m[1] : 0;
        return [$code, json_decode($raw, true)];
    }

    /** Verschachteltes JSON in "pfad => wert"-Paare zerlegen (für die Erkundung). */
    public static function flatten($data, string $prefix = ''): array
    {
        $out = [];
        if (is_array($data)) {
            foreach ($data as $k => $v) $out += self::flatten($v, $prefix === '' ? (string) $k : "$prefix.$k");
        } else {
            $out[$prefix] = $data;
        }
        return $out;
    }
}
