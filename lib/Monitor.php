<?php
/**
 * Überwachungslogik:
 *  - Scheitholz brennt / Ausbrand: direkt aus dem Zustand des Scheitholzkessels in der Hargassner-Cloud
 *    (Zündung/Leistungsbrand -> Ausbrand). Nur wenn die Cloud keine aktuellen Daten liefert, indirekt über
 *    den Trend der mittleren Puffertemperatur (Ersatz).
 *    -> "Nachlegen"-Meldung, wenn der Puffer noch Platz hat und es Tag ist
 *  - Pelletkessel startet bald (Füllgrad sinkt) -> rechtzeitig Holz anheizen
 *  - Pelletkessel startet (nur Protokoll, zum Einstellen der Schwellen)
 *  Verbrauchsausgleich: Läuft die Heizung (Heizkreispumpen im Statuswort), zieht sie Wärme aus dem
 *  Puffer. Der dabei typische Temperaturabfall wird gelernt und vom Trend abgezogen ("Netto-Trend"),
 *  damit ein Holzfeuer auch bei laufender Heizung erkannt wird.
 *  Sonnenausgleich: Die Solarthermie lädt den Puffer bei Sonne. Aus der Einstrahlung (Open-Meteo) wird der
 *  erwartete Solarertrag geschätzt und ebenfalls vom Trend abgezogen.
 *  - Puffer kalt
 *  - Verbindung zur Kesselsteuerung verloren
 * Der Zustand liegt in data/monitor_state.json, damit Neustarts nichts verlieren.
 */
require_once __DIR__ . '/Version.php';

class Monitor
{
    private array $state;
    private string $stateFile;

    /** @param callable|null $notify fn(array $event): array  – Rückgabe = Versandergebnis */
    public function __construct(private array $cfg, private string $dataDir, private $notify = null)
    {
        if (!is_dir($dataDir)) mkdir($dataDir, 0777, true);
        // Pelletzähler und Kesselleistung (Pos. 44 Verbrauch gesamt kg, 43 Lagerstand kg, 20 Leistung %), auch wenn
        // monitor_config.php sie noch nicht kennt
        $this->cfg['fields'] += ['PVERBR' => 44, 'PLAGER' => 43, 'PLEIST' => 20];
        $this->stateFile = "$dataDir/monitor_state.json";
        $s = is_file($this->stateFile) ? json_decode((string) file_get_contents($this->stateFile), true) : null;
        $this->state = is_array($s) ? $s : [];
        $this->state += [
            'holz' => 'aus', 'holz_seit' => null, 'start_kand' => null, 'ende_kand' => null,
            'puffer_kalt' => false, 'puffer_niedrig' => false, 'pellet_aus_vorher' => null, 'pellet_aus_seit' => null, 'verbindung_alarm' => false,
            'holz_ende_ts' => null, 'holz_quelle' => null, 'pellet_lauf' => null, 'pellet_tage' => [], 'pellet_zuletzt_an' => null, 'pellet_an_ts' => null, 'heizung_vorher' => null, 'heizung_seit' => null, 'verbrauch' => ['k' => null, 'n' => 0],
            'last_ok' => null, 'last_tick' => null, 'recent' => [], 'current' => null,
        ];
    }

    public function state(): array { return $this->state; }

