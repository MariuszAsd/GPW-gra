<?php
require __DIR__ . '/_boot.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('market.php');

$sid   = (int) ($_POST['stock_id'] ?? 0);
$side  = $_POST['side'] ?? 'buy';
$type  = ($_POST['type'] ?? 'limit') === 'market' ? 'market' : 'limit';
$qty   = (int) ($_POST['qty'] ?? 0);
$price = (float) str_replace(',', '.', $_POST['price'] ?? '0');
$sl    = ($_POST['sl_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['sl_price']) : null;
$tp    = ($_POST['tp_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['tp_price']) : null;

if ($type === 'market') {
    [$ok, $msg] = Engine::marketOrder((int) $user['id'], $sid, $side, $qty);
} else {
    $exp = ($_POST['validity'] ?? 'gtc') === 'session' ? Engine::sessionInfo()[0] : null;
    [$ok, $msg] = Engine::place((int) $user['id'], $sid, $side, $qty, $price, $exp);
    if ($ok) Engine::matchBook($sid);              // spróbuj skojarzyć od razu
}
Log::write($ok ? 'info' : 'warn', 'player', 'order.place', ($ok ? 'przyjęte' : 'odrzucone') . ": $type $side {$qty}szt" . ($type === 'limit' ? " @ $price" : '') . " (spółka #$sid)",
    ['user' => $user['username'], 'msg' => $msg]);
if ($ok && ($sl !== null || $tp !== null)) {       // ustaw SL/TP na pozycji
    Engine::ensureWallet((int) $user['id'], $sid);
    Db::pdo()->prepare("UPDATE wallets SET sl_price=?, tp_price=? WHERE user_id=? AND stock_id=?")
        ->execute([$sl, $tp, $user['id'], $sid]);
}
flash($msg, $ok ? 'ok' : 'err');
redirect('stock.php?id=' . $sid);
