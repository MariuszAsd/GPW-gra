<?php
/**
 * Analiza techniczna: 10 klasycznych wskaźników liczonych ze świec.
 *
 * Każdy wskaźnik zwraca CIĄGŁY sygnał w [-1, +1] (plus = kupuj, minus = sprzedaj) —
 * nic nie jest zero-jedynkowe: przebicie linii trendu to sygnał tym mocniejszy,
 * im wyraźniejsze przebicie. Sygnał zbiorczy to średnia WAŻONA — a wagi są RÓŻNE
 * dla różnych spółek (deterministycznie z id spółki), więc każda spółka "słucha"
 * innych wskaźników. Do tego DNA spółki ma tech_affinity (0..1): spółki techniczne
 * silniej reagują na sygnały (boty grają je mocniej), fundamentalne — słabiej.
 *
 * Boty: strategia 'tech' gra niemal wyłącznie na sygnale zbiorczym; boty trendowe
 * dostają "przechył" techniczny. Gracz widzi TE SAME odczyty w zakładce Analiza
 * na karcie spółki — może grać z botami albo przeciwko nim.
 */
final class Technical
{
    /** klucz => [nazwa, typ: trend|reversal] (typ tylko informacyjnie dla UI) */
    public const CATALOG = [
        'sma_cross' => ['Przecięcie średnich SMA 20/50', 'trend'],
        'ema_slope' => ['Nachylenie EMA 20',             'trend'],
        'trendline' => ['Linia trendu (regresja 60)',    'trend'],
        'donchian'  => ['Wybicie z kanału (40)',         'trend'],
        'macd'      => ['MACD (12/26/9)',                'trend'],
        'roc'       => ['Momentum ROC (10)',             'trend'],
        'volume'    => ['Potwierdzenie wolumenem',       'trend'],
        'rsi'       => ['RSI (14)',                      'reversal'],
        'stoch'     => ['Oscylator stochastyczny (14)',  'reversal'],
        'bollinger' => ['Wstęgi Bollingera (20, 2σ)',    'reversal'],
    ];

    private static array $sigCache = [];
    private static int $cacheTick = -1;

    private static function clamp(float $v): float { return max(-1.0, min(1.0, $v)); }

    private static function avg(array $a): float { return $a ? array_sum($a) / count($a) : 0.0; }

    /** seria EMA dla całej tablicy (klasyczny mnożnik 2/(n+1)) */
    private static function emaSeries(array $a, int $n): array
    {
        if (!$a) return [];
        $k = 2 / ($n + 1);
        $out = [$a[0]];
        for ($i = 1, $c = count($a); $i < $c; $i++) $out[$i] = $a[$i] * $k + $out[$i - 1] * (1 - $k);
        return $out;
    }

