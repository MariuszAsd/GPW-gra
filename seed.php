<?php
/** Zasiewa świat: sektory, spółki (DNA), boty, szablony newsów, raporty startowe. */
if (php_sapi_name() !== 'cli' && !defined('GPW_ALLOW_SETUP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Engine.php';

$cfg = require __DIR__ . '/config.php';
$pdo = Db::pdo();
$cli = php_sapi_name() === 'cli';
$log = fn($m) => print($cli ? "$m\n" : "<p>$m</p>");
$now = date('Y-m-d H:i:s');

// --- konta ---
$pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,0,'player',?)")
    ->execute(['gracz', password_hash('haslo123', PASSWORD_DEFAULT), $cfg['starting_cash']]);
$pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,0,'admin',0)")
    ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
Engine::setState('tick', '0');
Engine::setState('sentiment', '0');
Engine::setState('ticks_per_month', '20');
$log("✔ konta: gracz/haslo123, admin/admin123");

// --- SEKTORY ---
$sectors = [
    // symbol, nazwa, market_beta, volatility, growth, news_sensitivity
    ['TECH', 'Technologie',     1.4, 1.5, 0.03, 1.6],
    ['BIO',  'Biotechnologia',  1.8, 2.2, 0.04, 2.0],
    ['LUX',  'Dobra luksusowe', 1.2, 1.6, 0.01, 1.4],
    ['IND',  'Przemysł',        1.0, 1.0, 0.00, 0.9],
    ['FIN',  'Finanse',         1.1, 0.9, 0.01, 1.1],
    ['ENE',  'Energetyka',      0.8, 1.1, 0.00, 1.0],
];
$secStmt = $pdo->prepare("INSERT INTO sectors (symbol,name,market_beta,volatility,growth,news_sensitivity) VALUES (?,?,?,?,?,?)");
$secId = [];
foreach ($sectors as $s) { $secStmt->execute($s); $secId[$s[0]] = (int) $pdo->lastInsertId(); }
$log("✔ " . count($sectors) . " sektorów");