    /**
     * Ein Messzyklus. $values = Ergebnis von Hargassner::parse() oder null bei Lesefehler.
     * Liefert die in diesem Zyklus ausgelösten Ereignisse.
     */
    /**
     * @param float|null $sonne Globalstrahlung in W/m² (null = unbekannt)
     * @param array|null $hv    Scheitholzkessel aus der Hargassner-Cloud: zustand, rauchgas, kessel, soll, ts (null = unbekannt)
     */
    public function tick(?array $values, ?int $ts = null, string $error = '', ?float $sonne = null, ?array $hv = null): array
    {
        $ts ??= time();
        $events = [];
        $this->state['last_tick'] = $ts;
        $this->state['version'] = Version::NR; // Version, mit der dieser Monitor-Prozess gestartet wurde

        if ($values === null) {
            $events = array_merge($events, $this->checkConnection($ts, $error));
            // Ohne Daten vom Pelletkessel trotzdem Holzfeuer und Ausbrand über die Hargassner-Cloud melden
            // (18.11.: Kessel hatte eine neue IP, Cloud lief weiter). Pufferwerte sind dann unbekannt.
            if ($hv !== null && ($hv['zustand'] ?? null) !== null) {
                $hvVorher = $this->state['hv_zustand'] ?? null;
                $events = array_merge($events, $this->checkHvZustand($ts, $hv), $this->checkHolzCloud($ts, $hv, $hvVorher, []));
                if (is_array($this->state['current'])) $this->state['current']['hv'] = $hv;
            }
            $this->save();
            return $events;
        }

        if ($this->state['verbindung_alarm']) {
            $this->state['verbindung_alarm'] = false;
            $events[] = $this->event($ts, 'verbindung_ok', 'info', 'Verbindung wieder da',
                'Die Kesselsteuerung liefert wieder Daten.');
        }
        $this->state['last_ok'] = $ts;

        $v = $this->extract($values);
        $mean = $this->mean($v);
        $heizung = $this->heizungAktiv($v);
        if ($heizung !== $this->state['heizung_vorher']) { $this->state['heizung_vorher'] = $heizung; $this->state['heizung_seit'] = $ts; }
        $base = $this->verbrauchBasis($heizung);
        $solar = $this->solarErtrag($sonne);
        if ($mean !== null) {
            $this->state['recent'][] = [$ts, round($mean, 2), $base, $solar];
            $keep = $ts - 2 * 60 * $this->cfg['holz']['fenster_min'];
            $this->state['recent'] = array_values(array_filter($this->state['recent'], fn($r) => $r[0] >= $keep));
        }
        $slope = $this->slope($ts);
        $pelletAus = $this->pelletAus($v);
        if (!$pelletAus) $this->state['pellet_zuletzt_an'] = $ts;
        // Pelletkessel aus UND Nachlauf vorbei (nach dem Brand lädt er den Puffer noch eine Weile weiter)
        $pelletRuhig = $pelletAus && ($this->state['pellet_zuletzt_an'] === null
            || $ts - $this->state['pellet_zuletzt_an'] >= 60 * ($this->cfg['holz']['pellet_nachlauf_min'] ?? 30));
        $this->lerneVerbrauch($ts, $slope, $heizung, $pelletRuhig, $sonne);
        // Netto-Trend: gemessener Trend minus erwarteter Verbrauch und Solarertrag im selben Zeitfenster
        [$fVerbrauch, $fSolar] = $this->fensterBasis($ts);
        $fensterBasis = $fVerbrauch + $fSolar;
        $netto = $slope === null ? null : $slope - $fensterBasis;
        $sonneUnbekannt = !empty($this->cfg['sonne']['enabled']) && $sonne === null;

        $hvVorher = $this->state['hv_zustand'] ?? null;
        $cloud = $hv !== null && ($hv['zustand'] ?? null) !== null;
        $events = array_merge($events, $this->checkHvZustand($ts, $hv), $this->checkPelletStart($ts, $pelletAus, $v),
            $cloud ? $this->checkHolzCloud($ts, $hv, $hvVorher, $v)
                   : $this->checkHolz($ts, $netto, $pelletRuhig, $v, $fVerbrauch, $fSolar, $sonneUnbekannt),
            $this->checkPelletLauf($ts, $v, $pelletAus, $pelletRuhig), $this->checkPelletWarnung($ts, $v, $pelletRuhig), $this->checkAbend($ts, $v, $mean, $pelletRuhig), $this->checkPuffer($ts, $v));

        $this->state['current'] = $v + ['mittel' => $mean !== null ? round($mean, 1) : null,
            'trend' => $slope !== null ? round($slope, 2) : null, 'netto' => $netto !== null ? round($netto, 2) : null,
            'heizung' => $heizung, 'verbrauch' => round($base, 2), 'sonne' => $sonne, 'solar' => round($solar, 2), 'hv' => $hv,
            'pellet_aus' => $pelletAus, 'pellet_ruhig' => $pelletRuhig, 'holz_quelle' => $cloud ? 'cloud' : 'puffer', 'ts' => $ts];
        $this->appendHistory($ts, $v, $mean, $slope, $netto, $heizung, $sonne, $hv);
        $this->save();
        return $events;
    }

    /**
     * Pelletkessel aus? Über den Kesselzustand (Pos. 0), sonst über O2.
     * Nur 0 Initialisierung, 1 Aus und 8 Bereitschaft gelten als aus – auch 9 Halt für Entaschung, 10 Entaschung
     * und 12 Reinigung gehören noch zum Brand (Auswertung 22.10.–03.11.: der Puffer lädt dabei weiter).
     */
    private function pelletAus(array $v): bool
    {
        $c = $this->cfg['holz'];
        if (isset($v['ZK'])) {
            return in_array((int) $v['ZK'], $c['pellet_aus_zustaende'] ?? [0, 1, 8], true);
        }
        return isset($v['O2']) && $v['O2'] >= ($c['pellet_aus_ab_o2'] ?? 20);
    }