    /** Sygnały 10 wskaźników dla spółki: klucz => float [-1,1]. Cache per tick. */
    public static function signals(int $sid): array
    {
        $tick = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        if ($tick !== self::$cacheTick) { self::$sigCache = []; self::$cacheTick = $tick; }
        if (isset(self::$sigCache[$sid])) return self::$sigCache[$sid];

        $rows = array_reverse(Engine::all("SELECT h,l,c,v FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT 120", [$sid]));
        $c = array_map(fn($r) => (float) $r['c'], $rows);
        $h = array_map(fn($r) => (float) $r['h'], $rows);
        $l = array_map(fn($r) => (float) $r['l'], $rows);
        $v = array_map(fn($r) => (int) $r['v'], $rows);
        $n = count($c);
        $sig = array_fill_keys(array_keys(self::CATALOG), 0.0);   // za mało danych = neutralnie
        if ($n < 15) return self::$sigCache[$sid] = $sig;
        $price = $c[$n - 1] ?: 1.0;

        // SMA 20/50: odległość szybkiej od wolnej (procentowo, nasyca się przy ~2%)
        if ($n >= 50) {
            $s20 = self::avg(array_slice($c, -20));
            $s50 = self::avg(array_slice($c, -50));
            if ($s50 > 0) $sig['sma_cross'] = self::clamp(($s20 - $s50) / $s50 * 50);
        }
        // nachylenie EMA20 na ostatnich 5 świecach
        if ($n >= 30) {
            $e = self::emaSeries($c, 20);
            $sig['ema_slope'] = self::clamp(($e[$n - 1] - $e[$n - 6]) / $price * 150);
        }
        // RSI 14 — kontrariański: wyprzedanie (RSI 30) = kupuj, wykupienie (70) = sprzedaj
        $g = 0.0; $s = 0.0;
        for ($i = $n - 14; $i < $n; $i++) {
            $d = $c[$i] - $c[$i - 1];
            if ($d >= 0) $g += $d; else $s -= $d;
        }
        $rsi = $s + $g > 0 ? 100 * $g / ($g + $s) : 50.0;
        $sig['rsi'] = self::clamp((50 - $rsi) / 35);
        // MACD 12/26/9: histogram względem kursu
        if ($n >= 45) {
            $e12 = self::emaSeries($c, 12);
            $e26 = self::emaSeries($c, 26);
            $macd = [];
            for ($i = $n - 20; $i < $n; $i++) $macd[] = $e12[$i] - $e26[$i];
            $s9 = self::emaSeries($macd, 9);
            $hist = end($macd) - end($s9);
            $sig['macd'] = self::clamp($hist / $price * 400);
        }
        // Bollinger 20/2σ: pozycja w z-score, kontrariańsko (pod dolną wstęgą = kupuj)
        if ($n >= 20) {
            $w = array_slice($c, -20);
            $m = self::avg($w);
            $var = 0.0; foreach ($w as $x) $var += ($x - $m) ** 2;
            $sd = sqrt($var / 20);
            if ($sd > 0) $sig['bollinger'] = self::clamp(-($price - $m) / (2 * $sd));
        }
        // ROC 10: tempo zmiany
        if ($n >= 11 && $c[$n - 11] > 0) $sig['roc'] = self::clamp(($price / $c[$n - 11] - 1) * 10);
        // Stochastic %K 14 — kontrariański
        $hh = max(array_slice($h, -14)); $ll = min(array_slice($l, -14));
        if ($hh > $ll) $sig['stoch'] = self::clamp((50 - (($price - $ll) / ($hh - $ll) * 100)) / 35);
        // wolumen: ożywienie obrotu potwierdza kierunek ostatnich 5 świec
        if ($n >= 30) {
            $va5 = self::avg(array_slice($v, -5));
            $va30 = self::avg(array_slice($v, -30));
            if ($va30 > 0 && $c[$n - 6] > 0) {
                $dir = $price >= $c[$n - 6] ? 1 : -1;
                $sig['volume'] = self::clamp(($va5 / $va30 - 1) * $dir * 0.9);
            }
        }
        // kanał Donchiana 40: pozycja w kanale; wybicie ponad szczyt/pod dołek = pełny sygnał
        if ($n >= 40) {
            $hh = max(array_slice($h, -40)); $ll = min(array_slice($l, -40));
            if ($hh > $ll) $sig['donchian'] = self::clamp(($price - ($hh + $ll) / 2) / (($hh - $ll) / 2) * 1.15);
        }
        // linia trendu: nachylenie regresji liniowej z 60 świec (procent na okno)
        if ($n >= 60) {
            $w = array_slice($c, -60); $m = 60;
            $sx = $m * ($m - 1) / 2; $sxx = ($m - 1) * $m * (2 * $m - 1) / 6;
            $sy = array_sum($w); $sxy = 0.0;
            foreach ($w as $i => $y) $sxy += $i * $y;
            $den = $m * $sxx - $sx * $sx;
            if ($den != 0) {
                $slope = ($m * $sxy - $sx * $sy) / $den;
                $sig['trendline'] = self::clamp($slope * 60 / $price * 5);
            }
        }
        return self::$sigCache[$sid] = $sig;
    }

    /** Wagi wskaźników dla KONKRETNEJ spółki (deterministyczne z id, zakres 0.3-1.7). */
    public static function weights(int $sid): array
    {
        $w = [];
        foreach (array_keys(self::CATALOG) as $key) {
            $w[$key] = round(0.3 + (crc32($sid . '|' . $key) % 1000) / 1000 * 1.4, 2);
        }
        return $w;
    }

    /** Sygnał zbiorczy [-1,1]: średnia ważona wg wag spółki. */
    public static function composite(int $sid): float
    {
        $sig = self::signals($sid);
        $w = self::weights($sid);
        $sum = 0.0; $wsum = 0.0;
        foreach ($sig as $k => $s) { $sum += $s * $w[$k]; $wsum += $w[$k]; }
        return $wsum > 0 ? round($sum / $wsum, 4) : 0.0;
    }

    /** Werdykt tekstowy dla sygnału zbiorczego (jak w serwisach AT). */
    public static function verdict(float $s): array
    {
        if ($s >= 0.35)  return ['Kupuj', 'p'];
        if ($s >= 0.12)  return ['Lekko kupuj', 'p'];
        if ($s <= -0.35) return ['Sprzedaj', 'n'];
        if ($s <= -0.12) return ['Lekko sprzedaj', 'n'];
        return ['Neutralnie', ''];
    }

    /** Charakter spółki wg podatności technicznej. */
    public static function character(float $aff): string
    {
        if ($aff >= 0.62) return 'techniczna — kurs mocno słucha wskaźników';
        if ($aff <= 0.38) return 'fundamentalna — liczą się zyski i raporty, technika mniej';
        return 'mieszana — technika i fundamenty po połowie';
    }
}
