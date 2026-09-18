<?php
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('market.php');

// poza godzinami handlu giełda nie przyjmuje zleceń (QA i admin testują zawsze)
require_market_open($user, 'stock.php?id=' . (int) ($_POST['stock_id'] ?? 0));

$sid   = (int) ($_POST['stock_id'] ?? 0);
$side  = $_POST['side'] ?? 'buy';
$type  = in_array($_POST['type'] ?? 'limit', ['market', 'stop'], true) ? $_POST['type'] : 'limit';   // limit | market (PKC) | stop (stop-buy)
$qty   = (int) ($_POST['qty'] ?? 0);
$price = (float) str_replace(',', '.', $_POST['price'] ?? '0');
$sl    = ($_POST['sl_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['sl_price']) : null;
$tp    = ($_POST['tp_price'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['tp_price']) : null;

// wolne akcje PRZED zleceniem — do policzenia, ile REALNIE dokupił ten pakiet (auto-SL/TP)
$freeBefore = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$user['id'], $sid]) ?: 0);
$filledAny = false;
if ($type === 'stop') {
    // STOP-BUY: kupno czekające na przebicie progu (tylko strona kupna; do sprzedaży po spadku służy Stop-Loss)
    $trigger = (float) str_replace(',', '.', $_POST['trigger'] ?? '0');
    $limit   = ($_POST['limit'] ?? '') !== '' ? (float) str_replace(',', '.', $_POST['limit']) : round($trigger * 1.02, 2);
    $price   = $limit; $oid = 0;
    if ($side !== 'buy') { $ok = false; $msg = 'Stop-buy działa tylko dla kupna. Sprzedaż po spadku kursu to Stop-Loss (Portfel → pozycja).'; }
    else [$ok, $msg, $oid] = Engine::retryOnLock(fn() => Engine::placeStopBuy((int) $user['id'], $sid, $qty, $trigger, $limit)) + [2 => 0];
} elseif ($type === 'market') {
    [$ok, $msg] = Engine::retryOnLock(fn() => Engine::marketOrder((int) $user['id'], $sid, $side, $qty));
    $filledAny = $ok;   // PKC = realizacja natychmiast albo błąd
} else {
    $exp = ($_POST['validity'] ?? 'gtc') === 'session' ? Engine::sessionInfo()[0] : null;
    // Ponawiane OSOBNO: samo złożenie i samo kojarzenie. Wspólne ponowienie mogłoby złożyć zlecenie dwa razy.
    [$ok, $msg, $oid] = Engine::retryOnLock(fn() => Engine::place((int) $user['id'], $sid, $side, $qty, $price, $exp)) + [2 => 0];
    if ($ok) {
        Engine::retryOnLock(fn() => Engine::matchBook($sid));   // spróbuj skojarzyć od razu
        // JASNE potwierdzenie: ile weszło od razu, ile czeka w arkuszu
        $rem = $oid > 0 ? (int) (Engine::one("SELECT qty FROM orders WHERE id=? AND status='active'", [$oid]) ?? 0) : 0;
        $done = max(0, $qty - $rem);
        $filledAny = $done > 0;
        $act = $side === 'buy' ? 'Kupiono' : 'Sprzedano';
        $czas = $side === 'buy' ? 'kupca' : 'sprzedającego';
        if ($rem <= 0)        $msg = "✅ $act $done szt. po ~" . number_format($price, 2, ',', ' ') . ' PLN. Zlecenie zrealizowane w całości.';
        elseif ($done > 0)    $msg = "✅ $act od razu $done szt. — pozostałe $rem szt. czeka w arkuszu po " . number_format($price, 2, ',', ' ') . ' PLN (zobacz Portfel → Zlecenia).';
        else                  $msg = "📥 Zlecenie " . ($side === 'buy' ? 'kupna' : 'sprzedaży') . " $qty szt. po " . number_format($price, 2, ',', ' ') . " PLN złożone — czeka w arkuszu na $czas (Portfel → Zlecenia).";
    }
}
Log::write($ok ? 'info' : 'warn', 'player', 'order.place', ($ok ? 'przyjęte' : 'odrzucone') . ": $type $side {$qty}szt" . ($type === 'limit' ? " @ $price" : ($type === 'stop' ? " @ limit $price, próg " . ($trigger ?? 0) : '')) . " (spółka #$sid)",
    ['user' => $user['username'], 'msg' => $msg]);
$jTk = (string) Engine::one("SELECT ticker FROM stocks WHERE id=?", [$sid]);
Engine::journal((int) $user['id'], 'order',
    ($ok ? '📝 Złożono zlecenie: ' : '⛔ Zlecenie odrzucone: ')
    . ($type === 'market' ? 'PKC' : ($type === 'stop' ? 'stop-buy' : 'limit')) . ' ' . ($side === 'buy' ? 'kupno' : 'sprzedaż') . " {$qty} szt. {$jTk}"
    . ($type === 'limit' ? ' @ ' . number_format($price, 2, ',', ' ') : ($type === 'stop' ? ' (próg ' . number_format($trigger ?? 0, 2, ',', ' ') . ', limit ' . number_format($price, 2, ',', ' ') . ')' : ''))
    . ($ok ? '.' : ' — ' . $msg),
    $ok && !empty($oid) ? 'order.php?id=' . (int) $oid : 'stock.php?id=' . $sid);
if ($ok && $side === 'buy' && $type !== 'stop' && ($sl !== null || $tp !== null)) {   // stop-buy nic jeszcze nie kupił — SL/TP ustawisz po aktywacji
    // zlecenie obronne NA KUPIONY PAKIET (nie na całą pozycję): tyle, ile REALNIE dokupiło to
    // zlecenie (przyrost wolnych akcji), a nie cały wolny stan portfela (który obejmuje stare akcje)
    $free = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$user['id'], $sid]) ?: 0);
    $bought = max(0, $free - $freeBefore);
    $stopQty = min($bought, $free);
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
// misje dnia rozliczane od razu po zleceniu (nie tylko przy wejściu na Pulpit) — na konto WŁAŚCICIELA
if ($ok) { try { Daily::missions(Engine::challengeOwner((int) $user['id'])); } catch (Throwable $e) { /* misje nie psują zlecenia */ } }
flash($msg, $ok ? 'ok' : 'err');
redirect('stock.php?id=' . $sid);