    /** @param float|null $slope Netto-Trend (gemessener Trend minus erwarteter Verbrauch) */
    private function checkHolz(int $ts, ?float $slope, bool $pelletAus, array $v, float $verbrauch = 0.0,
                               float $solar = 0.0, bool $sonneUnbekannt = false): array
    {
        $c = $this->cfg['holz'];
        $s = &$this->state;
        $out = [];
        if ($slope === null) return $out; // zu wenig Verlauf
        // Bei Ausgleich: tatsächlichen Pufferanstieg und die abgezogenen Anteile mit angeben
        $teile = [];
        if (abs($verbrauch) > 0.05) $teile[] = sprintf('Heizung zieht ca. %.1f K/h ab', -$verbrauch);
        if (abs($solar) > 0.05) $teile[] = sprintf('Sonne bringt ca. %.1f K/h', $solar);
        $netto = $teile ? sprintf(' netto; Puffer selbst %+.1f K/h, %s', $slope + $verbrauch + $solar, implode(', ', $teile)) : '';

        if ($s['holz'] === 'aus') {
            // Nach einem Ausbrand schickt der Holzkessel noch Restwärme-Stöße -> höhere Schwelle
            $schwelle = $c['start_k_pro_h'];
            // Ohne aktuelle Sonnendaten kann Solarertrag nicht abgezogen werden -> vorsichtige Schwelle
            if ($sonneUnbekannt) $schwelle = max($schwelle, $this->cfg['sonne']['start_ohne_sonnendaten'] ?? $schwelle);
            if ($s['holz_ende_ts'] !== null && $ts - $s['holz_ende_ts'] < 60 * ($c['restwaerme_sperre_min'] ?? 0)) {
                $schwelle *= $c['restwaerme_faktor'] ?? 1;
            }
            if ($pelletAus && $slope >= $schwelle) {
                $s['start_kand'] ??= $ts;
                if ($ts - $s['start_kand'] >= 60 * $c['start_bestaetigen_min']) {
                    $s['holz'] = 'brennt';
                    $s['holz_seit'] = $s['start_kand'];
                    $s['holz_quelle'] = 'puffer';
                    $s['start_kand'] = null;
                    $s['ende_kand'] = null;
                    $out[] = $this->event($ts, 'holz_start', 'info', 'Scheitholzkessel heizt',
                        sprintf('Puffer steigt um %.1f K/h%s. Puffer oben %s °C.', $slope, $netto, $this->fmt($v['TPO'] ?? null)));
                }
            } else {
                $s['start_kand'] = null;
            }
            return $out;
        }

        // Zustand "brennt"
        $dauerMin = (int) round(($ts - $s['holz_seit']) / 60);
        if (!$pelletAus) {
            $s['holz'] = 'aus'; $s['ende_kand'] = null; $s['holz_ende_ts'] = $ts;
            if ($dauerMin >= $c['min_brenndauer_min']) {
                $out[] = $this->ausbrand($ts, $v, $dauerMin, 'Der Pelletkessel hat übernommen.');
            }
            return $out;
        }
        if ($slope <= $c['ende_k_pro_h']) {
            $s['ende_kand'] ??= $ts;
            if ($ts - $s['ende_kand'] >= 60 * $c['ende_bestaetigen_min']) {
                $s['holz'] = 'aus'; $s['ende_kand'] = null; $s['holz_ende_ts'] = $ts;
                if ($dauerMin >= $c['min_brenndauer_min']) {
                    $out[] = $this->ausbrand($ts, $v, $dauerMin, sprintf('Puffer steigt nicht mehr (%.1f K/h%s).', $slope, $netto));
                }
            }
        } else {
            $s['ende_kand'] = null;
        }
        return $out;
    }

    /** Ausbrand-Ereignis: Push nur, wenn der Puffer noch Platz hat und es im Zeitfenster liegt. */
    private function ausbrand(int $ts, array $v, int $dauerMin, string $grund): array
    {
        $max = $this->cfg['nachlegen']['puffer_unten_max'];
        $tpu = $v['TPU'] ?? null;
        $info = sprintf('%s Abbrand ca. %s. Puffer unten %s °C, oben %s °C, Füllgrad %s %%.',
            $grund, $this->dauer($dauerMin), $this->fmt($tpu), $this->fmt($v['TPO'] ?? null), $this->fmt($v['PFD'] ?? null));
        $halten = null;
        if ($tpu === null) $info = sprintf('%s Abbrand ca. %s. Keine Pufferwerte (Pelletkessel liefert gerade keine Daten) – bitte selbst prüfen, ob im Puffer noch Platz ist.',
            $grund, $this->dauer($dauerMin));
        if ($tpu !== null && $tpu >= $max) $halten = sprintf('Puffer unten %s °C ≥ %s °C – kein Platz zum Nachlegen', $this->fmt($tpu), $max);
        elseif (!$this->imZeitfenster($ts)) $halten = 'außerhalb ' . $this->zeitfensterText();
        return $halten === null
            ? $this->event($ts, 'holz_ende', 'alarm', 'Scheitholz brennt aus – jetzt nachlegen',
                $info . ($tpu !== null ? sprintf(' Im Puffer ist noch Platz (unten unter %s °C).', $max) : ''))
            : $this->event($ts, 'holz_ende', 'info', 'Scheitholz ausgebrannt', $info, $halten);
    }

    /**
     * Holzfeuer und Ausbrand aus dem Zustand des Scheitholzkessels in der Hargassner-Cloud.
     * Ablauf laut Cloud: Aus -> Zündung -> Leistungsbrand -> Ausbrand -> Restwärme -> Aus.
     * Ausbrand-Meldung beim Verlassen des Leistungsbrands; eine abgebrochene Zündung meldet nichts.
     */
    private function checkHolzCloud(int $ts, array $hv, ?string $vorher, array $v): array
    {
        $s = &$this->state;
        $z = $hv['zustand'];
        $brennt = in_array($z, self::HV_BRENNT, true);
        $s['start_kand'] = null; $s['ende_kand'] = null;
        $werte = sprintf('Rauchgas %s °C, Kessel %s °C', $this->fmt($hv['rauchgas'] ?? null), $this->fmt($hv['kessel'] ?? null));
        if ($s['holz'] === 'aus') {
            if (!$brennt) return [];
            // Glut flammt nach dem Ausbrand oft noch einmal kurz auf (07.11.: 3 × 10–15 min Leistungsbrand nach dem Ausbrand).
            // Ohne Zündung kurz nach einem Ausbrand: still mitzählen; gemeldet wird erst, wenn es länger brennt (= nachgelegt).
            $nach = $s['holz_ende_ts'] !== null && $ts - (int) $s['holz_ende_ts'] < 60 * ($this->cfg['holz']['nachflammen_min'] ?? 60)
                && $z !== 'STATE_IGNITION';
            $s['holz'] = 'brennt'; $s['holz_seit'] = $ts; $s['holz_quelle'] = 'cloud'; $s['holz_nachflammen'] = $nach;
            if ($nach) return [];
            return [$this->event($ts, 'holz_start', 'info', 'Scheitholzkessel heizt',
                sprintf('Hargassner-Cloud: %s. %s, Puffer oben %s °C.', self::hvText($z), $werte, $this->fmt($v['TPO'] ?? null)))];
        }
        if ($brennt) { $s['holz_quelle'] = 'cloud'; return []; }
        // Feuer vorbei (bzw. war laut Cloud nie an, z. B. Fehlerkennung über den Puffer vor dem Umschalten)
        $dauerMin = (int) round(($ts - (int) $s['holz_seit']) / 60);
        $nach = !empty($s['holz_nachflammen']);
        $s['holz'] = 'aus'; $s['holz_quelle'] = 'cloud'; $s['holz_nachflammen'] = false;
        if (!$nach) $s['holz_ende_ts'] = $ts; // Sperrzeit läuft ab dem eigentlichen Ausbrand
        if ($vorher !== 'STATE_EFFICIENCY_FIRE') return [];
        // kurzes Aufflammen der Glut: keine zweite Meldung
        if ($nach && $dauerMin < (int) ($this->cfg['holz']['min_brenndauer_min'] ?? 30)) return [];
        if ($nach) $s['holz_ende_ts'] = $ts;
        return [$this->ausbrand($ts, $v, $dauerMin, sprintf('Hargassner-Cloud meldet %s (%s).', self::hvText($z), $werte))];
    }

