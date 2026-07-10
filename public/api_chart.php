<?php
/** JSON świec pod wykres spółki: ?id=SPÓŁKA&iv=TICKÓW_NA_ŚWIECĘ (1 = surowe ticki). */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');

$id = (int) ($_GET['id'] ?? 0);
$iv = max(1, min(200, (int) ($_GET['iv'] ?? 1)));
$n  = 80;   // świec na wykresie

$raw = array_reverse(Engine::all("SELECT t,o,h,l,c,v FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT " . ($n * $iv), [$id]));
$agg = [];
foreach ($raw as $r) {
    $b = intdiv((int) $r['t'] - 1, $iv);
    if (!isset($agg[$b])) {
        $agg[$b] = ['o' => (float) $r['o'], 'h' => (float) $r['h'], 'l' => (float) $r['l'], 'c' => (float) $r['c'], 'v' => (int) $r['v']];
    } else {
        $x = &$agg[$b];
        $x['h'] = max($x['h'], (float) $r['h']);
        $x['l'] = min($x['l'], (float) $r['l']);
        $x['c'] = (float) $r['c'];
        $x['v'] += (int) $r['v'];
        unset($x);
    }
}
echo json_encode(['ok' => true, 'iv' => $iv, 'candles' => array_values($agg)]);
