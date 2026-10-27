<?php
/**
 * MONITOR RYNKU — globalne wykrywanie anomalii arkusza, wyceny i płynności.
 *
 * Po co: anomalie rynku (np. oferty sprzedaży 9× ponad kursem, pusta strona kupna, kurs daleko od wyceny,
 * animatorzy bez gotówki) gracz widzi na jednej spółce i nikt o nich nie wie, dopóki ktoś nie zrobi zrzutu ekranu.
 * Monitor co EVERY ticków (z crona, POZA transakcją ticka — tylko czyta) przegląda wszystkie spółki, a każdą
 * NOWĄ anomalię zapisuje w dzienniku (source='monitor', poziom warn) z kompletem liczb. Ta sama anomalia na tej
 * samej spółce nie jest powtarzana przez DEDUP ticków, żeby dziennik nie zalał się kopiami.
 * Zbiorcze liczby lądują w game_state.monitor_summary i w publicznym health.php, więc stan rynku produkcji widać
 * w health checku GitHuba bez logowania (żadnych danych graczy — tylko liczniki i tickery spółek).
 */
final class Monitor
{
    public const EVERY = 10;            // co ile ticków skan z crona
    public const DEDUP = 240;           // tyle ticków ta sama anomalia na tej samej spółce nie trafia ponownie do dziennika
    public const QUOTE_FAR = 0.15;      // najlepsza oferta dalej niż 15% od kursu = arkusz oderwany od rynku
    public const SPREAD_WIDE = 0.10;    // spread szerszy niż 10% środka = drogi handel „od ręki”
    public const FUND_HI = 1.5;         // wycena / kurs powyżej 1,5 albo poniżej 1/1,5 = rynek rozjechał się z fundamentem
    public const QUIET_TICKS = 120;     // tyle ticków bez żadnej transakcji = spółka „martwa”
    public const MM_SEED_CASH = 8000000;// gotówka animatora na starcie świata (seed.php) — punkt odniesienia płynności
    public const MM_LOW_CASH = 200000;  // animator z wolną gotówką poniżej tej kwoty praktycznie nie wystawi już ofert kupna

    /** Nazwy anomalii (do panelu GM i dziennika). */
    public const LABELS = [
        'brak_kupna'     => 'Brak ofert kupna',
        'brak_sprzedazy' => 'Brak ofert sprzedaży',
        'oferty_daleko'  => 'Oferty daleko od kursu',
        'szeroki_spread' => 'Bardzo szeroki spread',
        'wycena_rozjazd' => 'Kurs daleko od wyceny',
        'kurs_podloga'   => 'Kurs na podłodze 1 zł',
        'cisza'          => 'Brak transakcji',
    ];

