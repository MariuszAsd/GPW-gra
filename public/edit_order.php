<?php
/** Zmiana aktywnego zlecenia z limitem (cena/ilość reszty). Wołane z karty spółki, Portfela i szczegółów zlecenia. */
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('portfolio.php');

$oid   = (int) ($_POST['order_id'] ?? 0);
$qty   = (int) ($_POST['qty'] ?? 0);
$price = (float) str_replace(',', '.', $_POST['price'] ?? '0');

[$ok, $msg] = Engine::editOrder($oid, (int) $user['id'], $qty, $price);
Log::write($ok ? 'info' : 'warn', 'player', 'order.edit', ($ok ? 'zmieniono' : 'odrzucono') . ": #$oid -> {$qty}szt @ $price",
    ['user' => $user['username'], 'order_id' => $oid, 'msg' => $msg]);
if ($ok) Engine::journal((int) $user['id'], 'order', '✏️ Zmieniono zlecenie #' . $oid . ' — ' . $qty . ' szt. po ' . number_format($price, 2, ',', ' ') . ' PLN.', 'order.php?id=' . $oid);
flash($msg, $ok ? 'ok' : 'err');

// powrót tam, skąd przyszła edycja (tylko lokalna ścieżka aplikacji — bez otwartego przekierowania)
$back = (string) ($_POST['back'] ?? '');
if ($back !== '' && preg_match('~^[a-z_]+\.php(\?[\w=&%.-]*)?$~i', $back)) redirect($back);
redirect('order.php?id=' . $oid);
