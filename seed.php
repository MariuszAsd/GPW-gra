<?php
/** Zasiewa świat: gracz demo, spółki, boty + historia startowa. Uruchom: php seed.php */
// Ochrona: nie wolno uruchamiać przez przeglądarkę (tylko CLI albo z instalatora).
if (php_sapi_name() !== 'cli' && !defined('GPW_ALLOW_SETUP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Engine.php';

$cfg = require __DIR__ . '/config.php';
$pdo = Db::pdo();
$cli = php_sapi_name() === 'cli';
$log = fn($m) => print($cli ? "$m\n" : "<p>$m</p>");

// --- gracz demo (login: gracz / haslo123) ---
$pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,0,'player',?)")
    ->execute(['gracz', password_hash('haslo123', PASSWORD_DEFAULT), $cfg['starting_cash']]);
$log("✔ gracz demo: login 'gracz' / hasło 'haslo123' (" . number_format($cfg['starting_cash'], 0, ',', ' ') . " PLN)");

// --- konto admina (panel GM do sterowania rynkiem) ---
$pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,0,'admin',?)")
    ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT), 0]);
$log("✔ konto admina: login 'admin' / hasło 'admin123' (panel GM: /public/gm.php)");
Engine::setState('sentiment', '0');   // globalne nastawienie rynku (%/tick)

// --- spółki ---
$stocks = [
    ['PXS', 'PixelSoft',        'Technologie', 100.00],
    ['CDR', 'CD Projekt',       'Technologie', 128.50],
    ['GNI', 'GenomX',           'Biotech',      45.00],
    ['NAP', 'NaturaPharm',      'Biotech',      82.30],
    ['AEL', 'Aethelred Luxury', 'Dobra lux.',  250.00],
    ['PRM', 'Prestige Motors',  'Dobra lux.',  540.00],
    ['STB', 'Stal-Bud',         'Przemysł',    150.00],
    ['MCH', 'MegaChem',         'Przemysł',    320.00],
    ['BNK', 'Bank Centralny',   'Finanse',     210.00],
    ['ENG', 'EnergiaPL',        'Energetyka',   64.00],
];
$sIns = $pdo->prepare("INSERT INTO stocks (ticker, name, sector, price, fundamental) VALUES (?,?,?,?,?)");
foreach ($stocks as $s) $sIns->execute([$s[0], $s[1], $s[2], $s[3], $s[3]]);
$stockRows = Engine::all("SELECT id, price FROM stocks");
$log("✔ " . count($stockRows) . " spółki");

// --- historia startowa (płaskie świece), żeby SMA/RSI miały dane ---
$c = $pdo->prepare("INSERT INTO candles (stock_id,t,o,h,l,c,v) VALUES (?,?,?,?,?,?,0)");
foreach ($stockRows as $st) for ($t = -25; $t < 0; $t++) $c->execute([$st['id'], $t, $st['price'], $st['price'], $st['price'], $st['price']]);
Engine::setState('tick', '0');

// --- boty: 12 market makerów + 9 trend + 9 RSI ---
$uIns = $pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,1,?,?)");
$wIns = $pdo->prepare("INSERT INTO wallets (user_id, stock_id, qty, avg_price) VALUES (?,?,?,?)");
$n = 0;
$mk = function (string $role, int $count, float $cash, int $shares) use ($uIns, $wIns, $pdo, $stockRows, &$n) {
    for ($i = 0; $i < $count; $i++) {
        $uIns->execute(["bot_{$role}_" . (++$n), password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT), $role, $cash]);
        $uid = $pdo->lastInsertId();
        foreach ($stockRows as $st) $wIns->execute([$uid, $st['id'], $shares, $st['price']]);
    }
};
$mk('mm', 12, 5_000_000, 4000);
$mk('trend', 9, 1_000_000, 300);
$mk('rsi', 9, 1_000_000, 300);
$log("✔ 30 botów (12 market maker, 9 trend, 9 RSI)");

// gracz dostaje trochę akcji na start, żeby było co sprzedawać
Engine::ensureWallet(1, $stockRows[0]['id']);
$pdo->prepare("UPDATE wallets SET qty=100, avg_price=? WHERE user_id=1 AND stock_id=?")->execute([$stockRows[0]['price'], $stockRows[0]['id']]);

$log("✅ Świat zasiany.");
