<?php
/** Zasiewa świat: 8 sektorów, 50 spółek z GENEROWANYMI nazwami i DNA, boty, szablony newsów. */
if (php_sapi_name() !== 'cli' && !defined('GPW_ALLOW_SETUP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Engine.php';

$cfg = require __DIR__ . '/config.php';
$pdo = Db::pdo();
$cli = php_sapi_name() === 'cli';
$log = fn($m) => print($cli ? "$m\n" : "<p>$m</p>");
$now = date('Y-m-d H:i:s');

/** losowy float z zakresu */
function rf(float $a, float $b, int $dec = 2): float { return round($a + mt_rand() / mt_getrandmax() * ($b - $a), $dec); }

$pdo->beginTransaction();

// --- konta ---
$pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,0,'player',?)")
    ->execute(['gracz', password_hash('haslo123', PASSWORD_DEFAULT), $cfg['starting_cash']]);
$pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash) VALUES (?,?,0,'admin',0)")
    ->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
Engine::setState('tick', '0');
Engine::setState('sentiment', '0');
Engine::setState('ticks_per_month', '20');
$log("✔ konta: gracz/haslo123, admin/admin123");

// --- SEKTORY (8 branż) ---
// symbol, nazwa, market_beta, volatility, growth, news_sensitivity + [liczba spółek, C/Z min-max]
$sectors = [
    'TECH' => ['Technologie',     1.4, 1.5, 0.03, 1.6, 8, [18, 28]],
    'BIO'  => ['Biotechnologia',  1.8, 2.2, 0.04, 2.0, 7, [14, 24]],
    'GAM'  => ['Gry i media',     1.6, 1.9, 0.03, 1.8, 6, [16, 30]],
    'LUX'  => ['Dobra luksusowe', 1.2, 1.6, 0.01, 1.4, 5, [14, 22]],
    'IND'  => ['Przemysł',        1.0, 1.0, 0.00, 0.9, 7, [8, 14]],
    'FIN'  => ['Finanse',         1.1, 0.9, 0.01, 1.1, 6, [8, 13]],
    'ENE'  => ['Energetyka',      0.8, 1.1, 0.00, 1.0, 5, [10, 16]],
    'HAN'  => ['Handel',          0.9, 1.0, 0.01, 1.0, 6, [10, 18]],
];
$secStmt = $pdo->prepare("INSERT INTO sectors (symbol,name,market_beta,volatility,growth,news_sensitivity) VALUES (?,?,?,?,?,?)");
$secId = [];
foreach ($sectors as $sym => $s) { $secStmt->execute([$sym, $s[0], $s[1], $s[2], $s[3], $s[4]]); $secId[$sym] = (int) $pdo->lastInsertId(); }
$log("✔ " . count($sectors) . " sektorów");

// --- GENERATOR NAZW (człony per branża -> losowe, unikalne nazwy i tickery) ---
$parts = [
    'TECH' => [['Cyber','Data','Pixel','Quant','Nano','Neo','Soft','Byte','Cloud','Astra','Vertex','Hexa','Nova','Synte','Digi','Logi'],
               ['Soft','Tech','Sys','Logic','Ware','Net','Lab','Code','Core','Link','Works','Dynamics']],
    'BIO'  => [['Gen','Bio','Medi','Vita','Neuro','Cell','Immuno','Helio','Onko','Kardio','Derma','Proteo'],
               ['Pharm','Gen','Med','Lab','Cure','Plex','Zyme','Vita','Tech','Care']],
    'GAM'  => [['Game','Play','Story','Dream','Epic','Retro','Mega','Dark','Star','Wolf','Forge','Hype'],
               ['Studio','Games','Play','Media','Works','Arts','Interactive','Soft','Vision','Box']],
    'LUX'  => [['Aurum','Velvet','Prestige','Royal','Diament','Opal','Grand','Eleganza','Platyna','Karat'],
               [' Moda',' Motors',' Brands',' Estates',' Jewels',' Design',' Fashion',' House']],
    'IND'  => [['Stal','Mega','Poli','Ferro','Beton','Chemo','Metalo','Techno','Kruszo','Silo','Turbo','Prefa'],
               ['Bud','Chem','Stal','Metal','Prod','Mont','Plast','Mech','Tech','Mix']],
    'FIN'  => [['Kapital','Inwest','Kredo','Fides','Skarb','Certus','Solid','Meritum','Aureus','Lokata'],
               [' Bank',' Invest',' Capital',' Finanse',' Leasing',' Fundusz',' Broker',' Ubezpieczenia']],
    'ENE'  => [['Energo','Solar','Wiatro','Atomo','Gazo','Hydro','Termo','Volt','Grid','Helio'],
               ['Energia','Power','Gaz','Grid','Term','Watt','Volt','Net']],
    'HAN'  => [['Marko','Delika','Panda','Bazar','Kupiec','Galeria','Frux','Smako','Argo','Vendo','Prima','Cena'],
               ['Pol','Market','Trade',' Retail','Shop','Express','Hurt','Dystrybucja']],
];
$descTpl = [
    'TECH' => 'Tworzy oprogramowanie i rozwiązania chmurowe dla biznesu.',
    'BIO'  => 'Prowadzi badania nad nowymi terapiami i lekami.',
    'GAM'  => 'Produkuje gry wideo i treści rozrywkowe.',
    'LUX'  => 'Projektuje i sprzedaje dobra luksusowe z najwyższej półki.',
    'IND'  => 'Produkuje komponenty i materiały dla przemysłu.',
    'FIN'  => 'Świadczy usługi bankowe, inwestycyjne i ubezpieczeniowe.',
    'ENE'  => 'Wytwarza i dystrybuuje energię dla firm i gospodarstw.',
    'HAN'  => 'Prowadzi sieć sprzedaży detalicznej i e-commerce.',
];
$cities = ['Warszawie', 'Krakowie', 'Wrocławiu', 'Poznaniu', 'Gdańsku', 'Katowicach', 'Łodzi', 'Szczecinie', 'Lublinie', 'Rzeszowie'];