    /**
     * Pełny skan bez zapisu. Zwraca ['tick', 'stocks' => liczba spółek, 'issues' => [[code, stock_id, ticker, msg, ctx]],
     * 'counts' => [code => n], 'mm' => [...], 'strategies' => [...], 'alarms' => [kody alarmów rynkowych]].
     */
    public static function scan(): array
    {
        $tick = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        $now = Db::now();
        $book = [];
        foreach (Engine::all("SELECT stock_id, side, MAX(price) mx, MIN(price) mn, SUM(qty) q FROM orders WHERE status='active' GROUP BY stock_id, side") as $r) {
            $book[(int) $r['stock_id']][$r['side']] = $r;
        }
        $vol = [];
        foreach (Engine::all("SELECT stock_id, SUM(v) v FROM candles WHERE t > ? GROUP BY stock_id", [$tick - self::QUIET_TICKS]) as $r) {
            $vol[(int) $r['stock_id']] = (int) $r['v'];
        }
        $stocks = Engine::all("SELECT id, ticker, price, fundamental, halted_until FROM stocks ORDER BY id");
        $issues = [];
        $add = function (string $code, array $s, string $msg, array $ctx) use (&$issues) {
            $issues[] = [$code, (int) $s['id'], (string) $s['ticker'], $msg, ['stock_id' => (int) $s['id'], 'ticker' => $s['ticker']] + $ctx];
        };
        $f2 = fn($v) => number_format((float) $v, 2, ',', ' ');
        foreach ($stocks as $s) {
            $p = (float) $s['price']; $fund = (float) $s['fundamental'];
            if ($p <= 0) continue;
            $halted = $s['halted_until'] !== null && $s['halted_until'] !== '' && (string) $s['halted_until'] > $now;
            $bid = isset($book[(int) $s['id']]['buy']) ? (float) $book[(int) $s['id']]['buy']['mx'] : null;
            $ask = isset($book[(int) $s['id']]['sell']) ? (float) $book[(int) $s['id']]['sell']['mn'] : null;
            $ctx = ['price' => $p, 'bid' => $bid, 'ask' => $ask, 'fund' => $fund];
            // arkusz oceniamy tylko przy otwartym handlu spółką — zawieszenie świadomie wstrzymuje boty
            if (!$halted) {
                if ($bid === null) $add('brak_kupna', $s, "{$s['ticker']}: w arkuszu nie ma żadnej oferty kupna (kurs {$f2($p)} zł)", $ctx);
                if ($ask === null) $add('brak_sprzedazy', $s, "{$s['ticker']}: w arkuszu nie ma żadnej oferty sprzedaży (kurs {$f2($p)} zł)", $ctx);
                if ($ask !== null && $ask > $p * (1 + self::QUOTE_FAR)) {
                    $add('oferty_daleko', $s, "{$s['ticker']}: najtańsza oferta sprzedaży {$f2($ask)} zł przy kursie {$f2($p)} zł (×" . number_format($ask / $p, 2, ',', '') . ')', $ctx + ['side' => 'sell', 'ratio' => round($ask / $p, 3)]);
                } elseif ($bid !== null && $bid < $p * (1 - self::QUOTE_FAR)) {
                    $add('oferty_daleko', $s, "{$s['ticker']}: najlepsza oferta kupna {$f2($bid)} zł przy kursie {$f2($p)} zł (×" . number_format($bid / $p, 2, ',', '') . ')', $ctx + ['side' => 'buy', 'ratio' => round($bid / $p, 3)]);
                }
                if ($bid !== null && $ask !== null && $ask > $bid) {
                    $spr = ($ask - $bid) / (($ask + $bid) / 2);
                    if ($spr > self::SPREAD_WIDE) $add('szeroki_spread', $s, "{$s['ticker']}: spread " . number_format($spr * 100, 1, ',', '') . "% (kupno {$f2($bid)} / sprzedaż {$f2($ask)} zł)", $ctx + ['spread' => round($spr, 4)]);
                }
            }
            if ($fund > 0) {
                $g = $fund / $p;
                if ($g > self::FUND_HI || $g < 1 / self::FUND_HI) {
                    $add('wycena_rozjazd', $s, "{$s['ticker']}: wycena {$f2($fund)} zł, kurs {$f2($p)} zł (×" . number_format($g, 2, ',', '') . ')', $ctx + ['ratio' => round($g, 3)]);
                }
            }
            if ($p <= 1.0) $add('kurs_podloga', $s, "{$s['ticker']}: kurs na minimalnym poziomie 1 zł", $ctx);
            if ($tick > self::QUIET_TICKS && !$halted && (int) ($vol[(int) $s['id']] ?? 0) === 0) {
                $add('cisza', $s, "{$s['ticker']}: brak transakcji od " . self::QUIET_TICKS . " ticków", $ctx);
            }
        }
        $counts = array_fill_keys(array_keys(self::LABELS), 0);
        foreach ($issues as $i) $counts[$i[0]]++;

        // płynność animatorów: to oni trzymają arkusz po obu stronach — bez ich gotówki znikają oferty kupna
        $mm = Engine::row("SELECT COUNT(*) n, COALESCE(SUM(u.cash),0) cash, COALESCE(SUM(u.cash_reserved),0) res,
                                  SUM(CASE WHEN u.cash < ? THEN 1 ELSE 0 END) low
                           FROM users u JOIN bots b ON b.user_id = u.id WHERE b.strategy = 'mm' AND u.role <> 'challenger'", [self::MM_LOW_CASH]) ?: [];
        $mmN = (int) ($mm['n'] ?? 0);
        $mmPct = $mmN > 0 ? round(((float) $mm['cash'] + (float) $mm['res']) / ($mmN * self::MM_SEED_CASH) * 100, 1) : 0.0;
        $strategies = Engine::all("SELECT b.strategy, COUNT(*) n, ROUND(SUM(u.cash), 0) cash, ROUND(SUM(u.cash_reserved), 0) res,
                                          SUM(CASE WHEN u.cash < ? THEN 1 ELSE 0 END) low
                                   FROM users u JOIN bots b ON b.user_id = u.id WHERE u.role <> 'challenger' GROUP BY b.strategy ORDER BY b.strategy", [self::MM_LOW_CASH]);

        $n = count($stocks);
        $alarms = [];
        if ($mmN > 0 && $mmPct < 25) $alarms[] = 'mm_gotowka';
        if ($n > 0 && $counts['brak_kupna'] / $n > 0.2) $alarms[] = 'rynek_bez_kupujacych';
        if ($n > 0 && $counts['oferty_daleko'] / $n > 0.2) $alarms[] = 'arkusze_oderwane';
        if ($n > 0 && $counts['wycena_rozjazd'] / $n > 0.2) $alarms[] = 'wyceny_oderwane';

        return ['tick' => $tick, 'stocks' => $n, 'issues' => $issues, 'counts' => $counts,
                'mm' => ['n' => $mmN, 'pct' => $mmPct, 'low' => (int) ($mm['low'] ?? 0), 'cash' => (float) ($mm['cash'] ?? 0), 'res' => (float) ($mm['res'] ?? 0)],
                'strategies' => $strategies, 'alarms' => $alarms];
    }

    /** Skan + zapis: nowe anomalie do dziennika (z deduplikacją), podsumowanie do game_state. Zwraca wynik scan(). */
    public static function run(): array
    {
        $r = self::scan();
        $tick = $r['tick'];
        $logged = 0;
        $chk = Db::pdo()->prepare("SELECT 1 FROM logs WHERE source='monitor' AND event=? AND tick > ? AND context LIKE ? LIMIT 1");
        foreach ($r['issues'] as [$code, $sid, $ticker, $msg, $ctx]) {
            if ($logged >= 40) break;   // twardy limit na przebieg — przy masowej awarii wystarczy próbka + liczniki
            $chk->execute([$code, $tick - self::DEDUP, '{"stock_id":' . $sid . ',%']);
            if ($chk->fetchColumn()) continue;
            Log::write('warn', 'monitor', $code, $msg, $ctx);
            $logged++;
        }
        // alarmy rynkowe: wpis tylko przy ZMIANIE zestawu alarmów (włączenie albo ustąpienie)
        $prevAlarms = (string) (Engine::one("SELECT v FROM game_state WHERE k='monitor_alarms'") ?: '');
        $curAlarms = implode(',', $r['alarms']);
        if ($curAlarms !== $prevAlarms) {
            $lvl = $curAlarms === '' ? 'info' : 'error';
            Log::write($lvl, 'monitor', 'rynek.alarm', $curAlarms === '' ? 'Alarmy rynku ustąpiły' : 'Alarm rynku: ' . $curAlarms,
                ['alarms' => $r['alarms'], 'counts' => $r['counts'], 'mm' => $r['mm'], 'strategies' => $r['strategies']]);
            Engine::setState('monitor_alarms', $curAlarms);
        }
        Engine::setState('monitor_summary', self::summary($r));
        Engine::setState('monitor_tick', (string) $tick);
        return $r;
    }

    /** Zwięzły zapis wyniku (≤ 255 znaków — kolumna game_state.v): liczniki, płynność animatorów, najgorsze tickery. */
    public static function summary(array $r): string
    {
        $c = $r['counts'];
        $worst = [];
        foreach ($r['issues'] as [$code, , $ticker]) {
            if (in_array($code, ['oferty_daleko', 'brak_kupna', 'wycena_rozjazd'], true)) $worst[$ticker] = true;
        }
        $s = 't=' . $r['tick'] . ';spolek=' . $r['stocks'] . ';bez_kupna=' . $c['brak_kupna'] . ';bez_sprzedazy=' . $c['brak_sprzedazy']
           . ';oferty_daleko=' . $c['oferty_daleko'] . ';spread=' . $c['szeroki_spread'] . ';wycena=' . $c['wycena_rozjazd']
           . ';podloga=' . $c['kurs_podloga'] . ';cisza=' . $c['cisza'] . ';mm_proc=' . $r['mm']['pct'] . ';mm_puste=' . $r['mm']['low'] . '/' . $r['mm']['n']
           . ';alarmy=' . implode(',', $r['alarms']) . ';najgorsze=';
        foreach (array_keys($worst) as $t) {
            if (strlen($s) + strlen($t) + 1 > 250) break;
            $s .= $t . ',';
        }
        return rtrim($s, ',');
    }

    /** Odczyt zapisanego podsumowania jako tablica (dla health.php i panelu GM). */
    public static function lastSummary(): array
    {
        $v = (string) (Engine::one("SELECT v FROM game_state WHERE k='monitor_summary'") ?: '');
        $out = [];
        foreach (array_filter(explode(';', $v)) as $kv) {
            [$k, $val] = array_pad(explode('=', $kv, 2), 2, '');
            $out[$k] = is_numeric($val) ? $val + 0 : $val;
        }
        return $out;
    }
}
