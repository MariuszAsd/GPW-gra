<?php
/** Web Push: klucz publiczny i stan (GET) oraz zapis/usunięcie subskrypcji i test (POST, JSON). Wymaga zalogowania. */
require __DIR__ . '/_boot.php';
header('Content-Type: application/json');
$u = current_user();
if (!$u) { echo json_encode(['ok' => false, 'err' => 'Zaloguj się.']); exit; }
$uid = (int) ($u['owner_id'] ?? $u['id']);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $avail = Push::available();
    $k = $avail ? Push::keys(true) : null;
    echo json_encode(['ok' => true, 'available' => $avail && $k !== null, 'key' => $k['public'] ?? null, 'count' => Push::count($uid)]);
    exit;
}
$in = json_decode((string) file_get_contents('php://input'), true) ?: [];
$action = (string) ($in['action'] ?? $_POST['action'] ?? '');
if ($action === 'subscribe') {
    $s = $in['subscription'] ?? [];
    $ok = Push::subscribe($uid, (string) ($s['endpoint'] ?? ''), (string) ($s['keys']['p256dh'] ?? ''), (string) ($s['keys']['auth'] ?? ''));
    if ($ok) Log::write('info', 'player', 'push.subscribe', "push włączony (#$uid)", ['user' => $u['username']]);
    echo json_encode(['ok' => $ok, 'count' => Push::count($uid), 'err' => $ok ? null : 'Nieprawidłowa subskrypcja.']);
} elseif ($action === 'unsubscribe') {
    $n = Push::unsubscribe($uid, isset($in['endpoint']) ? (string) $in['endpoint'] : null);
    Log::write('info', 'player', 'push.unsubscribe', "push wyłączony (#$uid, $n)", ['user' => $u['username']]);
    echo json_encode(['ok' => true, 'removed' => $n, 'count' => Push::count($uid)]);
} elseif ($action === 'test') {
    [$sent, $fail] = Push::sendUser($uid, 'Makleria', '🔔 Powiadomienia push działają. Dostaniesz tu m.in. wyzwolone stopy, dywidendy i wyniki lig.', 'powiadomienia.php', 'test');
    echo json_encode(['ok' => $sent > 0, 'sent' => $sent, 'fail' => $fail, 'err' => $sent > 0 ? null : 'Nie udało się dostarczyć — sprawdź, czy przeglądarka ma włączone powiadomienia dla tej strony.']);
} else {
    echo json_encode(['ok' => false, 'err' => 'Nieznana akcja.']);
}
