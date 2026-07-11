<?php
/**
 * JSON świec pod wykres spółki.
 *   ?id=SPÓŁKA&range=d|t|m|r|max   d = dzień (intraday), reszta = świece dzienne D1
 *   ?id=SPÓŁKA&iv=N                tryb legacy: N ticków na świecę (intraday)
 * Świece tickowe żyją ~20 dni (retencja), dlatego tydzień/miesiąc/rok/max
 * budujemy z candles_daily (jedna świeca na sesję, pisana na zamknięciu dnia).
 */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');

$id = (int) ($_GET['id'] ?? 0);
$range = strtolower((string) ($_GET['range'] ?? ''));
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

/** intraday z candles: ostatnie $span ticków zbite do <= $maxBars świec */
function intraday(int $id, int $span, int $maxBars): array {
    $raw = array_reverse(Engine::all("SELECT t,o,h,l,c,v FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT " . $span, [$id]));
    $iv = max(1, (int) ceil(count($raw) / $maxBars));   // koszyk wg DOSTĘPNYCH danych (młody świat = wciąż czytelny wykres)
    // koszyki wyrównane do t (świece nie skaczą między odświeżeniami)
    $agg = [];
    foreach ($raw as $r) {
        $b = intdiv((int) $r['t'] - 1, $iv);
        if (!isset($agg[$b])) $agg[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v']];
        else {
            $agg[$b]['h'] = max($agg[$b]['h'], (float) $r['h']);
            $agg[$b]['l'] = min($agg[$b]['l'], (float) $r['l']);
            $agg[$b]['c'] = (float) $r['c'];
            $agg[$b]['v'] += (int) $r['v'];
        }
    }
    return array_values($agg);
}

[, , $tps] = Engine::sessionInfo();

if ($range === '' && isset($_GET['iv'])) {   // tryb legacy (stare klienty)
    $iv = max(1, min(200, (int) $_GET['iv']));
    echo json_encode(['ok' => true, 'iv' => $iv, 'candles' => intraday($id, 80 * $iv, 80)]);
    exit;
}

$sessions = ['t' => 7, 'm' => 30, 'r' => 365, 'max' => 0];
if ($range === 'd' || !isset($sessions[$range])) {
    // DZIEŃ: bieżąca sesja handlowa (intraday)
    echo json_encode(['ok' => true, 'range' => 'd', 'candles' => intraday($id, max(60, $tps), $maxBars)]);
    exit;
}

$nSess = $sessions[$range];
$daily = $nSess > 0
    ? array_reverse(Engine::all("SELECT o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session DESC LIMIT " . $nSess, [$id]))
    : Engine::all("SELECT o,h,l,c,v FROM candles_daily WHERE stock_id=? ORDER BY session ASC", [$id]);

$total = (int) Engine::one("SELECT COUNT(*) FROM candles_daily WHERE stock_id=?", [$id]);
if (count($daily) < 3) {
    // świat jeszcze nie uzbierał świec dziennych — pokaż całą dostępną historię tickową
    echo json_encode(['ok' => true, 'range' => $range, 'fallback' => 'intraday', 'days' => $total, 'candles' => intraday($id, 20000, $maxBars)]);
    exit;
}
echo json_encode(['ok' => true, 'range' => $range, 'days' => count($daily), 'days_total' => $total,
                  'candles' => bucket($daily, (int) ceil(count($daily) / $maxBars))]);
