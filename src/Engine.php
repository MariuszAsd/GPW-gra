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

    /** Zlecenie limit. $expiresSession: NULL = bezterminowe, N = ważne do końca sesji N. */
    public static function place(int $userId, int $stockId, string $side, int $qty, float $price, ?int $expiresSession = null): array
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

        $pdo->prepare("INSERT INTO orders (user_id, stock_id, side, qty, qty_init, price, status, expires_session, created_at) VALUES (?,?,?,?,?,?, 'active', ?, ?)")
            ->execute([$userId, $stockId, $side, $qty, $qty, round($price, 2), $expiresSession, Db::now()]);
        return [true, 'Zlecenie przyjęte.', (int) $pdo->lastInsertId()];
    }

    /**
     * Zlecenie PKC ("po każdej cenie") — realizacja natychmiast z arkusza.
     * KUPNO: jedno agresywne zlecenie limit po najgorszej osiągalnej cenie (arkusz + budżet gotówki);
     *        matchBook rozlicza każdy poziom po JEGO cenie i zwraca nadpłatę — kupujący płaci realne ceny.
     * SPRZEDAŻ: poziom po poziomie po cenie najlepszego bidu (matchBook rozlicza po cenie zlecenia sprzedaży,
     *           więc limit = bid gwarantuje sprzedaż dokładnie po cenach z arkusza).
     * Niezrealizowana reszta jest anulowana (jak IOC). Zwraca [ok, komunikat].
     */
    public static function marketOrder(int $userId, int $stockId, string $side, int $qty): array
    {
        $side = strtolower($side);
        if (!in_array($side, ['buy', 'sell'], true)) return [false, 'Nieznany typ zlecenia.'];
        if ($qty <= 0) return [false, 'Nieprawidłowa ilość.'];
        $pdo = Db::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            [$session] = self::sessionInfo();
            $txFrom = (int) (self::one("SELECT MAX(id) FROM transactions") ?: 0);

            if ($side === 'buy') {
                $cash = (float) self::one("SELECT cash FROM users WHERE id=?", [$userId]);
                // ile zdołamy kupić: idź po poziomach ask; escrow liczony po najgorszej cenie,
                // więc na każdym poziomie L pilnujemy (dotychczas + biorę) * cena_L <= gotówka
                $take = 0; $worst = 0.0; $need = $qty;
                foreach (self::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='sell' AND status='active' AND user_id<>? GROUP BY price ORDER BY price ASC LIMIT 40", [$stockId, $userId]) as $lvl) {
                    $p = (float) $lvl['price'];
                    $can = (int) floor(($cash + 1e-6) / $p) - $take;
                    $t = min($need, (int) $lvl['q'], max(0, $can));
                    if ($t <= 0) break;
                    $take += $t; $need -= $t; $worst = $p;
                    if ($need <= 0) break;
                }
                if ($take <= 0) { if ($own) $pdo->rollBack(); return [false, 'PKC: brak ofert sprzedaży w arkuszu (albo za mało gotówki na najtańszą).']; }
                [$ok, $msg, $oid] = self::place($userId, $stockId, 'buy', $take, $worst, $session) + [2 => 0];
                if (!$ok) { if ($own) $pdo->rollBack(); return [false, $msg]; }
                self::matchBook($stockId);
                self::cancelRemainder((int) $oid, $userId);
            } else {
                self::ensureWallet($userId, $stockId);
                $have = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$userId, $stockId]);
                if ($have <= 0) { if ($own) $pdo->rollBack(); return [false, 'PKC: nie masz akcji tej spółki.']; }
                $remaining = min($qty, $have);
                for ($i = 0; $i < 40 && $remaining > 0; $i++) {
                    $best = self::row("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='buy' AND status='active' AND user_id<>? GROUP BY price ORDER BY price DESC LIMIT 1", [$stockId, $userId]);
                    if (!$best) break;
                    $t = min($remaining, (int) $best['q']);
                    [$ok, $msg, $oid] = self::place($userId, $stockId, 'sell', $t, (float) $best['price'], $session) + [2 => 0];
                    if (!$ok) break;
                    self::matchBook($stockId);
                    $left = self::cancelRemainder((int) $oid, $userId);
                    $remaining -= ($t - $left);
                    if ($left >= $t) break;   // nic nie zeszło (poziom zniknął) — nie kręć się w miejscu
                }
                if ($remaining >= min($qty, $have)) { if ($own) $pdo->rollBack(); return [false, 'PKC: brak ofert kupna w arkuszu.']; }
            }

            // podsumowanie z realnych transakcji tego wywołania
            $who = $side === 'buy' ? 'buyer_id' : 'seller_id';
            $sum = self::row("SELECT SUM(qty) q, SUM(qty*price) v FROM transactions WHERE $who=? AND stock_id=? AND id>?", [$userId, $stockId, $txFrom]);
            if ($own) $pdo->commit();
            $q = (int) ($sum['q'] ?? 0); $v = (float) ($sum['v'] ?? 0);
            if ($q <= 0) return [false, 'PKC: nie udało się zrealizować transakcji.'];
            $avg = number_format($v / $q, 2, ',', ' ');
            $note = $q < $qty ? " (reszta z $qty szt. anulowana — arkusz/gotówka nie pozwoliły na więcej)" : '';
            return [true, ($side === 'buy' ? "PKC: kupiono" : "PKC: sprzedano") . " $q szt. po średniej $avg PLN$note."];
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Anuluje aktywną resztę zlecenia (zwalnia escrow); zwraca ile sztuk zostało anulowanych. */
    private static function cancelRemainder(int $orderId, int $userId): int
    {
        if ($orderId <= 0) return 0;
        $o = self::row("SELECT * FROM orders WHERE id=? AND user_id=? AND status='active'", [$orderId, $userId]);
        if (!$o) return 0;
        self::release($o);
        Db::pdo()->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$orderId]);
        return (int) $o['qty'];
    }

    public static function cancel(int $orderId, int $userId): array
    {
        $pdo = Db::pdo();
        $o = self::row("SELECT * FROM orders WHERE id=? AND user_id=? AND status IN ('active','pending')", [$orderId, $userId]);
        if (!$o) return [false, 'Nie znaleziono aktywnego zlecenia.'];
        self::release($o);
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$orderId]);
        return [true, "Zlecenie #$orderId anulowane."];
    }

    /**
     * Zlecenie obronne SL/TP na KONKRETNY pakiet akcji (status 'pending', czeka na kurs).
     * Rezerwuje akcje jak zlecenie sprzedaży; wyzwolone sprzedaje po realnych cenach z arkusza.
     */
    public static function placeStop(int $userId, int $stockId, int $qty, ?float $sl, ?float $tp): array
    {
        if ($qty <= 0) return [false, 'Nieprawidłowa ilość.'];
        if ($sl === null && $tp === null) return [false, 'Podaj próg SL i/lub TP.'];
        $price = (float) self::one("SELECT price FROM stocks WHERE id=?", [$stockId]);
        if ($price <= 0) return [false, 'Nie ma takiej spółki.'];
        if ($sl !== null && ($sl <= 0 || $sl >= $price)) return [false, 'Stop-Loss musi być PONIŻEJ bieżącego kursu (' . number_format($price, 2, ',', ' ') . ').'];
        if ($tp !== null && $tp <= $price)               return [false, 'Take-Profit musi być POWYŻEJ bieżącego kursu (' . number_format($price, 2, ',', ' ') . ').'];

        self::ensureWallet($userId, $stockId);
        $avail = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$userId, $stockId]);
        if ($avail < $qty) return [false, "Nie masz tylu wolnych akcji (dostępne: $avail)."];

        $pdo = Db::pdo();
        $pdo->prepare("UPDATE wallets SET qty=qty-?, qty_reserved=qty_reserved+? WHERE user_id=? AND stock_id=?")->execute([$qty, $qty, $userId, $stockId]);
        $pdo->prepare("INSERT INTO orders (user_id, stock_id, side, qty, qty_init, price, status, sl_price, tp_price, created_at) VALUES (?,?, 'sell', ?, ?, 0, 'pending', ?, ?, ?)")
            ->execute([$userId, $stockId, $qty, $qty, $sl, $tp, Db::now()]);
        $lbl = ($sl !== null ? 'SL ' . number_format($sl, 2, ',', ' ') : '') . ($sl !== null && $tp !== null ? ' / ' : '') . ($tp !== null ? 'TP ' . number_format($tp, 2, ',', ' ') : '');
        return [true, "Zlecenie obronne ($lbl) na $qty szt. przyjęte.", (int) $pdo->lastInsertId()];
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

    /* ---------- Prowizja od obrotu (skarbiec gry) ---------- */

    /** Stawka prowizji jako ułamek (w game_state trzymana w %, np. '0.5'). */
    public static function feeRate(): float
    {
        $v = self::one("SELECT v FROM game_state WHERE k='fee_rate'");
        return ($v === false || $v === null) ? 0.005 : max(0, (float) $v) / 100;
    }

    /** Dopisz prowizję do skarbca (arytmetyka po stronie SQL — odporna na współbieżność). */
    private static function addTreasury(float $amount): void
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare("UPDATE game_state SET v = ROUND(v + ?, 2) WHERE k='treasury'");
        $st->execute([$amount]);
        if ($st->rowCount() === 0) $pdo->prepare("INSERT INTO game_state (k, v) VALUES ('treasury', ?)")->execute([(string) round($amount, 2)]);
    }

    /* ---------- Kojarzenie zleceń (pełne rozliczenie księgi) ---------- */

    public static function matchBook(int $stockId, array &$tickTrades = []): int
    {
        $pdo = Db::pdo();
        $feeRate = self::feeRate();
        $buys  = self::all("SELECT * FROM orders WHERE stock_id=? AND side='buy'  AND status='active' ORDER BY price DESC, id ASC", [$stockId]);
        $sells = self::all("SELECT * FROM orders WHERE stock_id=? AND side='sell' AND status='active' ORDER BY price ASC,  id ASC", [$stockId]);
        $trades = 0;

        foreach ($buys as &$b) {
            if ($b['qty'] <= 0) continue;
            foreach ($sells as &$s) {
                if ($s['qty'] <= 0) continue;
                if ($b['user_id'] == $s['user_id']) continue;      // brak handlu z samym sobą
                if ($b['price'] < $s['price']) break;              // dalej już nie skrzyżuje

                // cena transakcji = cena zlecenia OCZEKUJĄCEGO (starszego) — jak na prawdziwej
                // giełdzie: kto czeka w arkuszu, handluje po swojej cenie; agresor bierze co jest
                $p   = ((int) $b['id'] < (int) $s['id']) ? (float) $b['price'] : (float) $s['price'];
                $q   = (int) min($b['qty'], $s['qty']);
                $val = round($q * $p, 2);

                // kupujący: zwolnij rezerwę po SWOIM limicie, zwróć nadpłatę (limit - p)
                $refund = round($q * ($b['price'] - $p), 2);
                $pdo->prepare("UPDATE users SET cash_reserved=cash_reserved-?, cash=cash+? WHERE id=?")
                    ->execute([round($q * $b['price'], 2), $refund, $b['user_id']]);
                // sprzedający: dostaje gotówkę POMNIEJSZONĄ o prowizję od obrotu (trafia do skarbca gry)
                $fee = round($val * $feeRate, 2);
                $pdo->prepare("UPDATE users SET cash=cash+? WHERE id=?")->execute([$val - $fee, $s['user_id']]);
                if ($fee > 0) self::addTreasury($fee);
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
                $pdo->prepare("INSERT INTO transactions (stock_id, buyer_id, seller_id, buy_order_id, sell_order_id, qty, price, created_at) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$stockId, $b['user_id'], $s['user_id'], $b['id'], $s['id'], $q, $p, Db::now()]);
                $tickTrades[$stockId][] = ['p' => $p, 'q' => $q];
                $trades++;
                if ($b['qty'] <= 0) break;
            }
        }
        return $trades;
    }

    /* ---------- Boty (strategie wg DNA z tabeli bots) ---------- */

    public static function runBots(): void
    {
        // globalny suwak aktywności botów (GM): 0 = wyłączone, 1 = normalnie, 2 = agresywnie
        $act = (float) (self::one("SELECT v FROM game_state WHERE k='bot_activity'") ?? 1);
        if ($act <= 0) return;

        $bots = self::all("SELECT u.id, b.strategy, b.news_reactivity, b.technical_sensitivity, b.risk_appetite, b.horizon
                           FROM users u JOIN bots b ON b.user_id = u.id WHERE u.is_bot = 1");
        $stocks = self::all("SELECT id, sector_id, price, fundamental, pe_target, last_eps, liquidity FROM stocks");
        $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);

        foreach ($bots as $bot) self::cancelAllFor((int) $bot['id']);

        // prefetch: portfele wszystkich botów (jedno zapytanie zamiast setek)
        $wal = [];
        foreach (self::all("SELECT user_id, stock_id, qty, avg_price FROM wallets WHERE user_id IN (SELECT id FROM users WHERE is_bot=1)") as $w) {
            $wal[$w['user_id']][$w['stock_id']] = $w;
        }
        // prefetch: historia zamknięć per spółka (raz na tick, nie raz na bota)
        $closes = [];
        foreach ($stocks as $st) $closes[$st['id']] = self::closes((int) $st['id'], 60);
        // prefetch: świeże newsy/ESPI (ostatnie 4 ticki) -> sentyment per spółka i per sektor
        $newsCo = []; $newsSec = [];
        foreach (self::all("SELECT scope, target_id, impact_strength FROM news WHERE publish_tick > ? AND impact_strength <> 0", [$tick - 4]) as $nw) {
            if ($nw['scope'] === 'COMPANY')     $newsCo[$nw['target_id']]  = ($newsCo[$nw['target_id']] ?? 0) + (float) $nw['impact_strength'];
            elseif ($nw['scope'] === 'SECTOR')  $newsSec[$nw['target_id']] = ($newsSec[$nw['target_id']] ?? 0) + (float) $nw['impact_strength'];
        }

        foreach ($bots as $bot) {
            $uid  = (int) $bot['id'];
            $strat = $bot['strategy'];
            $risk = max(0.3, (float) $bot['risk_appetite']);
            $sens = max(0.3, (float) $bot['technical_sensitivity']);
            $react = max(0.1, (float) $bot['news_reactivity']);
            $hor  = max(6, min(50, (int) $bot['horizon']));

            foreach ($stocks as $st) {
                $sid = (int) $st['id'];
                // każdy bot pokrywa ~1/3 spółek (deterministycznie) — tick nie puchnie przy 50 spółkach
                if ((($sid + $uid) % 3) !== 0) continue;

                $price = (float) $st['price'];
                $have  = (int) ($wal[$uid][$sid]['qty'] ?? 0);
                $avg   = (float) ($wal[$uid][$sid]['avg_price'] ?? 0);
                $liq   = max(0.3, (float) $st['liquidity']);
                $news  = ($newsCo[$sid] ?? 0) + 0.6 * ($newsSec[$st['sector_id']] ?? 0);   // świeży sentyment

                // wspólny odruch (ze starej wersji): realizacja zysku — kurs > śr. zakupu +15%
                if ($strat !== 'mm' && $have > 0 && $avg > 0 && $price > $avg * 1.15 && mt_rand(1, 100) <= 20) {
                    self::place($uid, $sid, 'sell', max(1, (int) floor($have * 0.25)), round($price * 0.99, 2));
                    continue;
                }

                if ($strat === 'mm') {
                    // animator: kwotuje wokół 0.7*fundament + 0.3*kurs; spread maleje z płynnością spółki
                    $base = (0.7 * $st['fundamental'] + 0.3 * $price) * (1 + mt_rand(-40, 40) / 10000);
                    foreach ([[0.010, 60], [0.022, 40], [0.038, 25]] as [$sp, $qy]) {
                        $spread = $sp / $liq;
                        $q = max(5, (int) round($qy * $act));
                        self::place($uid, $sid, 'buy',  $q, round($base * (1 - $spread), 2));
                        self::place($uid, $sid, 'sell', $q, round($base * (1 + $spread), 2));
                    }
                } elseif ($strat === 'trend') {
                    // podąża za trendem: SMA krótka vs długa, okna z horyzontu DNA, próg z czułości
                    $c = $closes[$sid];
                    if (count($c) < $hor) continue;
                    $short = self::sma(array_slice($c, -max(3, (int) round($hor / 4))));
                    $long  = self::sma(array_slice($c, -$hor));
                    $th = 0.01 / $sens;
                    // kalibracja: ciasne limity (~0,5% od kursu) zamiast 2% — bot trendowy
                    // przestaje płacić ~4% "prowizji" na każdej rundce (bankrutował ~-26%/15 sesji)
                    if ($short > $long * (1 + $th) && $have < 400 * $risk)  self::place($uid, $sid, 'buy',  max(1, (int) round(50 * $risk * $act)), round($price * 1.005, 2));
                    elseif ($short < $long * (1 - $th) && $have > 0)         self::place($uid, $sid, 'sell', min((int) round(100 * $risk * $act) + 1, $have), round($price * 0.995, 2));
                } elseif ($strat === 'rsi') {
                    // kontrarianin: kupuje wyprzedanie, sprzedaje wykupienie (progi z czułości)
                    $c = $closes[$sid];
                    if (count($c) < 15) continue;
                    $r = self::rsi($c);
                    $buyTh  = min(45, 30 + 10 * ($sens - 1));
                    $sellTh = max(55, 70 - 10 * ($sens - 1));
                    if ($r < $buyTh && $have < 400 * $risk)   self::place($uid, $sid, 'buy',  max(1, (int) round(60 * $risk * $act)), round($price * 1.02, 2));
                    elseif ($r > $sellTh && $have > 0)         self::place($uid, $sid, 'sell', min((int) round(100 * $risk * $act) + 1, $have), round($price * 0.98, 2));
                } elseif ($strat === 'fundamental') {
                    // inwestor wartościowy: porównuje kurs z wyceną z zysków (C/Z × EPS),
                    // a jego percepcję przesuwają świeże newsy (× news_reactivity — jak w starej wersji)
                    $fair = (float) $st['pe_target'] * (float) $st['last_eps'];
                    if ($fair <= 0) continue;
                    $fair *= 1 + ($news / 100) * $react * 6;
                    $margin = 0.08 / $risk;
                    if ($price < $fair * (1 - $margin) && $have < 500 * $risk)  self::place($uid, $sid, 'buy',  max(1, (int) round(40 * $risk * $act)), round($price * 1.02, 2));
                    elseif ($price > $fair * (1 + $margin) && $have > 0)         self::place($uid, $sid, 'sell', min((int) round(80 * $risk * $act) + 1, $have), round($price * 0.98, 2));
                } elseif ($strat === 'news') {
                    // gracz newsowy: wchodzi za świeżym ESPI (momentum), siła zależna od reaktywności
                    if (abs($news) < 0.05) {
                        // brak newsów: drobny szum rynkowy (płynność + wolumen jak w starej wersji)
                        if (mt_rand(1, 1000) <= (int) round(30 * $liq * $act)) {
                            if (mt_rand(0, 1) === 1) self::place($uid, $sid, 'buy', mt_rand(1, 8), round($price * 1.03, 2));
                            elseif ($have > 0)        self::place($uid, $sid, 'sell', min(mt_rand(1, 8), $have), round($price * 0.97, 2));
                        }
                        continue;
                    }
                    $q = max(1, (int) round(30 * $react * $act * min(3, abs($news) * 4)));
                    if ($news > 0 && $have < 600)  self::place($uid, $sid, 'buy',  $q, round($price * 1.025, 2));
                    elseif ($news < 0 && $have > 0) self::place($uid, $sid, 'sell', min($q * 2, $have), round($price * 0.975, 2));
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
        if ((float) (self::one("SELECT v FROM game_state WHERE k='bot_activity'") ?? 1) <= 0) return;  // boty zamrożone
        $bots = self::col("SELECT u.id FROM users u JOIN bots b ON b.user_id=u.id WHERE u.is_bot=1 AND b.strategy='mm'");
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

    /* ---------- Stop-Loss / Take-Profit (zlecenia obronne 'pending') ---------- */

    public static function checkStops(): void
    {
        $pdo = Db::pdo();
        $rows = self::all("SELECT o.*, s.price AS cur, s.ticker FROM orders o JOIN stocks s ON s.id=o.stock_id WHERE o.status='pending'");
        foreach ($rows as $o) {
            $hitSL = $o['sl_price'] !== null && (float) $o['cur'] <= (float) $o['sl_price'];
            $hitTP = $o['tp_price'] !== null && (float) $o['cur'] >= (float) $o['tp_price'];
            if (!$hitSL && !$hitTP) continue;

            // zwolnij rezerwację i sprzedaj po REALNYCH cenach z arkusza (jak PKC),
            // nie po sztywnym limicie pod kursem — gracz dostaje to, co stoi w bidach
            self::release($o);
            $pdo->prepare("UPDATE orders SET status='triggered' WHERE id=?")->execute([$o['id']]);
            $txFrom = (int) (self::one("SELECT MAX(id) FROM transactions") ?: 0);
            [$ok, $msg] = self::marketOrder((int) $o['user_id'], (int) $o['stock_id'], 'sell', (int) $o['qty']);
            $txTo = (int) (self::one("SELECT MAX(id) FROM transactions") ?: 0);
            Log::write($ok ? 'info' : 'warn', 'engine', $hitSL ? 'stops.sl' : 'stops.tp',
                sprintf('%s %s: kurs %s przekroczył próg %s — %s', $hitSL ? 'Stop-Loss' : 'Take-Profit', $o['ticker'],
                    number_format((float) $o['cur'], 2, ',', ' '),
                    number_format((float) ($hitSL ? $o['sl_price'] : $o['tp_price']), 2, ',', ' '), $msg),
                ['user_id' => (int) $o['user_id'], 'order_id' => (int) $o['id'], 'qty' => (int) $o['qty'], 'tx_from' => $txFrom, 'tx_to' => $txTo]);
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

    /* ---------- Raporty finansowe (miesięczne) ---------- */

    public static function generateReports(int $tick): void
    {
        $pdo = Db::pdo();
        $due = self::all("SELECT s.*, sec.profit_climate AS sector_climate FROM stocks s JOIN sectors sec ON sec.id = s.sector_id WHERE s.next_report_tick <= ?", [$tick]);
        if (!$due) return;
        $globalPer = max(1, (int) (self::one("SELECT v FROM game_state WHERE k='ticks_per_month'") ?: 20));

        foreach ($due as $s) {
            $sid = (int) $s['id'];
            $per = max(1, (int) $s['report_period']);   // kadencja raportu per spółka
            $shares = max(1.0, (float) $s['total_shares']);
            $prev = ((float) $s['last_profit']) ?: (float) $s['base_profit'];

            // oczekiwany wynik = poprzedni × ZNANY trend (miernik spółki + koniunktura branży + growth)
            $trend_m  = ((float) $s['profit_trend'] + (float) $s['sector_climate'] + (float) $s['growth_potential'] * $per) / 100.0;
            $expected = $prev * (1 + $trend_m);
            // faktyczny wynik = oczekiwany × niespodzianka (szum × agresywność)
            $noise    = (mt_rand(-12, 12) / 100.0) * max(0.2, (float) $s['aggressiveness']);
            $profit   = round(max(0, $expected * (1 + $noise)), 2);
            $eps      = round($profit * 12.0 / $shares, 4);
            $surprise = $expected != 0.0 ? ($profit - $expected) / abs($expected) * 100.0 : 0.0;

            // reakcja fundamentu: przyciągnij go do wyceny z zysków (C/Z × roczny EPS)
            $fair  = (float) $s['pe_target'] * $eps;
            $cur   = (float) $s['fundamental'];
            $delta = $fair - $cur;
            if ($delta < 0) $delta = $delta / max(0.5, (float) $s['financial_resilience']);  // odporne spadają mniej
            $pull  = min(1.0, 0.5 * (float) $s['news_impact']);
            $newFund = max(1, round($cur + $delta * $pull, 2));

            $pdo->prepare("UPDATE stocks SET fundamental=?, last_profit=?, last_eps=?, next_report_tick=next_report_tick+? WHERE id=?")
                ->execute([$newFund, $profit, $eps, $per, $sid]);

            $period = 'Miesiąc ' . (int) round($tick / $globalPer);
            $rev = round($profit / 0.15, 2); $cost = round($rev - $profit, 2);
            $pdo->prepare("INSERT INTO financial_reports (stock_id,tick,period,report_date,revenue,costs,net_profit,eps,expected_eps,surprise_pct)
                           VALUES (?,?,?,?,?,?,?,?,?,?)")
                ->execute([$sid, $tick, $period, Db::now(), $rev, $cost, $profit, $eps, round((float) $s['last_eps'], 4), round($surprise, 2)]);

            // news / ESPI z raportu (do wyświetlenia; ciągły wpływ newsów = kolejny krok)
            $type = $surprise >= 3 ? 'POS' : ($surprise <= -3 ? 'NEG' : 'NEU');
            $head = sprintf('Wyniki %s: zysk %s PLN (niespodzianka %s%%)',
                $s['ticker'], number_format($profit, 0, ',', ' '), ($surprise >= 0 ? '+' : '') . round($surprise, 1));
            $impact = max(-0.15, min(0.15, $surprise / 100.0 * (float) $s['news_impact'] * 0.3));  // łagodny dryf po wynikach
            $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                           VALUES (?,?,?,?,?,1,?,?,?,?)")
                ->execute([$head, 'Spółka opublikowała raport miesięczny.', $type, 'COMPANY', $sid, $impact, $tick, $tick + 8, Db::now()]);
        }
    }

    /* ---------- Newsy / ESPI (generowanie + ciągły wpływ) ---------- */

    public static function generateNews(int $tick): void
    {
        $templates = self::all("SELECT * FROM news_templates");
        if (!$templates) return;
        $companyTpl = array_values(array_filter($templates, fn($t) => $t['scope'] === 'COMPANY'));
        $sectorTpl  = array_values(array_filter($templates, fn($t) => $t['scope'] === 'SECTOR'));

        // ESPI/newsy spółek — częstotliwość zależna od news_frequency spółki
        foreach (self::all("SELECT s.id, s.ticker, s.news_frequency, sec.news_sensitivity FROM stocks s JOIN sectors sec ON sec.id = s.sector_id") as $s) {
            if (mt_rand(1, 1000) <= (int) round(20 * (float) $s['news_frequency'])) {
                $tpl = self::pickTemplate($companyTpl);
                if ($tpl) self::emitNews($tick, $tpl, 'COMPANY', (int) $s['id'], $s['ticker'], (float) $s['news_sensitivity']);
            }
        }
        // newsy sektorowe — rzadziej
        foreach (self::all("SELECT id, name, news_sensitivity FROM sectors") as $sec) {
            if (mt_rand(1, 1000) <= 8) {
                $tpl = self::pickTemplate($sectorTpl);
                if ($tpl) self::emitNews($tick, $tpl, 'SECTOR', (int) $sec['id'], $sec['name'], (float) $sec['news_sensitivity']);
            }
        }
    }

    private static function pickTemplate(array $tpls): ?array
    {
        if (!$tpls) return null;
        $total = 0; foreach ($tpls as $t) $total += max(1, (int) $t['frequency_weight']);
        $r = mt_rand(1, $total); $acc = 0;
        foreach ($tpls as $t) { $acc += max(1, (int) $t['frequency_weight']); if ($r <= $acc) return $t; }
        return $tpls[0];
    }

    private static function emitNews(int $tick, array $tpl, string $scope, int $targetId, string $label, float $sensitivity): void
    {
        $head = str_replace('[T]', $label, $tpl['headline_template']);
        $body = str_replace('[T]', $label, (string) $tpl['body_template']);
        $impact = round((float) $tpl['base_impact'] * $sensitivity, 3);   // %/tick w szczycie, ze znakiem
        $dur = max(1, (int) $tpl['duration_ticks']);
        Db::pdo()->prepare("INSERT INTO news (template_id,headline,body,type,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$tpl['id'], $head, $body, $tpl['type'], $scope, $targetId, (int) $tpl['is_espi'], $impact, $tick, $tick + $dur, Db::now()]);
    }

    /** Ciągły, zanikający wpływ aktywnych newsów/ESPI na wartość fundamentalną (lekko rusza kursem). */
    public static function applyNewsImpact(int $tick): void
    {
        $pdo = Db::pdo();
        $active = self::all("SELECT scope, target_id, impact_strength, publish_tick, expire_tick
                             FROM news WHERE publish_tick <= ? AND expire_tick > ? AND impact_strength <> 0", [$tick, $tick]);
        foreach ($active as $nw) {
            $span  = max(1, (int) $nw['expire_tick'] - (int) $nw['publish_tick']);
            $decay = ((int) $nw['expire_tick'] - $tick) / $span;               // 1 -> 0
            $nudge = ((float) $nw['impact_strength'] / 100.0) * $decay;         // ułamek na tick
            if (abs($nudge) < 1e-9) continue;
            if ($nw['scope'] === 'COMPANY') {
                $pdo->prepare("UPDATE stocks SET fundamental = ROUND(fundamental * (1 + ?), 2) WHERE id=?")->execute([$nudge, (int) $nw['target_id']]);
            } elseif ($nw['scope'] === 'SECTOR') {
                $pdo->prepare("UPDATE stocks SET fundamental = ROUND(fundamental * (1 + ?), 2) WHERE sector_id=?")->execute([$nudge, (int) $nw['target_id']]);
            } else {
                $pdo->prepare("UPDATE stocks SET fundamental = ROUND(fundamental * (1 + ?), 2)")->execute([$nudge]);
            }
        }
    }

    /* ---------- Indeks giełdowy (ważony kapitalizacją, baza 1000 pkt) ---------- */

    /** Bieżąca wartość indeksu = (kapitalizacja rynku / kapitalizacja bazowa) × 1000. */
    public static function indexValue(): float
    {
        $mcap = (float) (self::one("SELECT SUM(price * total_shares) FROM stocks") ?: 0);
        if ($mcap <= 0) return 1000.0;
        $base = (float) (self::one("SELECT v FROM game_state WHERE k='index_base_mcap'") ?: 0);
        if ($base <= 0) { $base = $mcap; self::setState('index_base_mcap', (string) $base); }   // leniwa baza (istniejące światy)
        return round($mcap / $base * 1000, 2);
    }

    /** Zapis wartości indeksu po ticku (historia pod wykres). */
    private static function recordIndex(int $t): void
    {
        Db::pdo()->prepare("INSERT INTO index_history (t, value) VALUES (?,?)")->execute([$t, self::indexValue()]);
        if ($t % 500 === 0) {   // retencja: trzymaj ostatnie ~10k punktów
            $max = (int) self::one("SELECT MAX(id) FROM index_history");
            if ($max > 10000) Db::pdo()->exec("DELETE FROM index_history WHERE id <= " . ($max - 10000));
        }
    }

    /** Zapis kapitału (equity) każdego CZŁOWIEKA-gracza po ticku — wykres portfela. */
    private static function recordEquity(int $t): void
    {
        Db::pdo()->prepare(
            "INSERT INTO equity_history (user_id, t, equity)
             SELECT u.id, ?, ROUND(u.cash + u.cash_reserved + COALESCE(sv.v, 0), 2)
             FROM users u
             LEFT JOIN (SELECT w.user_id AS uid, SUM((w.qty + w.qty_reserved) * s.price) AS v
                        FROM wallets w JOIN stocks s ON s.id = w.stock_id GROUP BY w.user_id) sv ON sv.uid = u.id
             WHERE u.is_bot = 0 AND u.role = 'player'"
        )->execute([$t]);
        if ($t % 500 === 0) Db::pdo()->prepare("DELETE FROM equity_history WHERE t < ?")->execute([$t - 10000]);
    }

    /* ---------- Sesje giełdowe i cel gry ---------- */

    /** [numer sesji, ticków do końca sesji, długość sesji] dla danego ticku. */
    public static function sessionInfo(?int $tick = null): array
    {
        $tps = max(1, (int) (self::one("SELECT v FROM game_state WHERE k='ticks_per_session'") ?: 20));
        if ($tick === null) $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        $n = intdiv(max(0, $tick), $tps) + 1;
        return [$n, $tps - (max(0, $tick) % $tps), $tps];
    }

    /** Na otwarciu nowej sesji: kurs otwarcia dnia + wygaśnięcie zleceń sesyjnych (ze zwrotem escrow). */
    private static function rollSession(int $tick): void
    {
        [$n] = self::sessionInfo($tick);
        $prev = (int) (self::one("SELECT v FROM game_state WHERE k='session'") ?: 0);
        if ($n === $prev) return;
        Db::pdo()->exec("UPDATE stocks SET day_open_price = price");

        $expired = self::all("SELECT * FROM orders WHERE status='active' AND expires_session IS NOT NULL AND expires_session < ?", [$n]);
        foreach ($expired as $o) self::release($o);
        if ($expired) {
            Db::pdo()->prepare("UPDATE orders SET status='expired' WHERE status='active' AND expires_session IS NOT NULL AND expires_session < ?")->execute([$n]);
            Log::write('info', 'engine', 'orders.expired', 'wygasło zleceń sesyjnych: ' . count($expired), ['session' => $n]);
        }
        self::setState('session', (string) $n);
    }

    /** Cel gry: gdy kapitał gracza (equity) osiągnie próg — zapisz sesję sukcesu + komunikat. */
    private static function checkGoal(int $tick): void
    {
        $target = (float) (self::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
        if ($target <= 0) return;
        [$session] = self::sessionInfo($tick);
        $players = self::all("SELECT id, username, cash, cash_reserved FROM users WHERE is_bot=0 AND role='player' AND goal_session IS NULL");
        foreach ($players as $p) {
            $stockVal = (float) (self::one(
                "SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0) FROM wallets w JOIN stocks s ON s.id = w.stock_id WHERE w.user_id = ?",
                [$p['id']]
            ) ?: 0);
            $equity = (float) $p['cash'] + (float) $p['cash_reserved'] + $stockVal;
            if ($equity >= $target) {
                Db::pdo()->prepare("UPDATE users SET goal_session=? WHERE id=?")->execute([$session, $p['id']]);
                Db::pdo()->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                                    VALUES (?,?,'POS','MARKET',NULL,0,0,?,?,?)")
                    ->execute(['🏆 ' . $p['username'] . ' osiągnął cel gry: ' . number_format($target, 0, ',', ' ') . ' PLN!',
                               'Kapitał inwestora przekroczył próg celu w sesji ' . $session . '.', $tick, $tick + 20, Db::now()]);
            }
        }
    }

    /* ---------- Pełny tick świata ---------- */

    public static function runTick(): int
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();   // cały tick atomowo (i dużo szybciej na SQLite)
        try {
        $t = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?? 0) + 1;
        self::rollSession($t);   // nowa sesja giełdowa -> kurs otwarcia dnia

        // dynamika ceny fundamentalnej — STEROWALNA i zależna od otoczenia:
        //   dryf = trend rynku*beta + trend sektora*beta + bias(GM) + growth(spółki+sektora)
        //   szum skalowany zmiennością spółki × sektora
        $market = (float) (self::one("SELECT v FROM game_state WHERE k='sentiment'") ?: 0);  // trend rynku %/tick
        $stocks = self::all("SELECT s.id, s.fundamental, s.bias, s.beta, s.volatility, s.growth_potential,
                                    sec.trend AS sector_trend, sec.market_beta AS sector_beta,
                                    sec.volatility AS sector_vol, sec.growth AS sector_growth
                             FROM stocks s JOIN sectors sec ON sec.id = s.sector_id");
        foreach ($stocks as $st) {
            $vol = (max(0.1, (float) $st['volatility'])) * (max(0.1, (float) $st['sector_vol']));
            // uwaga: growth idzie teraz kanałem raportów (zyski -> wycena), nie w ciągłym dryfie
            $drift = ( $market * (float) $st['beta']
                     + (float) $st['sector_trend'] * (float) $st['sector_beta']
                     + (float) $st['bias'] ) / 100.0;
            // kalibracja: szum tła niski (~4-6% na sesję) — duże ruchy mają pochodzić
            // z WYDARZEŃ (raporty, ESPI, sterowanie GM), nie z losowego tła
            $noise = (mt_rand(-5, 5) / 1000) * $vol;
            $f = (float) $st['fundamental'] * (1 + $drift + $noise);
            if (mt_rand(1, 100) <= 6) $f *= 1 + (mt_rand(-50, 50) / 1000) * $vol;    // rzadkie, umiarkowane szoki
            $pdo->prepare("UPDATE stocks SET fundamental=? WHERE id=?")->execute([max(1, round($f, 2)), $st['id']]);
        }
        self::generateReports($t);   // raporty miesięczne -> skok wartości fundamentalnej + news/ESPI
        self::generateNews($t);      // losowe ESPI/newsy pozytywne i negatywne
        self::applyNewsImpact($t);   // aktywne newsy lekko ruszają fundamentem (z zanikiem)

        self::checkStops();
        self::runBots();
        self::arbitrage();   // boty domykają lukę kurs -> wartość fundamentalna (kurs podąża za sterowaniem)

        $tickTrades = [];
        foreach (self::all("SELECT id FROM stocks") as $st) self::matchBook((int) $st['id'], $tickTrades);
        self::recordCandles($t, $tickTrades);
        self::recordIndex($t);    // indeks giełdowy (historia pod wykres)
        self::recordEquity($t);   // kapitał graczy (wykres portfela)
        self::checkGoal($t);      // czy któryś gracz osiągnął cel gry

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