$usedNames = []; $usedTickers = [];
$makeName = function (string $sym) use ($parts, &$usedNames): string {
    [$pre, $suf] = $parts[$sym];
    for ($try = 0; $try < 200; $try++) {
        $name = $pre[array_rand($pre)] . $suf[array_rand($suf)];
        if (strtolower(substr($name, 0, 4)) === strtolower(trim(substr($name, -4)))) continue; // unikaj "BioBio"
        if (!isset($usedNames[strtolower($name)])) { $usedNames[strtolower($name)] = 1; return $name; }
    }
    return 'Spolka' . count($usedNames);
};
$makeTicker = function (string $name) use (&$usedTickers): string {
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name);
    if ($ascii === false || $ascii === '') $ascii = $name;                 // fallback, gdy iconv nie działa na hostingu
    $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $ascii));
    if (strlen($letters) < 2) $letters = 'X' . strtoupper(substr(md5($name), 0, 3));
    $candidates = [substr($letters, 0, 3), substr($letters, 0, 2) . substr($letters, -1), substr($letters, 0, 4)];
    foreach ($candidates as $t) { if (strlen($t) >= 2 && !isset($usedTickers[$t])) { $usedTickers[$t] = 1; return $t; } }
    for ($i = 2; $i < 100; $i++) { $t = substr($letters, 0, 3) . $i; if (!isset($usedTickers[$t])) { $usedTickers[$t] = 1; return $t; } }
    return 'X' . mt_rand(100, 999);
};

