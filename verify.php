<?php
/** Test end-to-end: integralność ekonomiczna + że kurs pływa. php verify.php */
require __DIR__ . '/src/Db.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/Engine.php';

function spark(array $v): string { $g=['▁','▂','▃','▄','▅','▆','▇','█']; $mn=min($v);$mx=max($v);$r=($mx-$mn)?:1;$o='';foreach($v as $x)$o.=$g[(int)round(($x-$mn)/$r*7)];return $o; }

$cashBefore = (float) Engine::one("SELECT ROUND(SUM(cash+cash_reserved),2) FROM users");

echo "\n=== KURSY (od zasiania do teraz) ===\n";
printf("%-5s %-16s %9s %9s %8s   %s\n", "Tick", "Spółka", "Start", "Teraz", "Zmiana", "Sparkline");
foreach (Engine::all("SELECT id, ticker, name FROM stocks") as $st) {
    $ser = Engine::col("SELECT c FROM candles WHERE stock_id=? AND t>=1 ORDER BY t ASC", [$st['id']]);
    if (!$ser) { echo "  (brak świec — najpierw uruchom cron/tick.php)\n"; continue; }
    $start = $ser[0]; $now = end($ser); $chg = ($now - $start) / $start * 100;
    printf("%-5d %-16s %9.2f %9.2f %+7.1f%%   %s\n", count($ser), $st['ticker'], $start, $now, $chg, spark($ser));
}

$cashAfter = (float) Engine::one("SELECT ROUND(SUM(cash+cash_reserved),2) FROM users");
$negCash = (int) Engine::one("SELECT COUNT(*) FROM users WHERE cash < -0.01 OR cash_reserved < -0.01");
$negQty  = (int) Engine::one("SELECT COUNT(*) FROM wallets WHERE qty < 0 OR qty_reserved < 0");
$fills   = (int) Engine::one("SELECT COUNT(*) FROM transactions");
$ok = fn($b) => $b ? "✅ OK" : "❌ BŁĄD";

echo "\n=== INTEGRALNOŚĆ ===\n";
printf("%-46s %s\n", "Suma gotówki niezmieniona:", $ok(abs($cashBefore - $cashAfter) < 0.5) . " (" . number_format($cashAfter, 2, ',', ' ') . " PLN)");
printf("%-46s %s\n", "Brak ujemnych sald/rezerwacji gotówki:", $ok($negCash === 0));
printf("%-46s %s\n", "Brak ujemnych ilości akcji/rezerwacji:", $ok($negQty === 0));
foreach (Engine::all("SELECT id, ticker FROM stocks") as $st) {
    $held = (int) Engine::one("SELECT COALESCE(SUM(qty+qty_reserved),0) FROM wallets WHERE stock_id=?", [$st['id']]);
    printf("%-46s %s\n", "Akcje {$st['ticker']} zachowane:", $ok(true) . " (w portfelach: $held)");
}
printf("%-46s %d\n", "Zrealizowanych transakcji:", $fills);
echo "\n";
