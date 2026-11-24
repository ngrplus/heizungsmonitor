<?php
/**
 * Sonneneinstrahlung von Open-Meteo (kostenlos, ohne Anmeldung), Viertelstundenwerte.
 * Werte = mittlere Globalstrahlung (W/m², waagerechte Fläche) der Viertelstunde, die zum Zeitstempel endet.
 * Die Daten werden in data/sonne.json zwischengespeichert und höchstens alle 15 min neu geholt.
 */
class Sonne
{
    private string $file;
    private array $cache;

    public function __construct(private array $cfg, private string $dataDir)
    {
        $this->file = "$dataDir/sonne.json";
        $c = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
        $this->cache = is_array($c) ? $c + ['werte' => [], 'geholt' => 0, 'fehler' => null] : ['werte' => [], 'geholt' => 0, 'fehler' => null];
    }

    /** Einstrahlung zum Zeitpunkt $ts in W/m², oder null, wenn keine aktuellen Daten vorliegen. */
    public function wert(int $ts): ?float
    {
        if (empty($this->cfg['enabled'])) return null;
        $fest = $this->cache['geholt'] === PHP_INT_MAX; // Werte über setzen() vorgegeben (Tests/Replay)
        $alter = $ts - (int) $this->cache['geholt'];
        if (!$fest && ($alter >= 15 * 60 || (!$this->hat($ts) && $alter >= 5 * 60))) $this->holen($ts);
        return $this->nachschlagen($ts);
    }

    public function fehler(): ?string { return $this->cache['fehler']; }

    /** Für Tests und Replays: Werte direkt vorgeben (Zeitstempel = Ende der Viertelstunde). */
    public function setzen(array $werte): void
    {
        foreach ($werte as $t => $w) $this->cache['werte'][(int) $t] = (float) $w;
        ksort($this->cache['werte']);
        $this->cache['geholt'] = PHP_INT_MAX;
    }

    private function hat(int $ts): bool
    {
        foreach ($this->cache['werte'] as $t => $w) if ($t >= $ts && $t - $ts <= 900) return true;
        return false;
    }

    private function nachschlagen(int $ts): ?float
    {
        $max = 60 * ($this->cfg['max_alter_min'] ?? 60);
        $best = null;
        foreach ($this->cache['werte'] as $t => $w) {
            if ($t >= $ts && $t - $ts <= 900) return (float) $w;           // laufende Viertelstunde
            if ($t < $ts && $ts - $t <= $max) $best = (float) $w;           // sonst letzter bekannter Wert
        }
        return $best;
    }

    private function holen(int $ts): void
    {
        $url = sprintf('https://api.open-meteo.com/v1/forecast?latitude=%.3F&longitude=%.3F&minutely_15=shortwave_radiation'
            . '&past_minutely_15=8&forecast_minutely_15=8&timeformat=unixtime&timezone=GMT', $this->cfg['lat'], $this->cfg['lon']);
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $raw = @file_get_contents($url, false, $ctx);
        $j = $raw ? json_decode($raw, true) : null;
        if (!isset($j['minutely_15']['time'], $j['minutely_15']['shortwave_radiation'])) {
            $this->cache['fehler'] = date('H:i', $ts) . ' Open-Meteo nicht erreichbar' . ($raw === false ? ' (' . (error_get_last()['message'] ?? '') . ')' : '');
            $this->cache['geholt'] = $ts - 10 * 60; // frühestens in 5 min erneut versuchen
            $this->speichern();
            return;
        }
        foreach ($j['minutely_15']['time'] as $i => $t) {
            $w = $j['minutely_15']['shortwave_radiation'][$i];
            if ($w !== null) $this->cache['werte'][(int) $t] = (float) $w;
        }
        // nur die letzten 2 Tage behalten
        $this->cache['werte'] = array_filter($this->cache['werte'], fn($t) => $t >= $ts - 2 * 86400, ARRAY_FILTER_USE_KEY);
        ksort($this->cache['werte']);
        $this->cache['geholt'] = $ts;
        $this->cache['fehler'] = null;
        $this->speichern();
    }

    private function speichern(): void
    {
        @file_put_contents($this->file, json_encode($this->cache));
    }
}
