<?php
/**
 * JSON świec pod wykres spółki. Dwa niezależne wymiary:
 *   range = ZAKRES / horyzont (jak daleko wstecz): d|t|m|r|max
 *   iv    = ŚWIECA / interwał: auto | 1|5|15|60 (ticki=minuty) | d (sesja) | w (tydzień)
 * auto  = koszyk dobrany tak, by cały horyzont zmieścił się w <= 110 słupkach (jak dotąd);
 * jawna świeca pokazuje NAJNOWSZE <= 110 słupków horyzontu (trimmed=true, gdy ucięte).
 *   ?id=SPÓŁKA&iv=N bez range = tryb legacy (stare klienty): N ticków na świecę.
 * Świece tickowe żyją ~20 dni (retencja), dlatego świece d/w budujemy z candles_daily
 * (jedna świeca na sesję, pisana na zamknięciu dnia).
 *
 * Każda świeca niesie znacznik czasu: intraday -> 't' (tick) + 'lbl' (HH:MM), dzienna -> 's'
 * (sesja) + 'lbl' (data). Dodatkowo 'news' = znaczniki wiadomości/ESPI tej spółki na osi czasu
 * (tylko tryby tickowe), żeby na wykresie było widać jak kurs reaguje na newsy.
 */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');   // świece na żywo bez cache przeglądarki

$id = (int) ($_GET['id'] ?? 0);
$range = strtolower((string) ($_GET['range'] ?? ''));
$ivp = strtolower((string) ($_GET['iv'] ?? 'auto'));
$maxBars = 110;