    /**
     * Füllgrad sinkt -> bald startet der Pelletkessel (er startet bei Puffer oben ca. 55 °C, Füllgrad dann 62–73 %).
     * Hinweis zum rechtzeitigen Anheizen mit Holz. Außerhalb des Zeitfensters wird gewartet.
     */
    private function checkPelletWarnung(int $ts, array $v, bool $pelletRuhig): array
    {
        $c = ($this->cfg['pellet_warnung'] ?? []) + ['fuellgrad_unter' => 82, 'zurueck_ab' => 95, 'puffer_oben_bis' => 57];
        $pfd = $v['PFD'] ?? null;
        $tpo = $v['TPO'] ?? null;
        if ($pfd === null) return [];
        // wieder scharf, wenn der Puffer neu geladen ist (Füllgrad hoch UND oben wieder deutlich über der Schwelle)
        if ($this->state['puffer_niedrig'] && $pfd >= $c['zurueck_ab'] && ($tpo === null || $tpo >= $c['puffer_oben_bis'] + 5)) {
            $this->state['puffer_niedrig'] = false;
        }
        // Zwei Anzeichen (Auswertung 23.10.–07.11.): bei geschichtetem Puffer sinkt der Füllgrad früh; nach einem Holzfeuer
        // ist der Puffer durchmischt, der Füllgrad steht beim Pelletstart noch bei 91 % – dann warnt Puffer oben ≤ 57 °C.
        $fg = $pfd < $c['fuellgrad_unter'];
        $oben = $tpo !== null && $tpo <= $c['puffer_oben_bis'];
        // nicht melden, wenn schon geheizt wird (Holz brennt oder Pelletkessel läuft / lädt noch nach)
        if ($this->state['puffer_niedrig'] || !($fg || $oben) || $this->state['holz'] === 'brennt' || !$pelletRuhig) return [];
        if (!$this->imZeitfenster($ts)) return []; // wird zu Beginn des Zeitfensters gemeldet, falls dann noch nötig
        $this->state['puffer_niedrig'] = true;
        $grund = $fg ? sprintf('Füllgrad %s %% (Schwelle %s %%), Puffer oben %s °C.', $this->fmt($pfd), $c['fuellgrad_unter'], $this->fmt($tpo))
                     : sprintf('Puffer oben nur noch %s °C (Schwelle %s °C), Füllgrad %s %%.', $this->fmt($tpo), $c['puffer_oben_bis'], $this->fmt($pfd));
        return [$this->event($ts, 'puffer_niedrig', 'alarm', 'Bald startet der Pelletkessel – jetzt Holz anheizen',
            $grund . ' Der Pelletkessel startet bei Puffer oben ca. 55 °C – das Holzfeuer braucht ca. 40 min, bis Wärme im Puffer ankommt.')];
    }

