<?php
/**
 * Test integralności ekonomicznej między przebiegami: php verify.php
 * Zapamiętuje stan z poprzedniego uruchomienia w game_state (verify_*) i porównuje z bieżącym:
 *  - suma pieniądza w świecie może wzrosnąć WYŁĄCZNIE o wypłacone od tamtej pory dywidendy,
 *  - liczba akcji każdej spółki nie może się zmienić (poza debiutami IPO, które dokładają nowe spółki),
 *  - brak ujemnych sald i rezerwacji.
 * Pierwszy przebieg tylko zapisuje punkt odniesienia. (Dawna wersja porównywała tę samą wartość
 * ze sobą i miała „OK" wpisane na sztywno — nie mogła nic wykryć.)
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Forbidden'); }
require __DIR__ . '/src/Db.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/Log.php';
require __DIR__ . '/src/Engine.php';

function spark(array $v): string { $g=['▁','▂','▃','▄','▅','▆','▇','█']; $mn=min($v);$mx=max($v);$r=($mx-$mn)?:1;$o='';foreach($v as $x)$o.=$g[(int)round(($x-$mn)/$r*7)];return $o; }
$ok = fn($b) => $b ? "✅ OK" : "❌ BŁĄD";

echo "\n=== KURSY (od zasiania do teraz) ===\n";
printf("%-5s %-16s %9s %9s %8s   %s\n", "Tick", "Spółka", "Start", "Teraz", "Zmiana", "Sparkline");
foreach (Engine::all("SELECT id, ticker, name FROM stocks") as $st) {
    $ser = Engine::col("SELECT c FROM candles WHERE stock_id=? AND t>=1 ORDER BY t ASC", [$st['id']]);
    if (!$ser) { echo "  (brak świec — najpierw uruchom cron/tick.php)\n"; continue; }
    $start = $ser[0]; $now = end($ser); $chg = ($now - $start) / $start * 100;
    printf("%-5d %-16s %9.2f %9.2f %+7.1f%%   %s\n", count($ser), $st['ticker'], $start, $now, $chg, spark($ser));
}

// bieżący stan świata
$world = Engine::worldCash();   // ta sama formuła, którą sprawdza QA (inv.money)
$div = (float) (Engine::one("SELECT v FROM game_state WHERE k='dividends_paid'") ?: 0);
$base = Engine::one("SELECT v FROM game_state WHERE k='world_cash_base'");
$shares = [];
foreach (Engine::all("SELECT id, ticker FROM stocks") as $st) {
    $shares[$st['ticker']] = (int) Engine::one("SELECT COALESCE(SUM(qty+qty_reserved),0) FROM wallets WHERE stock_id=?", [$st['id']]);
}
$negCash = (int) Engine::one("SELECT COUNT(*) FROM users WHERE cash < -0.01 OR cash_reserved < -0.01");
$negQty  = (int) Engine::one("SELECT COUNT(*) FROM wallets WHERE qty < 0 OR qty_reserved < 0");

$prevWorld  = Engine::one("SELECT v FROM game_state WHERE k='verify_world'");
$prevDiv    = (float) (Engine::one("SELECT v FROM game_state WHERE k='verify_dividends'") ?: 0);
// Lista akcji spółek nie mieści się w jednym game_state.v (VARCHAR 255 na MySQL — 76+ spółek to ~1000 znaków JSON,
// INSERT kończył się błędem 1406 i kontrola „akcje zachowane” była na MySQL martwa), więc JSON leży pociachany
// na kawałki po 200 znaków pod kluczami verify_shares_0, verify_shares_1, … — nadal w TEJ bazie (a nie w pliku,
// który mieszałby przebiegi SQLite i MySQL).
$prevJson = '';
for ($i = 0; ; $i++) {
    $chunk = Engine::one("SELECT v FROM game_state WHERE k=?", ["verify_shares_$i"]);
    if ($chunk === false || $chunk === null || $chunk === '') break;
    $prevJson .= (string) $chunk;
}
$prevShares = json_decode($prevJson ?: '[]', true) ?: [];

echo "\n=== INTEGRALNOŚĆ (względem poprzedniego przebiegu) ===\n";
if ($prevWorld === false || $prevWorld === null) {
    printf("%-46s %s\n", "Punkt odniesienia:", "zapisany — uruchom ponownie po kilku tickach, żeby porównać");
} else {
    $expected = (float) $prevWorld + ($div - $prevDiv);
    printf("%-46s %s (%s PLN; dywidendy od ostatniego razu: %s PLN)\n", "Pieniądz w świecie = poprzedni + dywidendy:",
        $ok(abs($world - $expected) < 0.5), number_format($world, 2, ',', ' '), number_format($div - $prevDiv, 2, ',', ' '));
    foreach ($shares as $tk => $n) {
        if (!array_key_exists($tk, $prevShares)) { printf("%-46s %s\n", "Akcje $tk zachowane:", "— (nowa spółka od ostatniego przebiegu)"); continue; }
        printf("%-46s %s (w portfelach: $n)\n", "Akcje $tk zachowane:", $ok($n === (int) $prevShares[$tk]));
    }
}
if ($base !== false && $base !== null && $base !== '') {
    // kotwica (zasiew / pierwszy QA, przesuwana przy rejestracji i IPO) + dywidendy = cała gotówka w świecie
    printf("%-46s %s (różnica %s PLN)\n", "Pieniądz w świecie = kotwica + dywidendy:", $ok(abs($world - ((float) $base + $div)) < 1.0), number_format($world - ((float) $base + $div), 2, ',', ' '));
}
printf("%-46s %s\n", "Brak ujemnych sald/rezerwacji gotówki:", $ok($negCash === 0));
printf("%-46s %s\n", "Brak ujemnych ilości akcji/rezerwacji:", $ok($negQty === 0));
printf("%-46s %d\n", "Zrealizowanych transakcji:", (int) Engine::one("SELECT COUNT(*) FROM transactions"));

Engine::setState('verify_world', (string) round($world, 2));
Engine::setState('verify_dividends', (string) $div);
// stare kawałki precz (mniej spółek niż poprzednio = krótszy JSON), potem zapis po kawałku
Db::pdo()->exec("DELETE FROM game_state WHERE k LIKE 'verify_shares%'");
foreach (str_split(json_encode($shares), 200) as $i => $chunk) Engine::setState("verify_shares_$i", $chunk);
echo "\n";
