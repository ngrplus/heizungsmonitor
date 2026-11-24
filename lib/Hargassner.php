<?php
/**
 * Minimaler Telnet-Leser für Hargassner-Kesselsteuerungen (Touchtronic u. a.).
 *
 * Die Steuerung sendet nach dem Verbindungsaufbau ungefragt ca. alle 500 ms
 * eine Zeile der Form:
 *     pm 1 1.2 7.5 54 0 52 6 6 64 -20 52 64 58 35 0 34 -20 ...
 * Die Bedeutung der Positionen hängt von Kesseltyp und Firmware ab
 * (siehe /mappings).
 */
date_default_timezone_set('Europe/Berlin');

class Hargassner
{
    /** Prüft, ob ein TCP-Port erreichbar ist. */
    public static function probe(string $host, int $port, float $timeout = 2.0): array
    {
        $t0 = microtime(true);
        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        if ($fp) {
            fclose($fp);
            return ['port' => $port, 'open' => true, 'ms' => $ms];
        }
        return ['port' => $port, 'open' => false, 'ms' => $ms, 'error' => self::utf8(trim($errstr)) ?: "Fehler $errno"];
    }

    /**
     * Verbindet sich, liest bis zu $seconds Sekunden und liefert die letzte
     * vollständige pm-Zeile plus alle übrigen empfangenen Zeilen.
     */
    public static function read(string $host, int $port, float $connectTimeout = 3, float $seconds = 2.5): array
    {
        $t0 = microtime(true);
        $errno = 0; $errstr = '';
        $fp = @fsockopen($host, $port, $errno, $errstr, $connectTimeout);
        if (!$fp) {
            return ['ok' => false, 'error' => 'Keine Verbindung: ' . (self::utf8(trim($errstr)) ?: "Fehler $errno")];
        }
        stream_set_blocking($fp, false);

        $buf = '';
        $bytes = 0;
        $pmLines = [];
        $otherLines = [];
        $deadline = $t0 + $seconds;

        while (microtime(true) < $deadline) {
            $r = [$fp]; $w = null; $e = null;
            $left = max(0.05, $deadline - microtime(true));
            $n = @stream_select($r, $w, $e, 0, (int) min(500000, $left * 1e6));
            if ($n === false) break;
            if ($n === 0) continue;
            $chunk = fread($fp, 8192);
            if ($chunk === '' || $chunk === false) {
                if (feof($fp)) break;
                continue;
            }
            $bytes += strlen($chunk);
            $buf .= self::stripTelnet($chunk, $fp);

            // vollständige Zeilen herauslösen
            while (preg_match('/^(.*?)\r?\n/s', $buf, $m)) {
                $buf = substr($buf, strlen($m[0]));
                $line = trim($m[1]);
                if ($line === '') continue;
                if (preg_match('/^pm\b/', $line)) {
                    $pmLines[] = $line;
                } else {
                    $otherLines[] = $line;
                }
            }
            // Zwei pm-Zeilen reichen: die zweite ist garantiert vollständig
            if (count($pmLines) >= 2) break;
        }
        fclose($fp);

        $res = [
            'ok'        => true,
            'elapsedMs' => (int) round((microtime(true) - $t0) * 1000),
            'bytes'     => $bytes,
            'pmCount'   => count($pmLines),
            'other'     => array_map([self::class, 'utf8'], array_slice($otherLines, -10)),
            'partial'   => self::utf8(substr($buf, 0, 500)),
        ];
        if ($pmLines) {
            $line = end($pmLines);
            $res['raw'] = self::utf8($line);
            $res['values'] = self::parse($line);
        } else {
            $res['ok'] = false;
            $res['error'] = $bytes
                ? 'Daten empfangen, aber keine pm-Zeile gefunden (siehe Rohdaten).'
                : 'Verbunden, aber innerhalb von ' . $seconds . ' s keine Daten empfangen.';
        }
        return $res;
    }

    /** Zerlegt eine pm-Zeile in eine Liste typisierter Werte. */
    public static function parse(string $line): array
    {
        $tokens = preg_split('/\s+/', trim($line));
        array_shift($tokens); // "pm"
        $out = [];
        foreach ($tokens as $i => $t) {
            if (preg_match('/^-?\d+$/', $t)) {
                $v = ['i' => $i, 'v' => (int) $t, 'type' => 'int'];
            } elseif (preg_match('/^-?\d*[.,]\d+$/', $t)) {
                $v = ['i' => $i, 'v' => (float) str_replace(',', '.', $t), 'type' => 'float'];
            } elseif (preg_match('/^[0-9A-Fa-f]{3,4}$/', $t) && preg_match('/[A-Fa-f]/', $t)) {
                // Statuswörter als Hex, z. B. 008B -> Bits mit ausgeben
                $d = hexdec($t);
                $v = ['i' => $i, 'v' => $t, 'type' => 'hex', 'dec' => $d,
                      'bits' => str_pad(decbin($d), 16, '0', STR_PAD_LEFT)];
            } else {
                $v = ['i' => $i, 'v' => self::utf8($t), 'type' => 'str'];
            }
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Entfernt Telnet-Steuersequenzen (IAC ...) und lehnt alle Optionen ab,
     * damit die Gegenstelle nicht auf eine Antwort wartet.
     */
    private static function stripTelnet(string $data, $fp): string
    {
        if (strpos($data, "\xFF") === false) return $data;
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            if ($c !== "\xFF") { $out .= $c; continue; }
            $cmd = $data[$i + 1] ?? '';
            if ($cmd === "\xFF") { $out .= "\xFF"; $i++; continue; }
            if (in_array($cmd, ["\xFB", "\xFC", "\xFD", "\xFE"], true)) { // WILL WONT DO DONT
                $opt = $data[$i + 2] ?? '';
                if ($cmd === "\xFD") @fwrite($fp, "\xFF\xFC" . $opt); // DO -> WONT
                if ($cmd === "\xFB") @fwrite($fp, "\xFF\xFE" . $opt); // WILL -> DONT
                $i += 2;
                continue;
            }
            if ($cmd === "\xFA") { // Subnegotiation bis IAC SE
                $end = strpos($data, "\xFF\xF0", $i);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            $i++; // sonstiger 2-Byte-Befehl
        }
        return $out;
    }

    private static function utf8(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'ISO-8859-1');
    }
}