/** agregacja listy świec do <= $maxBars słupków (koszyk = kolejne $per sztuk) */
function bucket(array $rows, int $per): array {
    if ($per <= 1) return array_values($rows);
    $out = [];
    foreach (array_values($rows) as $i => $r) {
        $b = intdiv($i, $per);
        if (!isset($out[$b])) $out[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v'], 's' => (int) ($r['session'] ?? 0)];
        else {
            $out[$b]['h'] = max($out[$b]['h'], (float) $r['h']);
            $out[$b]['l'] = min($out[$b]['l'], (float) $r['l']);
            $out[$b]['c'] = (float) $r['c'];
            $out[$b]['v'] += (int) $r['v'];
            $out[$b]['s'] = (int) ($r['session'] ?? 0);
        }
    }
    return array_values($out);
}

/** ostatnie $span ticków z candles zbite w koszyki po $per ticków, wyrównane do t
 *  (świece nie skaczą między odświeżeniami); $per=0 => koszyk auto pod <= $maxBars słupków */
function intraday(int $id, int $span, int $maxBars, int $per = 0): array {
    $raw = array_reverse(Engine::all("SELECT t,o,h,l,c,v FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT " . $span, [$id]));
    if ($per < 1) $per = max(1, (int) ceil(count($raw) / $maxBars));   // koszyk wg DOSTĘPNYCH danych (młody świat = wciąż czytelny wykres)
    $agg = [];
    foreach ($raw as $r) {
        $b = intdiv((int) $r['t'] - 1, $per);
        if (!isset($agg[$b])) $agg[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v'], 't' => (int) $r['t']];
        else {
            $agg[$b]['h'] = max($agg[$b]['h'], (float) $r['h']);
            $agg[$b]['l'] = min($agg[$b]['l'], (float) $r['l']);
            $agg[$b]['c'] = (float) $r['c'];
            $agg[$b]['v'] += (int) $r['v'];
            $agg[$b]['t'] = (int) $r['t'];   // ostatni tick koszyka (reprezentant czasu)
        }
    }
    return ['candles' => array_values($agg), 'raw' => count($raw)];
}

/** dopisz do świec etykietę czasu: 'tick' -> HH:MM (minuty od otwarcia sesji), 'session' -> data */
function chartLabels(array &$candles, string $mode): void {
    if ($mode === 'tick') {
        $sst = Engine::sessionStartTick();
        [$en, $open] = Engine::marketHours();
        [$oh, $om] = array_map('intval', explode(':', $en ? $open : '09:00'));
        $base = $oh * 60 + $om;
        foreach ($candles as &$c) {
            $min = (($base + ((int) ($c['t'] ?? 0) - $sst)) % 1440 + 1440) % 1440;
            $c['lbl'] = sprintf('%02d:%02d', intdiv($min, 60), $min % 60);
        }
        unset($c);
    } else {
        foreach ($candles as &$c) {
            $s = (int) ($c['s'] ?? 0);
            $d = $s > 0 ? Engine::sessionDate($s) : null;
            $p = $d ? explode('-', $d) : null;
            $c['lbl'] = $p && count($p) === 3 ? $p[2] . '.' . $p[1] : ($s > 0 ? 'sesja #' . $s : '');
        }
        unset($c);
    }
}

/** znaczniki newsów/ESPI tej spółki (i jej sektora) w oknie ticków świec — tylko tryby tickowe.
 *  Pomija czysty flavor (technical bez impactu); pozycja = najbliższa świeca po publikacji. */
function chartNews(int $id, array $candles): array {
    if (!$candles || !isset($candles[0]['t'])) return [];
    $ts = array_map(fn($c) => (int) $c['t'], $candles);
    $tMin = min($ts); $tMax = max($ts); $n = count($candles);
    $secId = (int) (Engine::one("SELECT sector_id FROM stocks WHERE id=?", [$id]) ?: 0);
    $rows = Engine::all(
        "SELECT headline, type, is_espi, impact_strength, publish_tick, scope, kind
         FROM news
         WHERE publish_tick BETWEEN ? AND ?
           AND (is_espi = 1 OR impact_strength <> 0)
           AND ( (scope='COMPANY' AND target_id=?) OR (scope='SECTOR' AND target_id=?) OR scope='MARKET' )
         ORDER BY publish_tick ASC",
        [$tMin, $tMax, $id, $secId]
    );
    $out = [];
    foreach ($rows as $nw) {
        $pt = (int) $nw['publish_tick'];
        $idx = $n - 1;
        for ($i = 0; $i < $n; $i++) { if ($ts[$i] >= $pt) { $idx = $i; break; } }
        $out[] = [
            'pos'   => $n > 1 ? round($idx / ($n - 1), 4) : 0.5,
            'type'  => $nw['type'],                       // POS/NEG/NEU
            'espi'  => (int) $nw['is_espi'],
            'scope' => $nw['scope'],
            'imp'   => round((float) $nw['impact_strength'], 3),
            'head'  => (string) $nw['headline'],
        ];
    }
    return $out;
}

/** wspólne wyjście: dopisz etykiety czasu + znaczniki newsów (tick) i zwróć JSON */
function emit(int $id, array $candles, array $meta, string $mode): void {
    chartLabels($candles, $mode);
    $news = $mode === 'tick' ? chartNews($id, $candles) : [];
    echo json_encode(array_merge(['ok' => true], $meta, ['candles' => $candles, 'news' => $news]));
    exit;
}

[, , $tps] = Engine::sessionInfo();

if ($range === '' && isset($_GET['iv'])) {   // tryb legacy (stare klienty) — bez dekoracji
    $iv = max(1, min(200, (int) $_GET['iv']));
    echo json_encode(['ok' => true, 'iv' => $iv, 'candles' => intraday($id, 80 * $iv, 80)['candles']]);
    exit;
}

$sessAll = ['d' => 1, 't' => 7, 'm' => 30, 'r' => 365, 'max' => 0];
if (!isset($sessAll[$range])) $range = 'd';
$nSess = $sessAll[$range];

// --- ŚWIECA jawna, interwały tickowe (M1/M5/M15/H1) ---
if (in_array($ivp, ['1', '5', '15', '60'], true)) {
    $per = (int) $ivp;
    $horizon = $nSess === 0 ? PHP_INT_MAX : ($range === 'd' ? max(60, $tps) : $nSess * $tps);
    $span = (int) min($horizon, $maxBars * $per);
    $r = intraday($id, $span, $maxBars, $per);
    // koszyki są wyrównane do bezwzględnego t, więc okno $span ticków może zahaczyć
    // o maxBars+1 koszyków — utnij najstarszy (i tak częściowy: fałszywy open/wolumen)
    $cs = $r['candles'];
    $sliced = count($cs) > $maxBars;
    if ($sliced) $cs = array_slice($cs, -$maxBars);
    emit($id, $cs, ['range' => $range, 'iv' => $ivp, 'trimmed' => $sliced || ($r['raw'] === $span && $horizon > $span)], 'tick');
}

// --- ŚWIECA jawna, dzienna/tygodniowa (D1/T1) z candles_daily ---
if ($ivp === 'd' || $ivp === 'w') {
    $per = $ivp === 'w' ? 7 : 1;
    $rows = $nSess > 0
        ? array_reverse(Engine::all("SELECT session,o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session DESC LIMIT " . $nSess, [$id]))
        : Engine::all("SELECT session,o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session ASC", [$id]);
    $agg = [];
    foreach ($rows as $r) {   // tygodnie wyrównane do numeru sesji (stabilne między odświeżeniami)
        $b = intdiv((int) $r['session'] - 1, $per);
        if (!isset($agg[$b])) $agg[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v'], 's' => (int) $r['session']];
        else {
            $agg[$b]['h'] = max($agg[$b]['h'], (float) $r['h']);
            $agg[$b]['l'] = min($agg[$b]['l'], (float) $r['l']);
            $agg[$b]['c'] = (float) $r['c'];
            $agg[$b]['v'] += (int) $r['v'];
            $agg[$b]['s'] = (int) $r['session'];
        }
    }
    $cs = array_values($agg);
    $trim = count($cs) > $maxBars;
    if ($trim) $cs = array_slice($cs, -$maxBars);
    $total = (int) Engine::one("SELECT COUNT(*) FROM candles_daily WHERE stock_id=?", [$id]);
    emit($id, $cs, ['range' => $range, 'iv' => $ivp, 'days_total' => $total, 'trimmed' => $trim], 'session');
}

// --- ŚWIECA auto (dotychczasowe zachowanie) ---
if ($range === 'd') {
    // SESJA: bieżąca sesja handlowa (intraday)
    emit($id, intraday($id, max(60, $tps), $maxBars)['candles'], ['range' => 'd', 'iv' => 'auto'], 'tick');
}

$daily = $nSess > 0
    ? array_reverse(Engine::all("SELECT session,o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session DESC LIMIT " . $nSess, [$id]))
    : Engine::all("SELECT session,o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session ASC", [$id]);

$total = (int) Engine::one("SELECT COUNT(*) FROM candles_daily WHERE stock_id=?", [$id]);
if (count($daily) < 3) {
    // świat jeszcze nie uzbierał świec dziennych — pokaż całą dostępną historię tickową
    emit($id, intraday($id, 20000, $maxBars)['candles'], ['range' => $range, 'iv' => 'auto', 'fallback' => 'intraday', 'days' => $total], 'tick');
}
emit($id, bucket($daily, (int) ceil(count($daily) / $maxBars)), ['range' => $range, 'iv' => 'auto', 'days' => count($daily), 'days_total' => $total], 'session');
