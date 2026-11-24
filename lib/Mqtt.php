<?php
/**
 * Minimaler MQTT-3.1.1-Client (nur lesen, QoS 0) ohne Zusatzpakete.
 * Reicht, um Werte vom Broker im Heimnetz abzuholen.
 */
class Mqtt
{
    private $fp = null;

    public function __construct(private string $host, private int $port = 1883,
                                private string $user = '', private string $pass = '', private float $timeout = 3.0) {}

    public function connect(string $clientId = ''): void
    {
        $errno = 0; $errstr = '';
        $this->fp = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->fp) throw new RuntimeException("MQTT-Broker {$this->host}:{$this->port} nicht erreichbar: " . trim($errstr));
        stream_set_timeout($this->fp, (int) ceil($this->timeout));
        $clientId = $clientId ?: 'heizungsmonitor-' . bin2hex(random_bytes(4));
        $flags = 0x02; // clean session
        $payload = $this->str($clientId);
        if ($this->user !== '') { $flags |= 0x80; $payload .= $this->str($this->user); }
        if ($this->pass !== '') { $flags |= 0x40; $payload .= $this->str($this->pass); }
        $var = $this->str('MQTT') . chr(4) . chr($flags) . pack('n', 60);
        $this->send(0x10, $var . $payload);
        [$type, $body] = $this->readPacket($this->timeout) ?? [null, ''];
        if ($type !== 2) throw new RuntimeException('MQTT: keine Antwort auf CONNECT');
        $rc = ord($body[1] ?? "\xff");
        if ($rc !== 0) {
            $why = [1 => 'Protokollversion', 2 => 'Client-ID abgelehnt', 3 => 'Broker nicht verfügbar',
                    4 => 'Benutzername/Passwort falsch', 5 => 'nicht berechtigt'][$rc] ?? "Code $rc";
            throw new RuntimeException("MQTT-Anmeldung abgelehnt: $why");
        }
    }

    /** Abonniert $filters und sammelt $seconds Sekunden lang Nachrichten: [topic => [payload, anzahl, retained]] */
    public function collect(array $filters, float $seconds, ?callable $stopWhen = null): array
    {
        $pl = '';
        foreach ($filters as $f) $pl .= $this->str($f) . chr(0);
        $this->send(0x82, pack('n', 1) . $pl);
        $out = [];
        $end = microtime(true) + $seconds;
        while (($left = $end - microtime(true)) > 0) {
            $p = $this->readPacket(min($left, 1.0));
            if ($p === null) continue;
            [$type, $body, $flags] = $p;
            if ($type !== 3) continue; // nur PUBLISH
            $len = unpack('n', substr($body, 0, 2))[1];
            $topic = substr($body, 2, $len);
            $off = 2 + $len + ((($flags >> 1) & 3) > 0 ? 2 : 0);
            $payload = substr($body, $off);
            $n = ($out[$topic][1] ?? 0) + 1;
            $out[$topic] = [$payload, $n, (bool) ($flags & 1)];
            if ($stopWhen && $stopWhen($out)) break;
        }
        return $out;
    }

    public function close(): void
    {
        if ($this->fp) { @fwrite($this->fp, "\xe0\x00"); fclose($this->fp); $this->fp = null; }
    }

    public function __destruct() { $this->close(); }

    // ------------------------------------------------------------------ intern

    private function str(string $s): string { return pack('n', strlen($s)) . $s; }

    private function send(int $header, string $body): void
    {
        $len = strlen($body); $enc = '';
        do { $b = $len % 128; $len = intdiv($len, 128); $enc .= chr($len > 0 ? $b | 0x80 : $b); } while ($len > 0);
        fwrite($this->fp, chr($header) . $enc . $body);
    }

    /** @return array{0:int,1:string,2:int}|null */
    private function readPacket(float $wait): ?array
    {
        $r = [$this->fp]; $w = $e = null;
        if (!@stream_select($r, $w, $e, (int) $wait, (int) (($wait - (int) $wait) * 1e6))) return null;
        $h = fread($this->fp, 1);
        if ($h === '' || $h === false) { if (feof($this->fp)) throw new RuntimeException('MQTT: Verbindung getrennt'); return null; }
        $mult = 1; $len = 0;
        do { $c = ord($this->readN(1)); $len += ($c & 127) * $mult; $mult *= 128; } while ($c & 128);
        $body = $len ? $this->readN($len) : '';
        return [ord($h) >> 4, $body, ord($h) & 0x0f];
    }

    private function readN(int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($this->fp, $n - strlen($buf));
            if ($chunk === '' || $chunk === false) { if (feof($this->fp)) throw new RuntimeException('MQTT: Verbindung getrennt'); usleep(10000); continue; }
            $buf .= $chunk;
        }
        return $buf;
    }
}
