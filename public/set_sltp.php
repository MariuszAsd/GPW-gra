<?php
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('portfolio.php');

$sid = (int) ($_POST['stock_id'] ?? 0);
$qty = (int) ($_POST['qty'] ?? 0);
$sl  = ($_POST['sl_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['sl_price']) : null;
$tp  = ($_POST['tp_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['tp_price']) : null;

[$ok, $msg] = Engine::placeStop((int) $user['id'], $sid, $qty, $sl, $tp);
Log::write($ok ? 'info' : 'warn', 'player', 'order.stop', ($ok ? 'przyjęte' : 'odrzucone') . ": SL/TP {$qty}szt (spółka #$sid)",
    ['user' => $user['username'], 'sl' => $sl, 'tp' => $tp, 'msg' => $msg]);
flash($msg, $ok ? 'ok' : 'err');
redirect('portfolio.php');
