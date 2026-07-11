<?php
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('portfolio.php');
[$ok, $msg] = Engine::cancel((int) ($_POST['order_id'] ?? 0), (int) $user['id']);
Log::write($ok ? 'info' : 'warn', 'player', 'order.cancel', $msg, ['user' => $user['username'], 'order_id' => (int) ($_POST['order_id'] ?? 0)]);
flash($msg, $ok ? 'ok' : 'err');
redirect('portfolio.php');
