<?php
/** JSON do odświeżania kursów bez przeładowania (polling z rynku i strony spółki). */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
[$sessionNo, , $tps] = Engine::sessionInfo();
$sessStart = ($sessionNo - 1) * $tps;
$out = [];
foreach (Engine::all("SELECT s.id, s.price, s.day_open_price,
                             (SELECT SUM(c.v * c.c) FROM candles c WHERE c.stock_id = s.id AND c.t >= $sessStart) AS turnover
                      FROM stocks s") as $s) {
    $ref = (float) $s['day_open_price'] > 0 ? (float) $s['day_open_price'] : (float) $s['price'];
    $chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0;
    $out[$s['id']] = ['price' => number_format($s['price'], 2, '.', ''), 'chg' => round($chg, 2), 'vol' => money_short((float) $s['turnover'])];
}
$idxSeries = array_reverse(array_map(fn($v) => round((float) $v, 2), Engine::col("SELECT value FROM index_history ORDER BY t DESC LIMIT 120")));
$idxNow = $idxSeries ? end($idxSeries) : Engine::indexValue();
$idxOpen = (float) (Engine::one("SELECT value FROM index_history WHERE t >= ? ORDER BY t ASC LIMIT 1", [($sessionNo - 1) * $tps]) ?: $idxNow);
$idxChg = $idxOpen > 0 ? ($idxNow - $idxOpen) / $idxOpen * 100 : 0;
echo json_encode(['ok' => true, 'session' => $sessionNo, 'data' => $out,
    'index' => ['value' => number_format($idxNow, 2, '.', ''), 'chg' => round($idxChg, 2), 'series' => $idxSeries]]);