    /**
     * Pelletverbrauch je Brennzeit über den Verbrauchszähler (Pos. 44, ganze kg). Eine Brennzeit endet erst, wenn der
     * Kessel 30 min aus ist (Entaschung/Neustart dazwischen gehören dazu). Außerdem Tagessummen für das Dashboard.
     */
    private function checkPelletLauf(int $ts, array $v, bool $pelletAus, bool $pelletRuhig): array
    {
        $s = &$this->state;
        $z = isset($v['PVERBR']) && $v['PVERBR'] > 0 ? (float) $v['PVERBR'] : null;
        if ($z !== null) {
            $tag = date('Y-m-d', $ts);
            $t = $s['pellet_tage'][$tag] ?? ['start' => $z, 'kg' => 0];
            $t['kg'] = max(0, min(500, $z - $t['start']));
            $s['pellet_tage'][$tag] = $t;
            $s['pellet_tage'] = array_slice($s['pellet_tage'], -14, null, true);
        }
        $l = &$s['pellet_lauf'];
        if (!$pelletAus && $l === null) {
            $l = ['start' => $ts, 'zaehler' => $z, 'lager' => $v['PLAGER'] ?? null];
            return [];
        }
        if ($l === null || !$pelletRuhig) return [];
        $ende = (int) ($s['pellet_zuletzt_an'] ?? $ts);
        $kg = ($z !== null && $l['zaehler'] !== null) ? $z - $l['zaehler'] : null;
        if ($kg !== null && ($kg < 0 || $kg > 200)) $kg = null; // Zähler zurückgesetzt o. ä.
        $min = (int) round(($ende - $l['start']) / 60);
        $lager = $v['PLAGER'] ?? null;
        $l = null;
        if ($min < 5) return [];
        $f = "$this->dataDir/pellet_brennzeiten.csv";
        if (!is_file($f)) file_put_contents($f, "start;ende;dauer_min;kg;lager_kg\n");
        file_put_contents($f, sprintf("%s;%s;%d;%s;%s\n", date('Y-m-d H:i', $ende - $min * 60),
            date('Y-m-d H:i', $ende), $min, $kg ?? '', $lager ?? ''), FILE_APPEND);
        return [$this->event($ts, 'pellet_ende', 'info', 'Pelletkessel aus' . ($kg !== null ? " – $kg kg" : ''),
            sprintf('Brennzeit %s–%s Uhr (%s), Verbrauch %s, Lagerstand %s kg.', date('H:i', $ende - $min * 60), date('H:i', $ende),
                $this->dauer($min), $kg !== null ? "ca. $kg kg" : 'unbekannt', $this->fmt($lager)))];
    }

    /** Einstellungen der Abend-Prüfung mit Standardwerten (aus der Auswertung 23.10.–04.11.26). */
    public static function abendCfg(array $cfg): array
    {
        return ($cfg['abend'] ?? []) + [
            'enabled'        => true,
            'um'             => '21:00',  // Prüfzeit – danach bleibt noch Zeit zum Anheizen vor 22 Uhr
            'morgen'         => '06:15',  // dann laufen die Heizkreise an und ziehen kräftig Wärme
            'verlust_k_pro_h'=> 1.5,      // Abkühlung Puffer-Mittel bis zum Morgen (gemessen 1,0–1,7 K/h)
            'mittel_min'     => 60,       // darunter springt der Pelletkessel morgens vor ca. 7:40 an
        ];
    }

    /**
     * Abend-Prüfung (einmal am Tag zur Prüfzeit): Reicht der Puffer bis morgen früh? Sonst springt der Pelletkessel an,
     * bevor jemand Holz anheizen kann -> Hinweis, noch am Abend anzuheizen.
     * Kein Hinweis, wenn Holz brennt oder der Pelletkessel gerade lädt bzw. nachlädt (dann startet er morgens meist später).
     */
    private function checkAbend(int $ts, array $v, ?float $mean, bool $pelletRuhig): array
    {
        $c = self::abendCfg($this->cfg);
        $tag = date('Y-m-d', $ts);
        $jetzt = date('H:i', $ts);
        $bis = date('H:i', strtotime("$tag {$c['um']}") + 3600);
        if (empty($c['enabled']) || $mean === null || ($this->state['abend']['tag'] ?? null) === $tag || $jetzt < $c['um'] || $jetzt >= $bis) return [];
        $morgen = strtotime("$tag {$c['morgen']}") + 86400;
        $prognose = $mean - $c['verlust_k_pro_h'] * ($morgen - $ts) / 3600;
        $grund = $this->state['holz'] === 'brennt' ? 'Holz brennt' : (!$pelletRuhig ? 'Pelletkessel lädt' : null);
        $hinweis = $grund === null && $prognose < $c['mittel_min'];
        $this->state['abend'] = ['tag' => $tag, 'ts' => $ts, 'mittel' => round($mean, 1), 'prognose' => round($prognose, 1),
                                 'ergebnis' => $hinweis ? 'Hinweis gesendet' : ($grund ?? 'reicht bis morgen')];
        if (!$hinweis) return [];
        return [$this->event($ts, 'abend_anheizen', 'alarm', 'Heute Abend noch Holz anheizen?',
            sprintf('Puffer-Mittel jetzt %s °C, morgen um %s voraussichtlich nur noch ca. %s °C. Dann springt der Pelletkessel '
                . 'erfahrungsgemäß früh am Morgen an (vor ca. 7:30). Ein Holzfeuer jetzt reicht bis in den Vormittag.',
                $this->fmt($mean), $c['morgen'], $this->fmt($prognose)))];
    }

    /** Zustandswechsel des Scheitholzkessels laut Hargassner-Cloud protokollieren (Grundlage für die direkte Erkennung). */
    private function checkHvZustand(int $ts, ?array $hv): array
    {
        if (!$hv || ($hv['zustand'] ?? null) === null) return [];
        $alt = $this->state['hv_zustand'] ?? null;
        $this->state['hv_zustand'] = $hv['zustand'];
        if ($alt === null || $alt === $hv['zustand']) return [];
        return [$this->event($ts, 'hv_zustand', 'info', 'Scheitholzkessel: ' . self::hvText($hv['zustand']),
            sprintf('Zustand %s → %s. Rauchgas %s °C, Kessel %s °C (Hargassner-Cloud).', self::hvText($alt), self::hvText($hv['zustand']),
                $this->fmt($hv['rauchgas'] ?? null), $this->fmt($hv['kessel'] ?? null)))];
    }

    /** Push für neuere Meldungen, solange monitor_config.php sie nicht selbst regelt */
    private const PUSH_STANDARD = ['abend_anheizen' => true];

