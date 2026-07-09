<?php
/** JSON do odświeżania kursów bez przeładowania (polling z rynku). */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
$out = [];
foreach (Engine::all("SELECT id, ticker, price FROM stocks") as $s) {
    $ref = Engine::one("SELECT c FROM candles WHERE stock_id=? AND t>=1 ORDER BY t ASC LIMIT 1", [$s['id']]);
    $ref = $ref !== false && $ref !== null ? (float) $ref : (float) $s['price'];
    $chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0;
    $out[$s['id']] = ['price' => number_format($s['price'], 2, '.', ''), 'chg' => round($chg, 2)];
}
echo json_encode(['ok' => true, 'data' => $out]);
