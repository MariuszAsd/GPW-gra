<?php
require __DIR__ . '/_boot.php';
$user = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('portfolio.php');
[$ok, $msg] = Engine::cancel((int) ($_POST['order_id'] ?? 0), (int) $user['id']);
flash($msg, $ok ? 'ok' : 'err');
redirect('portfolio.php');