    /** Cloud-Zustände, in denen das Holz brennt */
    private const HV_BRENNT = ['STATE_IGNITION', 'STATE_EFFICIENCY_FIRE'];

    public static function hvText(?string $z): string
    {
        return ['STATE_OFF' => 'Aus', 'STATE_IGNITION' => 'Zündung', 'STATE_EFFICIENCY_FIRE' => 'Leistungsbrand',
                'STATE_BURN_OUT' => 'Ausbrand', 'STATE_RESIDUAL_HEATING' => 'Restwärme',
                'STATE_DEASHING' => 'Entaschung'][$z] ?? (string) $z;
    }

    /** Protokolliert, bei welchem Füllgrad der Pelletkessel startet (Erfahrungswert für die Schwelle). */
    private function checkPelletStart(int $ts, bool $pelletAus, array $v): array
    {
        $vorher = $this->state['pellet_aus_vorher'];
        $ausSeit = $this->state['pellet_aus_seit'];
        $this->state['pellet_aus_vorher'] = $pelletAus;
        if ($pelletAus) { $this->state['pellet_aus_seit'] ??= $ts; return []; }
        if ($vorher === true) $this->state['pellet_an_ts'] = $ts; // jeder Start, auch kurz nacheinander
        $this->state['pellet_aus_seit'] = null;
        // nur echte Starts nach längerer Pause protokollieren (kein Takten/Pendeln)
        if ($vorher !== true || $ausSeit === null || $ts - $ausSeit < 60 * ($this->cfg['pellet_start_pause_min'] ?? 30)) return [];
        return [$this->event($ts, 'pellet_start', 'info', 'Pelletkessel startet',
            sprintf('Füllgrad %s %%, Puffer oben %s °C, mitte %s °C, unten %s °C.', $this->fmt($v['PFD'] ?? null),
                $this->fmt($v['TPO'] ?? null), $this->fmt($v['TPM'] ?? null), $this->fmt($v['TPU'] ?? null)))];
    }

    /** Läuft eine Heizkreispumpe? null = unbekannt (Statuswort fehlt). */
    private function heizungAktiv(array $v): ?bool
    {
        if (!isset($v['PUMPEN'])) return null;
        foreach ($this->cfg['verbrauch']['heizung_pumpen_bits'] ?? [] as $bit) {
            if (((int) $v['PUMPEN'] >> $bit) & 1) return true;
        }
        return false;
    }

    /** Erwarteter Temperaturverlauf des Puffers ohne Holzfeuer (K/h, negativ = Verbrauch). */
    private function verbrauchBasis(?bool $heizung): float
    {
        $c = $this->cfg['verbrauch'] ?? [];
        if (empty($c['ausgleich']) || $heizung !== true) return 0.0;
        $l = $this->state['verbrauch'];
        return ($l['k'] !== null && $l['n'] >= ($c['lern_min'] ?? 120)) ? (float) $l['k'] : (float) ($c['standard_k_pro_h'] ?? 0);
    }

    /** Mittlerer erwarteter Verbrauch und Solarertrag über das Trendfenster: [verbrauch, solar] in K/h. */
    private function fensterBasis(int $ts): array
    {
        $win = 60 * $this->cfg['holz']['fenster_min'];
        $r = array_filter($this->state['recent'], fn($x) => $x[0] >= $ts - $win);
        if (!$r) return [0.0, 0.0];
        $n = count($r);
        return [array_sum(array_map(fn($x) => $x[2] ?? 0.0, $r)) / $n, array_sum(array_map(fn($x) => $x[3] ?? 0.0, $r)) / $n];
    }

    /** Geschätzter Solarertrag in K/h aus der Globalstrahlung (W/m²). */
    private function solarErtrag(?float $sonne): float
    {
        $c = $this->cfg['sonne'] ?? [];
        if (empty($c['enabled']) || empty($c['ausgleich']) || $sonne === null) return 0.0;
        return max(0.0, $sonne - ($c['ab_w_m2'] ?? 150)) / 100 * ($c['k_pro_h_je_100w'] ?? 1.0);
    }

    /** Lernt den Verbrauch bei laufender Heizung aus Zeiten ohne Holz- und Pelletfeuer. */
    private function lerneVerbrauch(int $ts, ?float $slope, ?bool $heizung, bool $pelletRuhig, ?float $sonne = null): void
    {
        $c = $this->cfg['verbrauch'] ?? [];
        $s = &$this->state;
        if (empty($c['lernen']) || $slope === null || $heizung !== true || !$pelletRuhig || $s['holz'] !== 'aus') return;
        // nur ohne Sonne lernen (sonst wäre der Solarertrag im "Verbrauch" enthalten)
        if (!empty($this->cfg['sonne']['enabled']) && ($sonne === null || $sonne >= ($this->cfg['sonne']['ab_w_m2'] ?? 150) / 2)) return;
        if ($s['start_kand'] !== null || $slope > 1.0) return;                                   // könnte Feuer sein
        if ($ts - (int) $s['heizung_seit'] < 60 * $this->cfg['holz']['fenster_min']) return;     // Fenster noch gemischt
        if ($s['pellet_aus_seit'] === null || $ts - $s['pellet_aus_seit'] < 60 * ($c['nach_pellet_min'] ?? 60)) return;
        if ($s['holz_ende_ts'] !== null && $ts - $s['holz_ende_ts'] < 60 * ($c['nach_ausbrand_min'] ?? 120)) return;
        $alpha = 1 / max(1, $c['ema_min'] ?? 180);
        $k = max(-15.0, min(0.0, $slope));
        $s['verbrauch']['k'] = $s['verbrauch']['k'] === null ? $k : $s['verbrauch']['k'] + $alpha * ($k - $s['verbrauch']['k']);
        $s['verbrauch']['n']++;
    }