// --- SPÓŁKI (DNA) ---
// t=ticker n=name s=sektor p=cena sh=akcje pe=C/Z + DNA
$stocks = [
    ['t'=>'PXS','n'=>'PixelSoft','s'=>'TECH','p'=>100.00,'sh'=>5000000,'pe'=>22,'beta'=>1.4,'vol'=>1.5,'liq'=>1.3,'ni'=>1.4,'nf'=>1.6,'res'=>0.8,'gp'=>0.03,'ag'=>1.3],
    ['t'=>'CDR','n'=>'CD Projekt','s'=>'TECH','p'=>128.50,'sh'=>2000000,'pe'=>25,'beta'=>1.5,'vol'=>1.8,'liq'=>1.2,'ni'=>1.6,'nf'=>1.5,'res'=>0.7,'gp'=>0.04,'ag'=>1.6],
    ['t'=>'GNI','n'=>'GenomX','s'=>'BIO','p'=>45.00,'sh'=>3000000,'pe'=>18,'beta'=>1.8,'vol'=>2.2,'liq'=>0.8,'ni'=>1.8,'nf'=>2.0,'res'=>0.6,'gp'=>0.05,'ag'=>1.9],
    ['t'=>'NAP','n'=>'NaturaPharm','s'=>'BIO','p'=>82.30,'sh'=>1500000,'pe'=>16,'beta'=>0.9,'vol'=>1.4,'liq'=>1.0,'ni'=>1.2,'nf'=>1.2,'res'=>1.2,'gp'=>0.02,'ag'=>1.1],
    ['t'=>'AEL','n'=>'Aethelred Luxury','s'=>'LUX','p'=>250.00,'sh'=>800000,'pe'=>20,'beta'=>1.2,'vol'=>1.6,'liq'=>1.0,'ni'=>1.3,'nf'=>1.4,'res'=>1.0,'gp'=>0.01,'ag'=>1.2],
    ['t'=>'PRM','n'=>'Prestige Motors','s'=>'LUX','p'=>540.00,'sh'=>500000,'pe'=>17,'beta'=>1.5,'vol'=>1.7,'liq'=>0.9,'ni'=>1.4,'nf'=>1.2,'res'=>0.9,'gp'=>0.02,'ag'=>1.4],
    ['t'=>'STB','n'=>'Stal-Bud','s'=>'IND','p'=>150.00,'sh'=>2000000,'pe'=>12,'beta'=>1.0,'vol'=>1.0,'liq'=>1.1,'ni'=>0.9,'nf'=>0.7,'res'=>1.3,'gp'=>0.00,'ag'=>0.9],
    ['t'=>'MCH','n'=>'MegaChem','s'=>'IND','p'=>320.00,'sh'=>1200000,'pe'=>13,'beta'=>0.9,'vol'=>1.1,'liq'=>1.0,'ni'=>1.1,'nf'=>0.8,'res'=>1.2,'gp'=>0.00,'ag'=>1.0],
    ['t'=>'BNK','n'=>'Bank Centralny','s'=>'FIN','p'=>210.00,'sh'=>4000000,'pe'=>11,'beta'=>1.1,'vol'=>0.9,'liq'=>1.4,'ni'=>1.1,'nf'=>1.1,'res'=>1.4,'gp'=>0.01,'ag'=>0.8],
    ['t'=>'ENG','n'=>'EnergiaPL','s'=>'ENE','p'=>64.00,'sh'=>3000000,'pe'=>14,'beta'=>0.8,'vol'=>1.1,'liq'=>1.2,'ni'=>1.0,'nf'=>1.0,'res'=>1.3,'gp'=>0.00,'ag'=>1.0],
];
$stStmt = $pdo->prepare("INSERT INTO stocks
    (ticker,name,sector_id,price,fundamental,day_open_price,total_shares,beta,volatility,liquidity,
     news_impact,news_frequency,financial_resilience,growth_potential,aggressiveness,pe_target,
     base_profit,last_profit,last_eps,report_period,next_report_tick)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$i = 0;
foreach ($stocks as $c) {
    $base = round($c['p'] * $c['sh'] / ($c['pe'] * 12), 2);   // miesięczny zysk spójny z ceną i C/Z
    $eps  = round($base * 12 / $c['sh'], 4);
    $next = 4 + $i * 2;                                        // rozłóż pierwsze raporty w czasie
    $stStmt->execute([
        $c['t'], $c['n'], $secId[$c['s']], $c['p'], $c['p'], $c['p'], $c['sh'],
        $c['beta'], $c['vol'], $c['liq'], $c['ni'], $c['nf'], $c['res'], $c['gp'], $c['ag'], $c['pe'],
        $base, $base, $eps, 20, $next,
    ]);
    $i++;
}
$stockRows = Engine::all("SELECT id, price, base_profit, last_eps, total_shares FROM stocks ORDER BY id");
$log("✔ " . count($stockRows) . " spółek z DNA");

// --- historia startowa (płaskie świece) ---
$cStmt = $pdo->prepare("INSERT INTO candles (stock_id,t,o,h,l,c,v) VALUES (?,?,?,?,?,?,0)");
foreach ($stockRows as $st) for ($t = -25; $t < 0; $t++) $cStmt->execute([$st['id'], $t, $st['price'], $st['price'], $st['price'], $st['price']]);

// --- raport startowy (Miesiąc 0) — żeby zakładka nie była pusta ---
$rStmt = $pdo->prepare("INSERT INTO financial_reports (stock_id,tick,period,report_date,revenue,costs,net_profit,eps,expected_eps,surprise_pct)
                        VALUES (?,0,'Miesiąc 0',?,?,?,?,?,?,0)");
foreach ($stockRows as $st) {
    $profit = (float) $st['base_profit']; $rev = round($profit / 0.15, 2); $cost = round($rev - $profit, 2);
    $rStmt->execute([$st['id'], $now, $rev, $cost, $profit, $st['last_eps'], $st['last_eps']]);
}
$log("✔ raporty startowe");

// --- SZABLONY NEWSÓW / ESPI ---
$tpl = [
    ['Analitycy podnoszą rekomendację dla [T]', 'Dom maklerski podniósł cenę docelową akcji [T].', 'POS', 'COMPANY', 0, 0.15, 20, 12],
    ['[T] ogłasza program skupu akcji własnych', 'Zarząd [T] poinformował o skupie akcji.', 'POS', 'COMPANY', 1, 0.30, 6, 15],
    ['[T] podpisuje duży kontrakt', 'Spółka [T] zawarła znaczącą umowę handlową.', 'POS', 'COMPANY', 1, 0.35, 8, 12],
    ['Odejście kluczowego dyrektora w [T]', 'Z [T] odchodzi wieloletni członek zarządu.', 'NEG', 'COMPANY', 1, -0.25, 8, 12],
    ['Postępowanie regulacyjne wobec [T]', 'Wobec [T] wszczęto postępowanie wyjaśniające.', 'NEG', 'COMPANY', 1, -0.30, 10, 10],
    ['Optymizm w sektorze [T]', 'Inwestorzy pozytywnie patrzą na branżę [T].', 'POS', 'SECTOR', 0, 0.12, 25, 14],
    ['Niepewność w sektorze [T]', 'Nad branżą [T] zbierają się chmury.', 'NEG', 'SECTOR', 0, -0.15, 25, 14],
    ['Spokojna sesja dla [T]', 'Notowania [T] pozostają stabilne.', 'NEU', 'COMPANY', 0, 0.00, 15, 30],
];
$tStmt = $pdo->prepare("INSERT INTO news_templates (headline_template,body_template,type,scope,is_espi,base_impact,duration_ticks,frequency_weight) VALUES (?,?,?,?,?,?,?,?)");
foreach ($tpl as $t) $tStmt->execute($t);
$log("✔ " . count($tpl) . " szablonów newsów/ESPI");

// --- BOTY (+ DNA) ---
$uStmt = $pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,1,?,?)");
$dStmt = $pdo->prepare("INSERT INTO bots (user_id, strategy, news_reactivity, technical_sensitivity, risk_appetite, horizon) VALUES (?,?,?,?,?,?)");
$wStmt = $pdo->prepare("INSERT INTO wallets (user_id, stock_id, qty, avg_price) VALUES (?,?,?,?)");
$n = 0;
$mk = function (string $role, int $count, float $cash, int $shares) use ($uStmt, $dStmt, $wStmt, $pdo, $stockRows, &$n) {
    for ($i = 0; $i < $count; $i++) {
        $uStmt->execute(["bot_{$role}_" . (++$n), password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT), $role, $cash]);
        $uid = (int) $pdo->lastInsertId();
        $dStmt->execute([$uid, $role, mt_rand(50, 200) / 100, mt_rand(50, 200) / 100, mt_rand(50, 200) / 100, mt_rand(5, 30)]);
        foreach ($stockRows as $st) $wStmt->execute([$uid, $st['id'], $shares, $st['price']]);
    }
};
$mk('mm', 12, 5000000, 4000);
$mk('trend', 9, 1000000, 300);
$mk('rsi', 9, 1000000, 300);
$log("✔ 30 botów (mm/trend/rsi) + DNA");

// --- gracz dostaje akcje na start ---
Engine::ensureWallet(1, (int) $stockRows[0]['id']);
$pdo->prepare("UPDATE wallets SET qty=100, avg_price=? WHERE user_id=1 AND stock_id=?")
    ->execute([$stockRows[0]['price'], $stockRows[0]['id']]);

$log("✅ Świat zasiany.");
