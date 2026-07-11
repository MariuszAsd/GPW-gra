<?php
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('market.php');

// poza godzinami handlu giełda nie przyjmuje zleceń (QA i admin testują zawsze)
if (!Engine::marketIsOpen() && !in_array($user['role'] ?? '', ['admin', 'qa'], true)) {
    [, $mo, $mc] = Engine::marketHours();
    flash("🌙 Giełda jest zamknięta — handel trwa {$mo}–{$mc}. Zlecenie nie zostało przyjęte.", 'err');
    redirect('stock.php?id=' . (int) ($_POST['stock_id'] ?? 0));
}

$sid   = (int) ($_POST['stock_id'] ?? 0);
$side  = $_POST['side'] ?? 'buy';
$type  = ($_POST['type'] ?? 'limit') === 'market' ? 'market' : 'limit';
$qty   = (int) ($_POST['qty'] ?? 0);
$price = (float) str_replace(',', '.', $_POST['price'] ?? '0');
$sl    = ($_POST['sl_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['sl_price']) : null;
$tp    = ($_POST['tp_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['tp_price']) : null;

$filledAny = false;
if ($type === 'market') {
    [$ok, $msg] = Engine::marketOrder((int) $user['id'], $sid, $side, $qty);
    $filledAny = $ok;   // PKC = realizacja natychmiast albo błąd
} else {
    $exp = ($_POST['validity'] ?? 'gtc') === 'session' ? Engine::sessionInfo()[0] : null;
    [$ok, $msg, $oid] = Engine::place((int) $user['id'], $sid, $side, $qty, $price, $exp) + [2 => 0];
    if ($ok) {
        Engine::matchBook($sid);                   // spróbuj skojarzyć od razu
        $filledAny = $oid > 0 && (int) Engine::one("SELECT qty FROM orders WHERE id=?", [$oid]) < $qty;
    }
}
Log::write($ok ? 'info' : 'warn', 'player', 'order.place', ($ok ? 'przyjęte' : 'odrzucone') . ": $type $side {$qty}szt" . ($type === 'limit' ? " @ $price" : '') . " (spółka #$sid)",
    ['user' => $user['username'], 'msg' => $msg]);
if ($ok && $side === 'buy' && ($sl !== null || $tp !== null)) {
    // zlecenie obronne NA KUPIONY PAKIET (nie na całą pozycję) — tyle, ile realnie weszło do portfela
    $free = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$user['id'], $sid]) ?: 0);
    $stopQty = min($qty, $free);
    if ($stopQty > 0) {
        [$ok2, $msg2] = Engine::placeStop((int) $user['id'], $sid, $stopQty, $sl, $tp);
        Log::write($ok2 ? 'info' : 'warn', 'player', 'order.stop', ($ok2 ? 'przyjęte' : 'odrzucone') . ": SL/TP {$stopQty}szt (spółka #$sid)",
            ['user' => $user['username'], 'sl' => $sl, 'tp' => $tp, 'msg' => $msg2]);
        $msg .= ' ' . $msg2;
    } else {
        $msg .= ' SL/TP nie ustawione — zlecenie kupna czeka w arkuszu (ustaw je w Portfelu po realizacji).';
    }
}
if ($ok && $side === 'buy' && $filledAny) {
    // odznaka: kupował, gdy lała się krew (kupno ZREALIZOWANE w trakcie krachu)
    $tk = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
    $crash = Engine::one("SELECT id FROM news WHERE scope='MARKET' AND type='NEG' AND impact_strength <= -0.8 AND expire_tick > ?", [$tk]);
    if ($crash) Engine::award((int) $user['id'], 'kupil_w_krachu');
}
flash($msg, $ok ? 'ok' : 'err');
redirect('stock.php?id=' . $sid);
