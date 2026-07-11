<?php
/** Obserwowane (gwiazdki): POST stock_id przełącza obserwowanie. GET zwraca listę id. */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
$u = current_user();
if (!$u) { echo json_encode(['ok' => false]); exit; }
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sid = (int) ($_POST['stock_id'] ?? 0);
    if (!Engine::one("SELECT id FROM stocks WHERE id=?", [$sid])) { echo json_encode(['ok' => false, 'err' => 'Nie ma takiej spółki.']); exit; }
    $limit = 30;   // rozsądny sufit — alerty i pulpit mają zostać czytelne
    $pdo = Db::pdo();
    $has = Engine::one("SELECT id FROM watchlist WHERE user_id=? AND stock_id=?", [$uid, $sid]);
    if ($has) {
        $pdo->prepare("DELETE FROM watchlist WHERE id=?")->execute([(int) $has]);
        echo json_encode(['ok' => true, 'on' => false]); exit;
    }
    if ((int) Engine::one("SELECT COUNT(*) FROM watchlist WHERE user_id=?", [$uid]) >= $limit) {
        echo json_encode(['ok' => false, 'err' => "Limit obserwowanych: $limit spółek."]); exit;
    }
    $pdo->prepare("INSERT INTO watchlist (user_id, stock_id, created_at) VALUES (?,?,?)")->execute([$uid, $sid, Db::now()]);
    echo json_encode(['ok' => true, 'on' => true]); exit;
}

echo json_encode(['ok' => true, 'ids' => array_map('intval', Engine::col("SELECT stock_id FROM watchlist WHERE user_id=?", [$uid]))]);
