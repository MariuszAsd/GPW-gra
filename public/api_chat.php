<?php
/** Czat rynkowy: odczyt (polling ?since=id) i wpis (POST msg). Anty-spam: 1 wpis / 5 s. */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
$u = current_user();
if (!$u) { echo json_encode(['ok' => false]); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['del'])) {   // moderacja: tylko admin ukrywa wpisy
        if (($u['role'] ?? '') === 'admin') {
            Db::pdo()->prepare("UPDATE chat_messages SET deleted=1 WHERE id=?")->execute([(int) $_POST['del']]);
            Log::write('info', 'gm', 'chat.delete', 'ukryto wpis #' . (int) $_POST['del'], ['by' => $u['username']]);
            echo json_encode(['ok' => true]); exit;
        }
        echo json_encode(['ok' => false, 'err' => 'Brak uprawnień.']); exit;
    }
    $msg = trim((string) ($_POST['msg'] ?? ''));
    if ($msg === '' || mb_strlen($msg) > 300) { echo json_encode(['ok' => false, 'err' => 'Wpis musi mieć 1-300 znaków.']); exit; }
    // anty-spam ATOMOWO (odporne na równoległe POST-y): wstaw tylko, gdy brak wpisu z ostatnich 5 s
    $cutoff = date('Y-m-d H:i:s', time() - 5);
    $st = Db::pdo()->prepare("INSERT INTO chat_messages (user_id, message, created_at)
                              SELECT ?, ?, ? FROM (SELECT 1 AS one) t
                              WHERE NOT EXISTS (SELECT 1 FROM chat_messages WHERE user_id = ? AND created_at > ?)");
    $st->execute([$u['id'], $msg, Db::now(), $u['id'], $cutoff]);
    if ($st->rowCount() === 0) { echo json_encode(['ok' => false, 'err' => 'Nie tak szybko — odczekaj chwilę.']); exit; }
    if (mt_rand(1, 20) === 1) {   // retencja: trzymaj ~500 ostatnich wpisów (rzadko, nie przy każdym wpisie)
        $edge = Engine::one("SELECT id FROM chat_messages ORDER BY id DESC LIMIT 1 OFFSET 500");
        if ($edge) Db::pdo()->prepare("DELETE FROM chat_messages WHERE id <= ?")->execute([$edge]);
    }
    echo json_encode(['ok' => true]); exit;
}

$since = (int) ($_GET['since'] ?? 0);
$rows = array_reverse(Engine::all(
    "SELECT c.id, c.message, c.created_at, u.username, u.id AS uid, u.role, u.chat_color
     FROM chat_messages c JOIN users u ON u.id = c.user_id
     WHERE c.deleted = 0 AND c.id > ? ORDER BY c.id DESC LIMIT 40", [$since]));
$out = [];
foreach ($rows as $r) {
    // kolor nicka (kosmetyka) — tylko zweryfikowany hex, żeby nic nie wstrzyknąć do stylu
    $col = preg_match('/^#[0-9a-f]{6}$/i', (string) $r['chat_color']) ? (string) $r['chat_color'] : '';
    $out[] = ['id' => (int) $r['id'], 'uid' => (int) $r['uid'], 'u' => $r['username'],
              'gm' => $r['role'] === 'admin' ? 1 : 0, 'pl' => $r['role'] === 'player' ? 1 : 0,
              'c' => $col, 'm' => $r['message'], 't' => substr($r['created_at'], 11, 5)];
}
// świeżo ukryte wpisy (moderacja GM) — klienci usuwają je z widoku bez przeładowania
$hidden = $since > 0
    ? array_map('intval', Engine::col("SELECT id FROM chat_messages WHERE deleted = 1 AND id > ?", [max(0, $since - 60)]))
    : [];
echo json_encode(['ok' => true, 'msgs' => $out, 'hidden' => $hidden, 'admin' => ($u['role'] ?? '') === 'admin' ? 1 : 0]);