    private function imZeitfenster(int $ts): bool
    {
        $z = $this->cfg['zeitfenster'] ?? null;
        if (!$z) return true;
        $t = date('H:i', $ts);
        return $z['von'] <= $z['bis'] ? ($t >= $z['von'] && $t < $z['bis']) : ($t >= $z['von'] || $t < $z['bis']);
    }

    private function zeitfensterText(): string
    {
        $z = $this->cfg['zeitfenster'] ?? null;
        return $z ? "{$z['von']}–{$z['bis']} Uhr" : 'immer';
    }

    private function checkPuffer(int $ts, array $v): array
    {
        $c = $this->cfg['puffer'];
        $t = $v[$c['fuehler']] ?? null;
        if ($t === null) return [];
        // direkt nach einem Pelletstart fällt Puffer oben kurz unter die Schwelle – das ist normal
        $kurzNachStart = $this->state['pellet_an_ts'] !== null && $ts - $this->state['pellet_an_ts'] < 60 * ($c['nach_pelletstart_min'] ?? 30);
        if (!$this->state['puffer_kalt'] && $t < $c['kalt_unter'] && !$kurzNachStart) {
            $this->state['puffer_kalt'] = true;
            return [$this->event($ts, 'puffer_kalt', 'alarm', 'Puffer kühlt ab',
                sprintf('Puffer oben nur noch %s °C (Schwelle %s °C), Füllgrad %s %%.', $this->fmt($t), $c['kalt_unter'], $this->fmt($v['PFD'] ?? null)))];
        }
        if ($this->state['puffer_kalt'] && $t > $c['kalt_zurueck']) {
            $this->state['puffer_kalt'] = false;
            return [$this->event($ts, 'puffer_ok', 'info', 'Puffer wieder warm', sprintf('Puffer oben %s °C.', $this->fmt($t)))];
        }
        return [];
    }

    private function checkConnection(int $ts, string $error): array
    {
        $last = $this->state['last_ok'];
        if ($this->state['verbindung_alarm'] || $last === null) return [];
        if ($ts - $last >= 60 * $this->cfg['verbindung_alarm_min']) {
            $this->state['verbindung_alarm'] = true;
            return [$this->event($ts, 'verbindung_weg', 'alarm', 'Keine Daten von der Heizung',
                'Seit ' . date('H:i', $last) . ' keine Daten. ' . $error)];
        }
        return [];
    }

    // ---------------------------------------------------------------- Hilfen

    private function extract(array $values): array
    {
        $byIdx = [];
        foreach ($values as $x) $byIdx[$x['i']] = $x['v'];
        $out = [];
        foreach ($this->cfg['fields'] as $name => $i) {
            if (isset($byIdx[$i]) && is_numeric($byIdx[$i])) $out[$name] = (float) $byIdx[$i];
        }
        // Statuswörter sind Hex-Werte (auch wenn sie nur Ziffern enthalten, z. B. "40" = 64)
        foreach ($this->cfg['hex_fields'] ?? [] as $name => $i) {
            if (isset($byIdx[$i]) && preg_match('/^[0-9A-Fa-f]+$/', (string) $byIdx[$i])) $out[$name] = hexdec((string) $byIdx[$i]);
        }
        return $out;
    }

    private function mean(array $v): ?float
    {
        $t = array_filter([$v['TPO'] ?? null, $v['TPM'] ?? null, $v['TPU'] ?? null], fn($x) => $x !== null && $x > -19);
        return $t ? array_sum($t) / count($t) : null;
    }

    /** Steigung der mittleren Puffertemperatur im Fenster, in K/h (lineare Regression). */
    private function slope(int $ts): ?float
    {
        $win = 60 * $this->cfg['holz']['fenster_min'];
        $pts = array_values(array_filter($this->state['recent'], fn($r) => $r[0] >= $ts - $win));
        if (count($pts) < 5 || ($pts[count($pts) - 1][0] - $pts[0][0]) < 0.7 * $win) return null;
        $n = count($pts);
        $mx = array_sum(array_column($pts, 0)) / $n;
        $my = array_sum(array_column($pts, 1)) / $n;
        $num = $den = 0.0;
        foreach ($pts as [$x, $y]) { $num += ($x - $mx) * ($y - $my); $den += ($x - $mx) ** 2; }
        return $den > 0 ? $num / $den * 3600 : null;
    }

