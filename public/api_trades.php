<?php
/** JSON do odświeżania karty spółki bez F5: transakcje, arkusz (bid/ask) i obrót sesji. */
require __DIR__ . '/_boot.php';
require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');   // bez tego mobilna przeglądarka „zamraża" listę do przeładowania

$id = (int) ($_GET['id'] ?? 0);
$trades = Engine::all("SELECT qty, price, created_at FROM transactions WHERE stock_id=? ORDER BY id DESC LIMIT 14", [$id]);
$bids = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='buy'  AND status='active' GROUP BY price ORDER BY price DESC LIMIT 8", [$id]);
$asks = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='sell' AND status='active' GROUP BY price ORDER BY price ASC  LIMIT 8", [$id]);
$turn = (float) (Engine::one("SELECT SUM(v * c) FROM candles WHERE stock_id=? AND t >= ?", [$id, Engine::sessionStartTick()]) ?: 0);

echo json_encode([
    'ok'       => true,
    'turnover' => money_short($turn) . ' PLN',
    'trades'   => array_map(fn($t) => ['t' => substr((string) $t['created_at'], 11, 8), 'qty' => (int) $t['qty'], 'price' => number_format((float) $t['price'], 2, '.', '')], $trades),
    'bids'     => array_map(fn($r) => ['p' => number_format((float) $r['price'], 2, '.', ''), 'q' => (int) $r['q']], $bids),
    'asks'     => array_map(fn($r) => ['p' => number_format((float) $r['price'], 2, '.', ''), 'q' => (int) $r['q']], $asks),
]);
