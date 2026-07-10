<?php
/** Licznik nieprzeczytanych powiadomień (badge na dzwonku, polling co 15 s). */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
$u = current_user();
if (!$u) { echo json_encode(['ok' => false]); exit; }
$n = (int) Engine::one("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$u['id']]);
echo json_encode(['ok' => true, 'unread' => $n]);
