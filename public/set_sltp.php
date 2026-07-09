<?php
require __DIR__ . '/_boot.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('portfolio.php');
$sid = (int) ($_POST['stock_id'] ?? 0);
$sl  = $_POST['sl_price'] !== '' ? (float) str_replace(',', '.', $_POST['sl_price']) : null;
$tp  = $_POST['tp_price'] !== '' ? (float) str_replace(',', '.', $_POST['tp_price']) : null;
Engine::ensureWallet((int) $user['id'], $sid);
Db::pdo()->prepare("UPDATE wallets SET sl_price=?, tp_price=? WHERE user_id=? AND stock_id=?")
    ->execute([$sl, $tp, $user['id'], $sid]);
flash('Zaktualizowano SL/TP.', 'ok');
redirect('portfolio.php');
