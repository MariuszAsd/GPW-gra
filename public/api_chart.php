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
 */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');

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
        if (!isset($out[$b])) $out[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v']];
        else {
            $out[$b]['h'] = max($out[$b]['h'], (float) $r['h']);
            $out[$b]['l'] = min($out[$b]['l'], (float) $r['l']);
            $out[$b]['c'] = (float) $r['c'];
            $out[$b]['v'] += (int) $r['v'];
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
        if (!isset($agg[$b])) $agg[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v']];
        else {
            $agg[$b]['h'] = max($agg[$b]['h'], (float) $r['h']);
            $agg[$b]['l'] = min($agg[$b]['l'], (float) $r['l']);
            $agg[$b]['c'] = (float) $r['c'];
            $agg[$b]['v'] += (int) $r['v'];
        }
    }
    return ['candles' => array_values($agg), 'raw' => count($raw)];
}

[, , $tps] = Engine::sessionInfo();

if ($range === '' && isset($_GET['iv'])) {   // tryb legacy (stare klienty)
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
    echo json_encode(['ok' => true, 'range' => $range, 'iv' => $ivp,
                      'trimmed' => $sliced || ($r['raw'] === $span && $horizon > $span), 'candles' => $cs]);
    exit;
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
        if (!isset($agg[$b])) $agg[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v']];
        else {
            $agg[$b]['h'] = max($agg[$b]['h'], (float) $r['h']);
            $agg[$b]['l'] = min($agg[$b]['l'], (float) $r['l']);
            $agg[$b]['c'] = (float) $r['c'];
            $agg[$b]['v'] += (int) $r['v'];
        }
    }
    $cs = array_values($agg);
    $trim = count($cs) > $maxBars;
    if ($trim) $cs = array_slice($cs, -$maxBars);
    $total = (int) Engine::one("SELECT COUNT(*) FROM candles_daily WHERE stock_id=?", [$id]);
    echo json_encode(['ok' => true, 'range' => $range, 'iv' => $ivp, 'days_total' => $total,
                      'trimmed' => $trim, 'candles' => $cs]);
    exit;
}

// --- ŚWIECA auto (dotychczasowe zachowanie) ---
if ($range === 'd') {
    // SESJA: bieżąca sesja handlowa (intraday)
    echo json_encode(['ok' => true, 'range' => 'd', 'iv' => 'auto', 'candles' => intraday($id, max(60, $tps), $maxBars)['candles']]);
    exit;
}

$daily = $nSess > 0
    ? array_reverse(Engine::all("SELECT o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session DESC LIMIT " . $nSess, [$id]))
    : Engine::all("SELECT o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session ASC", [$id]);

$total = (int) Engine::one("SELECT COUNT(*) FROM candles_daily WHERE stock_id=?", [$id]);
if (count($daily) < 3) {
    // świat jeszcze nie uzbierał świec dziennych — pokaż całą dostępną historię tickową
    echo json_encode(['ok' => true, 'range' => $range, 'iv' => 'auto', 'fallback' => 'intraday', 'days' => $total, 'candles' => intraday($id, 20000, $maxBars)['candles']]);
    exit;
}
echo json_encode(['ok' => true, 'range' => $range, 'iv' => 'auto', 'days' => count($daily), 'days_total' => $total,
                  'candles' => bucket($daily, (int) ceil(count($daily) / $maxBars))]);
