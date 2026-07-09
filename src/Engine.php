<?php
/**
 * Silnik rynku — JEDEN, spójny. Przeniesiony z zweryfikowanego prototypu.
 * Gwarancje (pilnowane testami integralności):
 *   - poprawny escrow: kupno rezerwuje gotówkę, sprzedaż rezerwuje akcje,
 *   - realizacja po cenie zlecenia oczekującego + zwrot nadpłaty kupującemu,
 *   - suma gotówki i liczba akcji są niezmienne (nic się nie drukuje/nie ginie),
 *   - pełne rozliczanie księgi (priorytet cena/czas), brak handlu z samym sobą,
 *   - poprawna średnia cena zakupu, upsert portfela po UNIQUE(user_id, stock_id),
 *   - działająca egzekucja Stop-Loss / Take-Profit.
 */
final class Engine
{
    /* ---------- Zlecenia gracza / botów ---------- */

    public static function place(int $userId, int $stockId, string $side, int $qty, float $price): array
    {
        $side = strtolower($side);
        if (!in_array($side, ['buy', 'sell'], true)) return [false, 'Nieznany typ zlecenia.'];
        if ($qty <= 0 || $price <= 0)               return [false, 'Nieprawidłowa ilość lub cena.'];
        $pdo = Db::pdo();

        if ($side === 'buy') {
            $cost = round($qty * $price, 2);
            $cash = (float) self::one("SELECT cash FROM users WHERE id=?", [$userId]);
            if ($cash + 1e-6 < $cost) return [false, "Za mało gotówki. Masz " . number_format($cash, 2, ',', ' ') . " PLN, potrzeba " . number_format($cost, 2, ',', ' ') . " PLN."];
            $pdo->prepare("UPDATE users SET cash=cash-?, cash_reserved=cash_reserved+? WHERE id=?")->execute([$cost, $cost, $userId]);
        } else {
            self::ensureWallet($userId, $stockId);
            $avail = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$userId, $stockId]);
            if ($avail < $qty) return [false, "Nie masz tylu akcji (dostępne: $avail)."];
            $pdo->prepare("UPDATE wallets SET qty=qty-?, qty_reserved=qty_reserved+? WHERE user_id=? AND stock_id=?")->execute([$qty, $qty, $userId, $stockId]);
        }

        $pdo->prepare("INSERT INTO orders (user_id, stock_id, side, qty, price, status, created_at) VALUES (?,?,?,?,?, 'active', ?)")
            ->execute([$userId, $stockId, $side, $qty, round($price, 2), Db::now()]);
        return [true, 'Zlecenie przyjęte.'];
    }

    public static function cancel(int $orderId, int $userId): array
    {
        $pdo = Db::pdo();
        $o = self::row("SELECT * FROM orders WHERE id=? AND user_id=? AND status='active'", [$orderId, $userId]);
        if (!$o) return [false, 'Nie znaleziono aktywnego zlecenia.'];
        self::release($o);
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$orderId]);
        return [true, "Zlecenie #$orderId anulowane."];
    }

    /** zwolnij rezerwację pozostałej części zlecenia */
    private static function release(array $o): void
    {
        $pdo = Db::pdo();
        if ($o['side'] === 'buy') {
            $back = round($o['qty'] * $o['price'], 2);
            $pdo->prepare("UPDATE users SET cash=cash+?, cash_reserved=cash_reserved-? WHERE id=?")->execute([$back, $back, $o['user_id']]);
        } else {
            $pdo->prepare("UPDATE wallets SET qty=qty+?, qty_reserved=qty_reserved-? WHERE user_id=? AND stock_id=?")->execute([$o['qty'], $o['qty'], $o['user_id'], $o['stock_id']]);
        }
    }

    /* ---------- Kojarzenie zleceń (pełne rozliczenie księgi) ---------- */

    public static function matchBook(int $stockId, array &$tickTrades = []): int
    {
        $pdo = Db::pdo();
        $buys  = self::all("SELECT * FROM orders WHERE stock_id=? AND side='buy'  AND status='active' ORDER BY price DESC, id ASC", [$stockId]);
        $sells = self::all("SELECT * FROM orders WHERE stock_id=? AND side='sell' AND status='active' ORDER BY price ASC,  id ASC", [$stockId]);
        $trades = 0;

        foreach ($buys as &$b) {
            if ($b['qty'] <= 0) continue;
            foreach ($sells as &$s) {
                if ($s['qty'] <= 0) continue;
                if ($b['user_id'] == $s['user_id']) continue;      // brak handlu z samym sobą
                if ($b['price'] < $s['price']) break;              // dalej już nie skrzyżuje

                $p   = (float) $s['price'];
                $q   = (int) min($b['qty'], $s['qty']);
                $val = round($q * $p, 2);

                // kupujący: zwolnij rezerwę po SWOIM limicie, zwróć nadpłatę (limit - p)
                $refund = round($q * ($b['price'] - $p), 2);
                $pdo->prepare("UPDATE users SET cash_reserved=cash_reserved-?, cash=cash+? WHERE id=?")
                    ->execute([round($q * $b['price'], 2), $refund, $b['user_id']]);
                // sprzedający: dostaje gotówkę, zdejmij rezerwację akcji
                $pdo->prepare("UPDATE users SET cash=cash+? WHERE id=?")->execute([$val, $s['user_id']]);
                $pdo->prepare("UPDATE wallets SET qty_reserved=qty_reserved-? WHERE user_id=? AND stock_id=?")->execute([$q, $s['user_id'], $stockId]);
                // kupujący: dopisz akcje + poprawna średnia cena
                self::ensureWallet($b['user_id'], $stockId);
                $bw = self::row("SELECT qty, avg_price FROM wallets WHERE user_id=? AND stock_id=?", [$b['user_id'], $stockId]);
                $nq = $bw['qty'] + $q;
                $navg = $nq > 0 ? round((($bw['qty'] * $bw['avg_price']) + $val) / $nq, 4) : 0;
                $pdo->prepare("UPDATE wallets SET qty=?, avg_price=? WHERE user_id=? AND stock_id=?")->execute([$nq, $navg, $b['user_id'], $stockId]);

                // redukuj zlecenia
                $b['qty'] -= $q; $s['qty'] -= $q;
                $pdo->prepare("UPDATE orders SET qty=?, status=? WHERE id=?")->execute([$b['qty'], $b['qty'] <= 0 ? 'filled' : 'active', $b['id']]);
                $pdo->prepare("UPDATE orders SET qty=?, status=? WHERE id=?")->execute([$s['qty'], $s['qty'] <= 0 ? 'filled' : 'active', $s['id']]);

                // kurs = ostatnia cena transakcji + log
                $pdo->prepare("UPDATE stocks SET price=? WHERE id=?")->execute([$p, $stockId]);
                $pdo->prepare("INSERT INTO transactions (stock_id, buyer_id, seller_id, qty, price, created_at) VALUES (?,?,?,?,?,?)")
                    ->execute([$stockId, $b['user_id'], $s['user_id'], $q, $p, Db::now()]);
                $tickTrades[$stockId][] = ['p' => $p, 'q' => $q];
                $trades++;
                if ($b['qty'] <= 0) break;
            }
        }
        return $trades;
    }

    /* ---------- Boty ---------- */

    public static function runBots(): void
    {
        $pdo = Db::pdo();
        $bots   = self::all("SELECT id, role FROM users WHERE is_bot=1");
        $stocks = self::all("SELECT id, price, fundamental FROM stocks");

        foreach ($bots as $bot) self::cancelAllFor((int) $bot['id']);

        foreach ($bots as $bot) {
            $uid = (int) $bot['id'];
            foreach ($stocks as $st) {
                $sid = (int) $st['id'];
                if ($bot['role'] === 'mm') {
                    $base = 0.7 * $st['fundamental'] + 0.3 * $st['price'];
                    $base *= 1 + mt_rand(-40, 40) / 10000;
                    foreach ([[0.010, 60], [0.022, 40], [0.038, 25]] as [$sp, $qy]) {
                        self::place($uid, $sid, 'buy',  $qy, round($base * (1 - $sp), 2));
                        self::place($uid, $sid, 'sell', $qy, round($base * (1 + $sp), 2));
                    }
                } elseif ($bot['role'] === 'trend') {
                    $c = self::closes($sid, 20);
                    if (count($c) < 20) continue;
                    $short = self::sma(array_slice($c, -5)); $long = self::sma($c);
                    $have = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]);
                    if ($short > $long * 1.01 && $have < 300)      self::place($uid, $sid, 'buy',  60, round($st['price'] * 1.02, 2));
                    elseif ($short < $long * 0.99 && $have > 0)     self::place($uid, $sid, 'sell', min(120, $have), round($st['price'] * 0.98, 2));
                } elseif ($bot['role'] === 'rsi') {
                    $c = self::closes($sid, 100);
                    if (count($c) < 15) continue;
                    $r = self::rsi($c);
                    $have = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]);
                    if ($r < 30 && $have < 300)     self::place($uid, $sid, 'buy',  80, round($st['price'] * 1.02, 2));
                    elseif ($r > 70 && $have > 0)   self::place($uid, $sid, 'sell', min(120, $have), round($st['price'] * 0.98, 2));
                }
            }
        }
    }

    private static function cancelAllFor(int $uid): void
    {
        $pdo = Db::pdo();
        foreach (self::all("SELECT * FROM orders WHERE user_id=? AND status='active'", [$uid]) as $o) self::release($o);
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE user_id=? AND status='active'")->execute([$uid]);
    }

    /**
     * Presja arbitrażowa: gdy kurs odbiega od wartości fundamentalnej (sterowanej przez
     * bias/sentiment/eventy w panelu GM), losowy market maker krzyżuje spread w stronę
     * fundamentu. Kurs nadal powstaje z arkusza (transakcja po realnej cenie zlecenia),
     * ale rynek realnie podąża za sterowaniem.
     */
    private static function arbitrage(): void
    {
        $bots = self::col("SELECT id FROM users WHERE is_bot=1 AND role='mm'");
        if (!$bots) return;
        foreach (self::all("SELECT id, price, fundamental FROM stocks") as $st) {
            $sid = (int) $st['id']; $price = (float) $st['price']; $fund = (float) $st['fundamental'];
            if (abs($fund - $price) < $price * 0.001) continue;          // już blisko wartości godziwej
            $uid = (int) $bots[array_rand($bots)];
            if ($fund > $price) {                                        // podnieś kurs: kup po najlepszym ask
                $ask = self::one("SELECT MIN(price) FROM orders WHERE stock_id=? AND side='sell' AND status='active' AND user_id<>?", [$sid, $uid]);
                if ($ask && $fund >= (float) $ask) self::place($uid, $sid, 'buy', 40, (float) $ask);
            } else {                                                     // zbij kurs: sprzedaj po najlepszym bid
                $bid  = self::one("SELECT MAX(price) FROM orders WHERE stock_id=? AND side='buy' AND status='active' AND user_id<>?", [$sid, $uid]);
                $have = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]);
                if ($bid && $have > 0 && $fund <= (float) $bid) self::place($uid, $sid, 'sell', min(40, $have), (float) $bid);
            }
        }
    }

    /* ---------- Stop-Loss / Take-Profit (realna egzekucja) ---------- */

    public static function checkStops(): void
    {
        $rows = self::all("SELECT w.*, s.price FROM wallets w JOIN stocks s ON s.id=w.stock_id
                           WHERE w.qty > 0 AND (w.sl_price IS NOT NULL OR w.tp_price IS NOT NULL)");
        foreach ($rows as $w) {
            $hitSL = $w['sl_price'] !== null && $w['price'] <= $w['sl_price'];
            $hitTP = $w['tp_price'] !== null && $w['price'] >= $w['tp_price'];
            if ($hitSL || $hitTP) {
                // sprzedaj po cenie marketowej (limit lekko poniżej, żeby się złapało)
                self::place((int) $w['user_id'], (int) $w['stock_id'], 'sell', (int) $w['qty'], round($w['price'] * 0.97, 2));
                Db::pdo()->prepare("UPDATE wallets SET sl_price=NULL, tp_price=NULL WHERE user_id=? AND stock_id=?")
                    ->execute([$w['user_id'], $w['stock_id']]);
            }
        }
    }

    /* ---------- Świece ---------- */

    public static function recordCandles(int $t, array $tickTrades): void
    {
        $pdo = Db::pdo();
        foreach (self::all("SELECT id, price FROM stocks") as $st) {
            $sid = (int) $st['id'];
            $prev = self::closes($sid, 1);
            $o = $prev ? (float) $prev[0] : (float) $st['price'];
            $tr = $tickTrades[$sid] ?? [];
            if ($tr) { $ps = array_column($tr, 'p'); $c = end($ps); $h = max($ps); $l = min($ps); $v = array_sum(array_column($tr, 'q')); }
            else     { $c = $o; $h = $o; $l = $o; $v = 0; }
            $pdo->prepare("INSERT INTO candles (stock_id,t,o,h,l,c,v) VALUES (?,?,?,?,?,?,?)")->execute([$sid, $t, $o, $h, $l, $c, $v]);
        }
    }

    /* ---------- Pełny tick świata ---------- */

    public static function runTick(): int
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();   // cały tick atomowo (i dużo szybciej na SQLite)
        try {
        $t = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?? 0) + 1;

        // dynamika ceny fundamentalnej — STEROWALNA:
        //   sentiment (globalny) + bias (per spółka) = deterministyczny dryf w %/tick
        //   vol (per spółka) = mnożnik szumu i siły szoków (newsów)
        $sentiment = (float) (self::one("SELECT v FROM game_state WHERE k='sentiment'") ?: 0);
        foreach (self::all("SELECT id, fundamental, bias, vol FROM stocks") as $st) {
            $vol   = (float) $st['vol'] > 0 ? (float) $st['vol'] : 1.0;
            $drift = ($sentiment + (float) $st['bias']) / 100.0;           // np. bias=+1 => +1%/tick
            $noise = (mt_rand(-16, 16) / 1000) * $vol;                     // szum skalowany zmiennością
            $f = $st['fundamental'] * (1 + $drift + $noise);
            if (mt_rand(1, 100) <= 15) $f *= 1 + (mt_rand(-100, 100) / 1000) * $vol;  // szok/news
            $pdo->prepare("UPDATE stocks SET fundamental=? WHERE id=?")->execute([max(1, round($f, 2)), $st['id']]);
        }

        self::checkStops();
        self::runBots();
        self::arbitrage();   // boty domykają lukę kurs -> wartość fundamentalna (kurs podąża za sterowaniem)

        $tickTrades = [];
        foreach (self::all("SELECT id FROM stocks") as $st) self::matchBook((int) $st['id'], $tickTrades);
        self::recordCandles($t, $tickTrades);

        self::setState('tick', (string) $t);
        $pdo->commit();
        return $t;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /* ---------- Sygnały ---------- */

    public static function closes(int $stockId, int $n): array
    {
        return array_reverse(self::col("SELECT c FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT $n", [$stockId]));
    }
    public static function sma(array $a): float { return $a ? array_sum($a) / count($a) : 0; }
    public static function rsi(array $p, int $period = 14): float
    {
        if (count($p) < $period + 1) return 50;
        $g = $l = 0;
        for ($i = 1; $i <= $period; $i++) { $d = $p[$i] - $p[$i - 1]; $d >= 0 ? $g += $d : $l -= $d; }
        $ag = $g / $period; $al = $l / $period;
        for ($i = $period + 1; $i < count($p); $i++) {
            $d = $p[$i] - $p[$i - 1]; $cg = $d > 0 ? $d : 0; $cl = $d < 0 ? -$d : 0;
            $ag = ($ag * ($period - 1) + $cg) / $period; $al = ($al * ($period - 1) + $cl) / $period;
        }
        return $al == 0 ? 100 : 100 - 100 / (1 + $ag / $al);
    }

    /* ---------- Małe helpery bazodanowe ---------- */

    public static function ensureWallet(int $userId, int $stockId): void
    {
        $exists = self::one("SELECT id FROM wallets WHERE user_id=? AND stock_id=?", [$userId, $stockId]);
        if (!$exists) Db::pdo()->prepare("INSERT INTO wallets (user_id, stock_id) VALUES (?,?)")->execute([$userId, $stockId]);
    }
    public static function setState(string $k, string $v): void
    {
        $pdo = Db::pdo();
        $has = self::one("SELECT k FROM game_state WHERE k=?", [$k]);
        if ($has) $pdo->prepare("UPDATE game_state SET v=? WHERE k=?")->execute([$v, $k]);
        else      $pdo->prepare("INSERT INTO game_state (k, v) VALUES (?,?)")->execute([$k, $v]);
    }

    private static function stmt(string $sql, array $a): PDOStatement { $s = Db::pdo()->prepare($sql); $s->execute($a); return $s; }
    public static function all(string $sql, array $a = []): array { return self::stmt($sql, $a)->fetchAll(); }
    public static function row(string $sql, array $a = []): ?array { $r = self::stmt($sql, $a)->fetch(); return $r ?: null; }
    public static function one(string $sql, array $a = []) { return self::stmt($sql, $a)->fetchColumn(); }
    public static function col(string $sql, array $a = []): array { return self::stmt($sql, $a)->fetchAll(PDO::FETCH_COLUMN); }
}
