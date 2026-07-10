<?php
/** JSON do odświeżania kursów bez przeładowania (polling z rynku i strony spółki). */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
$out = [];
foreach (Engine::all("SELECT id, price, day_open_price FROM stocks") as $s) {
    $ref = (float) $s['day_open_price'] > 0 ? (float) $s['day_open_price'] : (float) $s['price'];
    $chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0;
    $out[$s['id']] = ['price' => number_format($s['price'], 2, '.', ''), 'chg' => round($chg, 2)];
}
[$sessionNo, , $tps] = Engine::sessionInfo();
$idxNow = (float) (Engine::one("SELECT value FROM index_history ORDER BY t DESC LIMIT 1") ?: Engine::indexValue());
$idxOpen = (float) (Engine::one("SELECT value FROM index_history WHERE t >= ? ORDER BY t ASC LIMIT 1", [($sessionNo - 1) * $tps]) ?: $idxNow);
$idxChg = $idxOpen > 0 ? ($idxNow - $idxOpen) / $idxOpen * 100 : 0;
echo json_encode(['ok' => true, 'session' => $sessionNo, 'data' => $out,
    'index' => ['value' => number_format($idxNow, 2, '.', ''), 'chg' => round($idxChg, 2)]]);