// --- 50 SPÓŁEK ---
$stStmt = $pdo->prepare("INSERT INTO stocks
    (ticker,name,sector_id,description,price,fundamental,day_open_price,total_shares,free_float,
     beta,volatility,liquidity,news_impact,news_frequency,financial_resilience,growth_potential,aggressiveness,
     pe_target,base_profit,last_profit,last_eps,report_period,next_report_tick)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
$i = 0;
foreach ($sectors as $sym => $sec) {
    [$secName, $mbeta, $mvol, , , $count, $peRange] = $sec;
    for ($k = 0; $k < $count; $k++) {
        $name = $makeName($sym);
        $ticker = $makeTicker($name);
        // cena: ważone przedziały (małe/średnie/duże/premium)
        $r = mt_rand(1, 100);
        $price = $r <= 40 ? rf(8, 60) : ($r <= 70 ? rf(60, 200) : ($r <= 90 ? rf(200, 500) : rf(500, 900)));
        // liczba akcji z docelowej kapitalizacji 30M–900M
        $shares = max(200000, min(20000000, (int) round(mt_rand(30, 900) * 1e6 / $price)));
        $pe = rf($peRange[0], $peRange[1], 1);
        $base = round($price * $shares / ($pe * 12), 2);       // miesięczny zysk spójny z ceną i C/Z
        $eps = round($base * 12 / $shares, 4);
        $desc = $descTpl[$sym] . ' Siedziba w ' . $cities[array_rand($cities)] . '.';
        $stStmt->execute([
            $ticker, $name, $secId[$sym], $desc, $price, $price, $price, $shares, rf(30, 95, 0),
            rf($mbeta - 0.3, $mbeta + 0.3), rf($mvol * 0.7, $mvol * 1.3), rf(0.7, 1.5), rf(0.8, 1.8),
            rf(0.5, 2.0), rf(0.6, 1.4), rf(0, 0.04, 3), rf(0.7, 2.0),
            $pe, $base, $base, $eps, 20, 3 + ($i * 2) % 40,    // raporty rozłożone na ~2 mies.
        ]);
        $i++;
    }
}
$stockRows = Engine::all("SELECT id, price, base_profit, last_eps FROM stocks ORDER BY id");
$log("✔ " . count($stockRows) . " spółek (nazwy generowane losowo)");

// --- historia startowa (płaskie świece) ---
$cStmt = $pdo->prepare("INSERT INTO candles (stock_id,t,o,h,l,c,v) VALUES (?,?,?,?,?,?,0)");
foreach ($stockRows as $st) for ($t = -25; $t < 0; $t++) $cStmt->execute([$st['id'], $t, $st['price'], $st['price'], $st['price'], $st['price']]);

// --- raport startowy (Miesiąc 0) ---
$rStmt = $pdo->prepare("INSERT INTO financial_reports (stock_id,tick,period,report_date,revenue,costs,net_profit,eps,expected_eps,surprise_pct)
                        VALUES (?,0,'Miesiąc 0',?,?,?,?,?,?,0)");
foreach ($stockRows as $st) {
    $profit = (float) $st['base_profit']; $rev = round($profit / 0.15, 2); $cost = round($rev - $profit, 2);
    $rStmt->execute([$st['id'], $now, $rev, $cost, $profit, $st['last_eps'], $st['last_eps']]);
}
$log("✔ raporty startowe");

// --- SZABLONY NEWSÓW / ESPI ---
$tpl = [
    ['Analitycy podnoszą rekomendację dla [T]', 'Dom maklerski podniósł cenę docelową akcji [T].', 'POS', 'COMPANY', 0, 0.15, 12, 20],
    ['[T] ogłasza program skupu akcji własnych', 'Zarząd [T] poinformował o skupie akcji.', 'POS', 'COMPANY', 1, 0.30, 15, 6],
    ['[T] podpisuje duży kontrakt', 'Spółka [T] zawarła znaczącą umowę handlową.', 'POS', 'COMPANY', 1, 0.35, 12, 8],
    ['[T] zapowiada wejście na nowe rynki', 'Zarząd [T] przedstawił plan ekspansji zagranicznej.', 'POS', 'COMPANY', 1, 0.20, 14, 8],
    ['Odejście kluczowego dyrektora w [T]', 'Z [T] odchodzi wieloletni członek zarządu.', 'NEG', 'COMPANY', 1, -0.25, 12, 8],
    ['Postępowanie regulacyjne wobec [T]', 'Wobec [T] wszczęto postępowanie wyjaśniające.', 'NEG', 'COMPANY', 1, -0.30, 10, 6],
    ['[T] ostrzega przed słabszym kwartałem', 'Spółka [T] obniżyła prognozy wyników.', 'NEG', 'COMPANY', 1, -0.28, 12, 6],
    ['Optymizm w sektorze [T]', 'Inwestorzy pozytywnie patrzą na branżę [T].', 'POS', 'SECTOR', 0, 0.12, 14, 25],
    ['Niepewność w sektorze [T]', 'Nad branżą [T] zbierają się chmury.', 'NEG', 'SECTOR', 0, -0.15, 14, 25],
    ['Spokojna sesja dla [T]', 'Notowania [T] pozostają stabilne.', 'NEU', 'COMPANY', 0, 0.00, 30, 15],
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
        $dStmt->execute([$uid, $role, rf(0.5, 2.0), rf(0.5, 2.0), rf(0.5, 2.0), mt_rand(5, 30)]);
        foreach ($stockRows as $st) $wStmt->execute([$uid, $st['id'], $shares, $st['price']]);
    }
};
$mk('mm', 12, 8000000, 3000);
$mk('trend', 9, 1500000, 300);
$mk('rsi', 9, 1500000, 300);
$log("✔ 30 botów (mm/trend/rsi) + DNA");

// --- gracz dostaje akcje na start ---
Engine::ensureWallet(1, (int) $stockRows[0]['id']);
$pdo->prepare("UPDATE wallets SET qty=100, avg_price=? WHERE user_id=1 AND stock_id=?")
    ->execute([$stockRows[0]['price'], $stockRows[0]['id']]);

$pdo->commit();
$log("✅ Świat zasiany.");