    /** @param string|null $halten Grund, warum keine Push-Nachricht geschickt wird */
    private function event(int $ts, string $type, string $level, string $title, string $text, ?string $halten = null): array
    {
        $e = ['ts' => $ts, 'zeit' => date('Y-m-d H:i:s', $ts), 'type' => $type, 'level' => $level,
              'title' => $title, 'text' => $text];
        if ($halten === null && !($this->cfg['push'][$type] ?? (self::PUSH_STANDARD[$type] ?? false))) $halten = 'nur Protokoll';
        if ($halten === null && ($p = self::pause($this->dataDir, $ts))) $halten = 'Wartungspause bis ' . date('d.m. H:i', $p['bis']);
        if ($halten !== null) $e['versand'] = ['kein Push' => $halten];
        elseif ($this->notify) $e['versand'] = ($this->notify)($e);
        file_put_contents("$this->dataDir/events.jsonl", json_encode($e, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
        return $e;
    }

    private function appendHistory(int $ts, array $v, ?float $mean, ?float $slope, ?float $netto, ?bool $heizung, ?float $sonne = null, ?array $hv = null): void
    {
        $file = "$this->dataDir/history_" . date('Y-m-d', $ts) . '.csv';
        $cols = ['ZK', 'O2', 'TK', 'TRG', 'TPO', 'TPM', 'TPU', 'PFD', 'TAUS', 'HK1_VL', 'HK1_VLS', 'HK2_VL', 'HK2_VLS', 'WW_TAG', 'WW_PUMPE', 'PVERBR', 'PLAGER', 'PLEIST'];
        $head = "ts;zeit;" . implode(';', $cols) . ";PUMPEN;SONNE;HV_ZUSTAND;HV_RAUCHGAS;HV_KESSEL;mittel;trend;netto;heizung;holz";
        if (is_file($file)) {
            // Spalten haben sich geändert -> alte Datei beiseitelegen, neue beginnen
            $fh = fopen($file, 'r'); $first = rtrim((string) fgets($fh)); fclose($fh);
            if ($first !== $head) rename($file, substr($file, 0, -4) . '_' . date('His', $ts) . '.csv');
        }
        if (!is_file($file)) file_put_contents($file, "$head\n");
        $row = [$ts, date('H:i:s', $ts)];
        foreach ($cols as $c) $row[] = $v[$c] ?? '';
        $row[] = isset($v['PUMPEN']) ? strtoupper(dechex((int) $v['PUMPEN'])) : '';
        $row[] = $sonne === null ? '' : round($sonne);
        $row[] = $hv['zustand'] ?? '';
        $row[] = $hv['rauchgas'] ?? '';
        $row[] = $hv['kessel'] ?? '';
        $row[] = $mean !== null ? round($mean, 2) : '';
        $row[] = $slope !== null ? round($slope, 2) : '';
        $row[] = $netto !== null ? round($netto, 2) : '';
        $row[] = $heizung === null ? '' : (int) $heizung;
        $row[] = $this->state['holz'];
        file_put_contents($file, implode(';', $row) . "\n", FILE_APPEND);
    }

    private function save(): void
    {
        file_put_contents($this->stateFile, json_encode($this->state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    private function fmt($x): string { return $x === null ? '?' : (string) round((float) $x, 1); }
    private function dauer(int $min): string { return $min >= 60 ? sprintf('%d h %02d min', intdiv($min, 60), $min % 60) : "$min min"; }

    /** Verlauf von $hours Stunden bis $bis (Standard: jetzt) für das Dashboard. */
    public static function history(string $dataDir, int $hours = 24, int $maxPoints = 720, ?int $bis = null): array
    {
        $bis ??= time();
        $from = $bis - $hours * 3600;
        $rows = [];
        $days = [];
        for ($d = $from; $d < $bis + 86400; $d += 86400) $days[date('Y-m-d', $d)] = true;
        $days[date('Y-m-d', $bis)] = true;
        foreach (array_keys($days) as $day) {
          foreach (glob("$dataDir/history_$day*.csv") ?: [] as $f) {
            $lines = file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $head = explode(';', array_shift($lines));
            foreach ($lines as $l) {
                $r = array_combine($head, array_pad(explode(';', $l), count($head), ''));
                if ((int) $r['ts'] >= $from && (int) $r['ts'] <= $bis) $rows[(int) $r['ts']] = $r;
            }
          }
        }
        ksort($rows);
        $rows = array_values($rows);
        $step = max(1, (int) ceil(count($rows) / $maxPoints));
        return $step > 1 ? array_values(array_filter($rows, fn($i) => $i % $step === 0, ARRAY_FILTER_USE_KEY)) : $rows;
    }

    /** Beginn der Aufzeichnung (ältester Tag mit Verlaufsdatei) als Zeitstempel, oder null. */
    public static function historyStart(string $dataDir): ?int
    {
        $f = glob("$dataDir/history_*.csv") ?: [];
        sort($f);
        return $f && preg_match('/history_(\d{4}-\d{2}-\d{2})/', $f[0], $m) ? strtotime($m[1]) : null;
    }

    /** Aktive Wartungspause (keine Push-Nachrichten) oder null. */
    public static function pause(string $dataDir, ?int $ts = null): ?array
    {
        $f = "$dataDir/pause.json";
        if (!is_file($f)) return null;
        $p = json_decode((string) file_get_contents($f), true);
        return (is_array($p) && ($p['bis'] ?? 0) > ($ts ?? time())) ? $p : null;
    }

    public static function setPause(string $dataDir, ?int $bis, string $grund = ''): void
    {
        $f = "$dataDir/pause.json";
        if ($bis === null) { @unlink($f); return; }
        file_put_contents($f, json_encode(['bis' => $bis, 'grund' => $grund, 'gesetzt' => time()], JSON_UNESCAPED_UNICODE));
    }

    public static function events(string $dataDir, int $limit = 50): array
    {
        $f = "$dataDir/events.jsonl";
        if (!is_file($f)) return [];
        $lines = array_slice(file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$limit);
        return array_reverse(array_map(fn($l) => json_decode($l, true), $lines));
    }
}
