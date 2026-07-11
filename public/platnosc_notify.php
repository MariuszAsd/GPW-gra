<?php
/**
 * Webhook operatora płatności (PayU notifyUrl) — BEZ sesji i layoutu.
 * PayU wysyła tu status zamówienia; podpis weryfikuje Payments::handleNotify.
 * Odpowiedź 200 = potwierdzenie odbioru (inaczej PayU ponawia do skutku).
 */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Migrator.php';
require_once __DIR__ . '/../src/Engine.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Tokens.php';
require_once __DIR__ . '/../src/Payments.php';

try { Migrator::ensure(); } catch (Throwable $e) { /* baza dogoni się przy zwykłym ruchu */ }

$raw = (string) file_get_contents('php://input');
$sig = (string) ($_SERVER['HTTP_OPENPAYU_SIGNATURE'] ?? $_SERVER['HTTP_X_OPENPAYU_SIGNATURE'] ?? '');

[$code, $body] = Payments::handleNotify($raw, $sig);
http_response_code($code);
header('Content-Type: application/json');
echo $body;
