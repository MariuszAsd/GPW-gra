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
require_once __DIR__ . '/EventCatalog.php';
require_once __DIR__ . '/Achievements.php';

final class Engine
{
    /* ---------- Zlecenia gracza / botów ---------- */

    /** Zlecenie limit. $expiresSession: NULL = bezterminowe, N = ważne do końca sesji N. */
    public static function place(int $userId, int $stockId, string $side, int $qty, float $price, ?int $expiresSession = null): array
    {
        $side = strtolower($side);
        if (!in_array($side, ['buy', 'sell'], true)) return [false, 'Nieznany typ zlecenia.'];
        $price = round($price, 2);   // normalizuj cenę do 2 miejsc U ŹRÓDŁA: rezerwacja == zapis == zwrot.
        // Wcześniej rezerwacja liczyła się z surowej ceny (np. 1,009), a zlecenie/zwrot z zaokrąglonej
        // (1,01) — różnica „drukowała" gotówkę i dawała ujemne cash_reserved przy anulacji/realizacji.
        if ($qty <= 0 || $price <= 0)               return [false, 'Nieprawidłowa ilość lub cena.'];
        if (($m = self::haltMessage($stockId)) !== null) return [false, $m];   // widełki: zawieszone = brak nowych zleceń
        $pdo = Db::pdo();

        if ($side === 'buy') {
            $cost = round($qty * $price, 2);
            // ATOMOWO: potrąć tylko gdy naprawdę starczy gotówki (jeden UPDATE z warunkiem) — chroni przed
            // double-spend i ujemnym saldem przy równoległych żądaniach (dwie sesje / podwójny submit).
            // próg z tolerancją liczony w PHP i bindowany jako gotowa liczba — NIE „cash + 0.000001" w SQL
            // (SQLite z arytmetyką na kolumnie potrafi nie dopasować wiersza; bindowany próg działa wszędzie).
            $st = $pdo->prepare("UPDATE users SET cash=cash-?, cash_reserved=cash_reserved+? WHERE id=? AND cash >= ?");
            $st->execute([$cost, $cost, $userId, $cost - 0.000001]);
            if ($st->rowCount() === 0) {
                $cash = (float) self::one("SELECT cash FROM users WHERE id=?", [$userId]);
                return [false, "Za mało gotówki. Masz " . number_format($cash, 2, ',', ' ') . " PLN, potrzeba " . number_format($cost, 2, ',', ' ') . " PLN."];
            }
        } else {
            self::ensureWallet($userId, $stockId);
            $st = $pdo->prepare("UPDATE wallets SET qty=qty-?, qty_reserved=qty_reserved+? WHERE user_id=? AND stock_id=? AND qty >= ?");
            $st->execute([$qty, $qty, $userId, $stockId, $qty]);
            if ($st->rowCount() === 0) {
                $avail = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$userId, $stockId]);
                return [false, "Nie masz tylu akcji (dostępne: $avail)."];
            }
        }

        $pdo->prepare("INSERT INTO orders (user_id, stock_id, side, qty, qty_init, price, status, expires_session, created_at) VALUES (?,?,?,?,?,?, 'active', ?, ?)")
            ->execute([$userId, $stockId, $side, $qty, $qty, $price, $expiresSession, Db::now()]);
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
        if (($m = self::haltMessage($stockId)) !== null) return [false, $m];
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
     * Edycja AKTYWNEGO zlecenia z limitem: zmiana ceny i/lub ilości (reszty czekającej w arkuszu).
     * Rezerwacja korygowana różnicowo (bez utraty escrow), po czym próba natychmiastowego skojarzenia.
     * Zmiana = utrata priorytetu czasu (świeży czas), jak przy realnej modyfikacji na giełdzie.
     */
    public static function editOrder(int $orderId, int $userId, int $newQty, float $newPrice): array
    {
        $pdo = Db::pdo();
        $o = self::row("SELECT * FROM orders WHERE id=? AND user_id=? AND status='active'", [$orderId, $userId]);
        if (!$o)                                       return [false, 'Nie znaleziono aktywnego zlecenia do edycji.'];
        if (!in_array($o['side'], ['buy', 'sell'], true)) return [false, 'Tego zlecenia nie można edytować.'];
        $newQty = (int) $newQty; $newPrice = round((float) $newPrice, 2);
        if ($newQty <= 0 || $newPrice <= 0)            return [false, 'Podaj poprawną ilość i cenę.'];
        if (($m = self::haltMessage((int) $o['stock_id'])) !== null) return [false, $m];

        if ($o['side'] === 'buy') {
            $oldCost = round((float) $o['qty'] * (float) $o['price'], 2);   // bieżąca rezerwacja tego zlecenia
            $newCost = round($newQty * $newPrice, 2);
            $cash = (float) self::one("SELECT cash FROM users WHERE id=?", [$userId]);
            if ($cash + $oldCost + 1e-6 < $newCost)
                return [false, 'Za mało gotówki na tę zmianę. Dostępne z tym zleceniem: ' . number_format($cash + $oldCost, 2, ',', ' ') . ' PLN, potrzeba ' . number_format($newCost, 2, ',', ' ') . ' PLN.'];
            $delta = round($newCost - $oldCost, 2);   // >0 dokładamy rezerwację, <0 zwrot
            $pdo->prepare("UPDATE users SET cash=cash-?, cash_reserved=cash_reserved+? WHERE id=?")->execute([$delta, $delta, $userId]);
        } else {
            $oldQ  = (int) $o['qty'];
            $avail = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$userId, $o['stock_id']]);
            if ($avail + $oldQ < $newQty)
                return [false, 'Nie masz tylu akcji (dostępne z tym zleceniem: ' . ($avail + $oldQ) . ').'];
            $dq = $newQty - $oldQ;   // >0 rezerwujemy więcej, <0 zwrot do portfela
            $pdo->prepare("UPDATE wallets SET qty=qty-?, qty_reserved=qty_reserved+? WHERE user_id=? AND stock_id=?")->execute([$dq, $dq, $userId, $o['stock_id']]);
        }
        // świeży czas = utrata priorytetu w arkuszu; qty_init=newQty (edytowana reszta staje się nowym pakietem)
        $pdo->prepare("UPDATE orders SET qty=?, qty_init=?, price=?, created_at=? WHERE id=?")
            ->execute([$newQty, $newQty, $newPrice, Db::now(), $orderId]);
        self::matchBook((int) $o['stock_id']);   // spróbuj skojarzyć od razu po zmianie
        return [true, "Zlecenie #$orderId zmienione: $newQty szt. po " . number_format($newPrice, 2, ',', ' ') . ' PLN.', $orderId];
    }

    /**
     * Zlecenie obronne SL/TP na KONKRETNY pakiet akcji (status 'pending', czeka na kurs).
     * Rezerwuje akcje jak zlecenie sprzedaży; wyzwolone sprzedaje po realnych cenach z arkusza.
     * $trail (SL kroczący): % pod kursem — silnik PODNOSI sl_price za rosnącym kursem,
     * nigdy nie obniża. Bez podanego SL próg startowy = kurs x (1 - trail%).
     */
    public static function placeStop(int $userId, int $stockId, int $qty, ?float $sl, ?float $tp, ?float $trail = null): array
    {
        if ($qty <= 0) return [false, 'Nieprawidłowa ilość.'];
        if ($sl === null && $tp === null && $trail === null) return [false, 'Podaj próg SL i/lub TP (albo SL kroczący %).'];
        if (($m = self::haltMessage($stockId)) !== null) return [false, $m];   // zawieszenie blokuje też obronne (fair play po wznowieniu)
        $price = (float) self::one("SELECT price FROM stocks WHERE id=?", [$stockId]);
        if ($price <= 0) return [false, 'Nie ma takiej spółki.'];
        if ($trail !== null) {
            if ($trail < 0.5 || $trail > 50) return [false, 'SL kroczący: podaj 0,5–50 (procent pod kursem).'];
            if ($sl === null) $sl = round($price * (1 - $trail / 100), 2);   // start progu tuż pod bieżącym kursem
        }
        if ($sl !== null && ($sl <= 0 || $sl >= $price)) return [false, 'Stop-Loss musi być PONIŻEJ bieżącego kursu (' . number_format($price, 2, ',', ' ') . ').'];
        if ($tp !== null && $tp <= $price)               return [false, 'Take-Profit musi być POWYŻEJ bieżącego kursu (' . number_format($price, 2, ',', ' ') . ').'];

        self::ensureWallet($userId, $stockId);
        $avail = (int) self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$userId, $stockId]);
        if ($avail < $qty) return [false, "Nie masz tylu wolnych akcji (dostępne: $avail)."];

        $pdo = Db::pdo();
        $pdo->prepare("UPDATE wallets SET qty=qty-?, qty_reserved=qty_reserved+? WHERE user_id=? AND stock_id=?")->execute([$qty, $qty, $userId, $stockId]);
        $pdo->prepare("INSERT INTO orders (user_id, stock_id, side, qty, qty_init, price, status, sl_price, tp_price, trail_pct, created_at) VALUES (?,?, 'sell', ?, ?, 0, 'pending', ?, ?, ?, ?)")
            ->execute([$userId, $stockId, $qty, $qty, $sl, $tp, $trail, Db::now()]);
        $trailLbl = $trail !== null ? rtrim(rtrim(number_format($trail, 1, ',', ''), '0'), ',') : '';
        $lbl = ($sl !== null ? ($trail !== null ? "SL kroczący $trailLbl% (start " : 'SL ') . number_format($sl, 2, ',', ' ') . ($trail !== null ? ')' : '') : '')
             . ($sl !== null && $tp !== null ? ' / ' : '') . ($tp !== null ? 'TP ' . number_format($tp, 2, ',', ' ') : '');
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
        $trades = 0; $achUids = [];

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

                // powiadom człowieka, gdy jego CZEKAJĄCE zlecenie właśnie zrealizowało się w całości
                // (tylko z crona; zlecenia z tej samej sekundy = natychmiastowe PKC/SL — te widać od razu)
                if (php_sapi_name() === 'cli') {
                    $nowTs = Db::now();
                    foreach ([[$b, 'kupna'], [$s, 'sprzedaży']] as [$oo, $lbl]) {
                        if ($oo['qty'] <= 0 && $oo['created_at'] !== $nowTs && in_array((int) $oo['user_id'], self::humanIds(), true)) {
                            $tk = self::one("SELECT ticker FROM stocks WHERE id=?", [$stockId]);
                            self::notify((int) $oo['user_id'], 'order',
                                "✅ Zlecenie $lbl $tk zrealizowane w całości (" . (int) $oo['qty_init'] . " szt.)",
                                'order.php?id=' . (int) $oo['id']);
                        }
                    }
                }

                // kurs = ostatnia cena transakcji + log
                $pdo->prepare("UPDATE stocks SET price=? WHERE id=?")->execute([$p, $stockId]);
                $pdo->prepare("INSERT INTO transactions (stock_id, buyer_id, seller_id, buy_order_id, sell_order_id, qty, price, created_at) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$stockId, $b['user_id'], $s['user_id'], $b['id'], $s['id'], $q, $p, Db::now()]);
                foreach ([(int) $b['user_id'], (int) $s['user_id']] as $huid) {   // odznaki: zbierz, sprawdź RAZ po pętli
                    if (in_array($huid, self::humanIds(), true)) $achUids[$huid] = true;
                }
                $tickTrades[$stockId][] = ['p' => $p, 'q' => $q];
                $trades++;
                if ($b['qty'] <= 0) break;
            }
        }
        foreach (array_keys($achUids) as $huid) self::checkTradeAchievements($huid);
        return $trades;
    }

    /* ---------- Boty (strategie wg DNA z tabeli bots) ---------- */

    public static function runBots(): void
    {
        // globalny suwak aktywności botów (GM): 0 = wyłączone, 1 = normalnie, 2 = agresywnie
        $act = (float) (self::one("SELECT v FROM game_state WHERE k='bot_activity'") ?? 1);
        if ($act <= 0) return;

        $pdo = Db::pdo();
        self::ensureTechBots();       // istniejące światy dostają botów AT bez resetu (jednorazowo)
        self::ensureBotNames();       // bot_* -> losowe „ludzkie" nazwy (jednorazowo)
        self::ensureBotPopulation();  // dołóż botów do docelowej populacji: głębszy arkusz (jednorazowo)
        if (!class_exists('Technical')) require_once __DIR__ . '/Technical.php';
        $taInfV = self::one("SELECT v FROM game_state WHERE k='ta_influence'");                  // GM: 0 = AT bez wpływu
        $taInf = ($taInfV === false || $taInfV === null) ? 1.0 : (float) $taInfV;

        $bots = self::all("SELECT u.id, b.strategy, b.news_reactivity, b.technical_sensitivity, b.risk_appetite, b.horizon
                           FROM users u JOIN bots b ON b.user_id = u.id WHERE u.is_bot = 1");
        $stocks = self::all("SELECT id, sector_id, price, fundamental, pe_target, last_eps, liquidity, dividend_payout, tech_affinity, ta_signal, halted_until FROM stocks");
        $nowTs = Db::now();   // do pomijania spółek z zawieszonymi notowaniami (jak dawne place()->haltMessage())
        $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        $mods = self::activeMods($tick);   // boty fundamentalne rozumieja skutki wydarzen

        // BRAMKA CHURNU: ~1/3 botów przekwotowuje w danym ticku, reszta ZOSTAWIA swoje zlecenia
        // (odświeży je za 1-2 ticki). Dzięki temu arkusz pozostaje głęboki (zlecenia trwają), a
        // liczba operacji zapisu na tick — i okno blokady, przez które WISI zlecenie gracza — spada ~3×.
        // Efekt uboczny: w arkuszu leżą zlecenia z różnych „roczników" → naturalnie zróżnicowany.
        $botActive = fn(int $uid) => (($uid + $tick) % 4) === 0;

        // aktywne boty tego ticku (bramka)
        $activeUids = [];
        foreach ($bots as $b) if ($botActive((int) $b['id'])) $activeUids[] = (int) $b['id'];

        // BATCH ANULOWANIE: jednym ciągiem zwolnij rezerwacje i skasuj aktywne zlecenia botów,
        // które teraz przekwotują — zamiast setek pojedynczych cancelAllFor (mniej round-tripów do bazy).
        if ($activeUids) {
            $ph = implode(',', array_fill(0, count($activeUids), '?'));
            $relCash = []; $relSh = [];
            foreach (self::all("SELECT user_id, stock_id, side, qty, price FROM orders WHERE status='active' AND user_id IN ($ph)", $activeUids) as $o) {
                if ($o['side'] === 'buy') $relCash[(int) $o['user_id']] = ($relCash[(int) $o['user_id']] ?? 0) + round((float) $o['qty'] * (float) $o['price'], 2);
                else { $kk = $o['user_id'] . ':' . $o['stock_id']; $relSh[$kk] = ($relSh[$kk] ?? 0) + (int) $o['qty']; }
            }
            foreach ($relCash as $uidR => $c) if ($c > 0) $pdo->prepare("UPDATE users SET cash=cash+?, cash_reserved=cash_reserved-? WHERE id=?")->execute([$c, $c, $uidR]);
            foreach ($relSh as $kk => $q) if ($q > 0) { [$uR, $sR] = explode(':', $kk); $pdo->prepare("UPDATE wallets SET qty=qty+?, qty_reserved=qty_reserved-? WHERE user_id=? AND stock_id=?")->execute([$q, $q, $uR, $sR]); }
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE status='active' AND user_id IN ($ph)")->execute($activeUids);
        }

        // prefetch: portfele botów (PO anulowaniu — odzwierciedla zwolnione akcje) + gotówka
        $wal = [];
        foreach (self::all("SELECT user_id, stock_id, qty, avg_price FROM wallets WHERE user_id IN (SELECT id FROM users WHERE is_bot=1)") as $w) {
            $wal[$w['user_id']][$w['stock_id']] = $w;
        }
        $botCash = [];
        foreach (self::all("SELECT id, cash FROM users WHERE is_bot=1") as $u) $botCash[(int) $u['id']] = (float) $u['cash'];

        // KOLEJKA ZLECEŃ botów: zbierana w PHP (z bieżącym saldem gotówki/akcji, by nie przekroczyć),
        // zapisywana ZBIORCZO po pętli. Zamienia ~2000 zapytań/tick na kilkanaście → tick nie muli
        // i nie blokuje zleceń graczy. Zwraca bool jak dawne place() (fałsz = brak środków/akcji).
        $newOrders = []; $resCash = []; $resSh = [];
        $queue = function (int $uid, int $sid, string $side, int $qty, float $price) use (&$newOrders, &$resCash, &$resSh, &$botCash, &$wal) {
            if ($qty <= 0 || $price <= 0) return false;
            $price = round($price, 2);
            if ($side === 'buy') {
                $cost = round($qty * $price, 2);
                if (($botCash[$uid] ?? 0) + 1e-6 < $cost) return false;
                $botCash[$uid] -= $cost;
                $resCash[$uid] = ($resCash[$uid] ?? 0) + $cost;
            } else {
                $avail = (int) ($wal[$uid][$sid]['qty'] ?? 0);
                if ($avail < $qty) return false;
                $wal[$uid][$sid]['qty'] = $avail - $qty;
                $kk = $uid . ':' . $sid; $resSh[$kk] = ($resSh[$kk] ?? 0) + $qty;
            }
            $newOrders[] = [$uid, $sid, $side, $qty, $price];
            return true;
        };

        // prefetch: historia zamknięć per spółka (raz na tick, nie raz na bota)
        $closes = [];
        foreach ($stocks as $st) $closes[$st['id']] = self::closes((int) $st['id'], 60);
        // prefetch: świeże newsy (ostatnie 4 ticki) ROZBITE NA KLASY INFORMACJI —
        // fundament (twarde fakty), nastroje (miękkie) i technika (komentarz z wykresu).
        // Różne strategie botów słuchają różnych klas (patrz newsPerception).
        $nF = ['co' => [], 'sec' => [], 'mkt' => 0.0];   // fundamental
        $nS = ['co' => [], 'sec' => [], 'mkt' => 0.0];   // sentiment
        $nT = [];                                          // technical (tylko spółkowe)
        foreach (self::all("SELECT scope, target_id, impact_strength, kind FROM news WHERE publish_tick > ? AND impact_strength <> 0", [$tick - 4]) as $nw) {
            $imp = (float) $nw['impact_strength'];
            $k = $nw['kind'] ?? 'fundamental';
            if ($k === 'technical') {
                if ($nw['scope'] === 'COMPANY') $nT[$nw['target_id']] = ($nT[$nw['target_id']] ?? 0) + $imp;
                continue;
            }
            if ($k === 'sentiment') {
                if ($nw['scope'] === 'COMPANY')     $nS['co'][$nw['target_id']]  = ($nS['co'][$nw['target_id']] ?? 0) + $imp;
                elseif ($nw['scope'] === 'SECTOR')  $nS['sec'][$nw['target_id']] = ($nS['sec'][$nw['target_id']] ?? 0) + $imp;
                else                                $nS['mkt'] += $imp;
            } else {
                if ($nw['scope'] === 'COMPANY')     $nF['co'][$nw['target_id']]  = ($nF['co'][$nw['target_id']] ?? 0) + $imp;
                elseif ($nw['scope'] === 'SECTOR')  $nF['sec'][$nw['target_id']] = ($nF['sec'][$nw['target_id']] ?? 0) + $imp;
                else                                $nF['mkt'] += $imp;
            }
        }

        foreach ($bots as $bot) {
            $uid  = (int) $bot['id'];
            if (!$botActive($uid)) continue;     // nieaktywny w tym ticku — jego zlecenia zostają w arkuszu (anulowanie było zbiorcze wyżej)
            $strat = $bot['strategy'];
            $risk = max(0.3, (float) $bot['risk_appetite']);
            $sens = max(0.3, (float) $bot['technical_sensitivity']);
            $react = max(0.1, (float) $bot['news_reactivity']);
            $hor  = max(6, min(50, (int) $bot['horizon']));

            foreach ($stocks as $st) {
                $sid = (int) $st['id'];
                if ($st['halted_until'] !== null && $st['halted_until'] !== '' && (string) $st['halted_until'] > $nowTs) continue;   // widełki: zawieszone notowania = boty nie dokładają zleceń (jak dawne place())
                $liq = max(0.3, (float) $st['liquidity']);
                // pokrycie botami zależne od PŁYNNOŚCI spółki: płynne handluje ~połowa botów,
                // niepłynne co piąty — obrót i częstotliwość transakcji różnicują się naturalnie
                $span = $liq >= 1.2 ? 2 : ($liq >= 0.95 ? 3 : 5);
                if ((($sid + $uid) % $span) !== 0) continue;

                $price = (float) $st['price'];
                $have  = (int) ($wal[$uid][$sid]['qty'] ?? 0);
                $avg   = (float) ($wal[$uid][$sid]['avg_price'] ?? 0);
                $aff   = max(0.0, min(1.0, (float) $st['tech_affinity']));
                // świeże informacje per klasa: spółka + 0.6×sektor + 0.4×rynek
                $newsF = ($nF['co'][$sid] ?? 0) + 0.6 * ($nF['sec'][$st['sector_id']] ?? 0) + 0.4 * $nF['mkt'];
                $newsS = ($nS['co'][$sid] ?? 0) + 0.6 * ($nS['sec'][$st['sector_id']] ?? 0) + 0.4 * $nS['mkt'];
                $newsT = (float) ($nT[$sid] ?? 0);
                $news  = self::newsPerception($strat, $aff, $newsF, $newsS, $newsT);   // percepcja TEJ strategii

                // wspólny odruch (ze starej wersji): realizacja zysku — kurs > śr. zakupu +15%
                if ($strat !== 'mm' && $have > 0 && $avg > 0 && $price > $avg * 1.15 && mt_rand(1, 100) <= 20) {
                    $queue($uid, $sid,'sell', max(1, (int) floor($have * 0.25)), round($price * 0.99, 2));
                    continue;
                }

                if ($strat === 'mm') {
                    // animator: kwotuje wokol 0.7*fundament + 0.3*kurs; spread maleje z plynnoscia spolki.
                    // ZARZADZANIE ZAPASEM: nadmiar akcji -> kwotuje nizej i sprzedaje wiecej (i odwrotnie),
                    // zamiast biernie puchnac na trendach — mniej strat mm, naturalniejszy rynek
                    $inv = max(-1.0, min(1.0, ($have - 3000) / 3000));
                    $base = (0.7 * $st['fundamental'] + 0.3 * $price) * (1 + mt_rand(-15, 15) / 10000) * (1 - 0.004 * $inv);
                    // grube kwotowania (3 poziomy) z LOSOWĄ wielkością i drobnym rozrzutem poziomów —
                    // arkusz jest głęboki (zlecenie 20–100k nie rusza kilku % kursu) ORAZ zróżnicowany
                    // (koniec z rzędem identycznych ilości); persystencja przez bramkę churnu dokłada głębi.
                    foreach ([[0.007, 180], [0.015, 130], [0.027, 90]] as [$sp, $qy]) {
                        $spread = ($sp / max(0.85, $liq)) * (1 + mt_rand(-20, 25) / 100);   // rozrzut poziomów cenowych
                        $jb = 0.55 + mt_rand(0, 95) / 100; $js = 0.55 + mt_rand(0, 95) / 100;   // rozrzut wielkości (0,55–1,5×)
                        $qb = max(5, (int) round($qy * $act * (1 - 0.4 * $inv) * $jb));
                        $qs = max(5, (int) round($qy * $act * (1 + 0.4 * $inv) * $js));
                        $queue($uid, $sid,'buy',  $qb, round($base * (1 - $spread), 2));
                        $queue($uid, $sid,'sell', $qs, round($base * (1 + $spread), 2));
                    }
                } elseif ($strat === 'trend') {
                    // podaza za trendem: SMA krotka vs dluga + CIECIE STRAT (nie trzyma spadajacych)
                    $c = $closes[$sid];
                    if (count($c) < $hor) continue;
                    if ($have > 0 && $avg > 0 && $price < $avg * 0.93) {   // stop-loss bota: -7% od sredniej
                        $queue($uid, $sid,'sell', $have, round($price * 0.995, 2));
                        continue;
                    }
                    $short = self::sma(array_slice($c, -max(3, (int) round($hor / 4))));
                    $long  = self::sma(array_slice($c, -$hor));
                    $th = 0.01 / $sens;
                    // kalibracja: ciasne limity (~0,5% od kursu) zamiast 2% — bot trendowy
                    // przestaje płacić ~4% "prowizji" na każdej rundce (bankrutował ~-26%/15 sesji)
                    // przechył AT: zgodny sygnał techniczny powiększa pozycję (do +60%), przeciwny nie blokuje
                    $tilt = $taInf > 0 ? (float) $st['ta_signal'] * $taInf * (float) $st['tech_affinity'] * $sens * 0.6 : 0.0;
                    if ($short > $long * (1 + $th) && $have < 400 * $risk)  $queue($uid, $sid,'buy',  max(1, (int) round(50 * $risk * $act * (1 + max(0, $tilt)))), round($price * 1.005, 2));
                    elseif ($short < $long * (1 - $th) && $have > 0)         $queue($uid, $sid,'sell', min((int) round(100 * $risk * $act * (1 + max(0, -$tilt))) + 1, $have), round($price * 0.995, 2));
                } elseif ($strat === 'rsi') {
                    // kontrarianin: kupuje wyprzedanie, sprzedaje wykupienie (progi z czułości)
                    $c = $closes[$sid];
                    if (count($c) < 15) continue;
                    $r = self::rsi($c);
                    $buyTh  = min(45, 30 + 10 * ($sens - 1));
                    $sellTh = max(55, 70 - 10 * ($sens - 1));
                    // im glebiej w strefie, tym wieksza pozycja (RSI 20 -> mocniejszy zakup niz RSI 29)
                    if ($r < $buyTh && $have < 400 * $risk) {
                        $depth = 1 + min(1.0, ($buyTh - $r) / 20);
                        $queue($uid, $sid,'buy',  max(1, (int) round(45 * $risk * $act * $depth)), round($price * 1.015, 2));
                    } elseif ($r > $sellTh && $have > 0) {
                        $depth = 1 + min(1.0, ($r - $sellTh) / 20);
                        $queue($uid, $sid,'sell', min((int) round(70 * $risk * $act * $depth) + 1, $have), round($price * 0.985, 2));
                    }
                } elseif ($strat === 'fundamental') {
                    // inwestor wartosciowy: kurs vs wycena z zyskow (C/Z x EPS); percepcje przesuwaja
                    // swieze newsy ORAZ aktywne skutki wydarzen (kapitalizuje przyszle zyski/straty),
                    // do tego lekka premia za spolki dywidendowe
                    $fair = (float) $st['pe_target'] * (float) $st['last_eps'];
                    if ($fair <= 0) continue;
                    $evEarn = self::modVal($mods, 'stock', $sid, 'profit_trend')
                            + self::modVal($mods, 'sector', (int) $st['sector_id'], 'profit_climate');
                    $fair *= 1 + ($news / 100) * $react * 6 + ($evEarn / 100) * 2.5 * $react;   // $news = już PRZEFILTROWANA percepcja (twarde fakty, mocniej na spółkach fundamentalnych)
                    $fair *= 1 + 0.05 * (float) $st['dividend_payout'];
                    $margin = 0.08 / $risk;
                    if ($price < $fair * (1 - $margin) && $have < 500 * $risk)  $queue($uid, $sid,'buy',  max(1, (int) round(40 * $risk * $act)), round($price * 1.02, 2));
                    elseif ($price > $fair * (1 + $margin) && $have > 0)         $queue($uid, $sid,'sell', min((int) round(80 * $risk * $act) + 1, $have), round($price * 0.98, 2));
                } elseif ($strat === 'tech') {
                    // gracz TECHNICZNY: handluje niemal wyłącznie na zbiorczym sygnale AT.
                    // Siła = sygnał x wpływ globalny (GM) x podatność spółki x czułość bota —
                    // nic nie jest zero-jedynkowe: mocniejszy sygnał = większa pozycja.
                    if ($taInf <= 0) continue;
                    // komentarz techniczny w mediach = chwilowy dopalacz czułości (samospełniająca się przepowiednia)
                    $sigT = (float) $st['ta_signal'] * $taInf * (0.4 + 1.2 * $aff) * $sens * $news;
                    if ($have > 0 && $avg > 0 && $price < $avg * 0.90) {   // twardy stop bota AT: -10%
                        $queue($uid, $sid,'sell', $have, round($price * 0.995, 2));
                        continue;
                    }
                    if ($sigT > 0.12 && $have < 450 * $risk) {
                        $q = max(1, (int) round(55 * $risk * $act * min(1.6, $sigT * 2)));
                        $queue($uid, $sid,'buy', $q, round($price * 1.008, 2));
                    } elseif ($sigT < -0.12 && $have > 0) {
                        $q = min((int) round(90 * $risk * $act * min(1.6, -$sigT * 2)) + 1, $have);
                        $queue($uid, $sid,'sell', $q, round($price * 0.992, 2));
                    }
                } elseif ($strat === 'news') {
                    // gracz newsowy: wchodzi za świeżym ESPI (momentum), siła zależna od reaktywności
                    if (abs($news) < 0.05) {
                        // brak newsów: drobny szum rynkowy (płynność + wolumen jak w starej wersji)
                        if (mt_rand(1, 1000) <= (int) round(30 * $liq * $act)) {
                            if (mt_rand(0, 1) === 1) $queue($uid, $sid,'buy', mt_rand(1, 8), round($price * 1.03, 2));
                            elseif ($have > 0)        $queue($uid, $sid,'sell', min(mt_rand(1, 8), $have), round($price * 0.97, 2));
                        }
                        continue;
                    }
                    $q = max(1, (int) round(18 * $react * $act * min(2, abs($news) * 2.5)));
                    if ($news > 0 && $have < 600)  $queue($uid, $sid,'buy',  $q, round($price * 1.012, 2));
                    elseif ($news < 0 && $have > 0) $queue($uid, $sid,'sell', min($q * 2, $have), round($price * 0.988, 2));
                }
            }
        }

        // === FLUSH: zapis zebranych zleceń ZBIORCZO (bulk rezerwacja + bulk INSERT) ===
        foreach ($resCash as $uidF => $c) if ($c > 0) $pdo->prepare("UPDATE users SET cash=cash-?, cash_reserved=cash_reserved+? WHERE id=?")->execute([round($c, 2), round($c, 2), $uidF]);
        foreach ($resSh as $kk => $q) if ($q > 0) { [$uR, $sR] = explode(':', $kk); $pdo->prepare("UPDATE wallets SET qty=qty-?, qty_reserved=qty_reserved+? WHERE user_id=? AND stock_id=?")->execute([$q, $q, $uR, $sR]); }
        $nowF = Db::now();
        foreach (array_chunk($newOrders, 200) as $chunk) {
            $vals = []; $args = [];
            foreach ($chunk as [$uidF, $sidF, $sideF, $qtyF, $priceF]) {
                $vals[] = "(?,?,?,?,?,?, 'active', NULL, ?)";
                array_push($args, $uidF, $sidF, $sideF, $qtyF, $qtyF, $priceF, $nowF);
            }
            $pdo->prepare("INSERT INTO orders (user_id, stock_id, side, qty, qty_init, price, status, expires_session, created_at) VALUES " . implode(',', $vals))->execute($args);
        }
    }

    /**
     * PERCEPCJA INFORMACJI wg strategii bota — serce mechaniki "różne boty
     * słuchają różnych wiadomości na różnych spółkach":
     *   fundamental  słucha TWARDYCH faktów ($nF), tym mocniej, im bardziej
     *                fundamentalny charakter ma spółka (niska aff); plotki
     *                waży ledwie 0.2 — "szum, nie sygnał".
     *   news         żywi się WSZYSTKIM, ale nastroje ($nS) gra ×1.6 i tym
     *                chętniej, im bardziej spekulacyjna (techniczna) spółka;
     *                komentarze techniczne podchwytuje w połowie siły.
     *   tech         zwraca MNOŻNIK czułości (1..1.6): komentarz techniczny
     *                w mediach wzmacnia pozycje bota AT; twarde fakty i plotki
     *                ignoruje — "wszystko i tak widać na wykresie".
     *   pozostałe    0 (trend/rsi/mm reagują na cenę, nie na słowa).
     */
    public static function newsPerception(string $strat, float $aff, float $nF, float $nS, float $nT): float
    {
        return match ($strat) {
            'fundamental' => $nF * (1.3 - 0.6 * $aff) + 0.2 * $nS,
            'news'        => $nF + 1.6 * $nS * (0.8 + 0.5 * $aff) + 0.5 * $nT,
            'tech'        => 1.0 + min(0.6, abs($nT) * 4.0),
            default       => 0.0,
        };
    }

    /** Jednorazowe dosianie botów TECHNICZNYCH do istniejącego świata (bez resetu). */
    private static function ensureTechBots(): void
    {
        if ((int) (self::one("SELECT v FROM game_state WHERE k='tech_bots_added'") ?: 0) === 1) return;
        self::setState('tech_bots_added', '1');
        try {
            $pdo = Db::pdo();
            $stocks = self::all("SELECT id, price FROM stocks");
            $n = (int) self::one("SELECT COUNT(*) FROM users WHERE username LIKE 'bot_tech_%'");
            $uStmt = $pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash, start_equity) VALUES (?,?,1,'tech',?,?)");
            $dStmt = $pdo->prepare("INSERT INTO bots (user_id, strategy, news_reactivity, technical_sensitivity, risk_appetite, horizon) VALUES (?,'tech',?,?,?,?)");
            $wStmt = $pdo->prepare("INSERT INTO wallets (user_id, stock_id, qty, avg_price) VALUES (?,?,300,?)");
            $rf = fn(float $a, float $b) => round($a + mt_rand() / mt_getrandmax() * ($b - $a), 2);
            $sumPrices = array_sum(array_map(fn($st) => (float) $st['price'], $stocks));
            for ($i = 1; $i <= 6; $i++) {
                $uStmt->execute(['bot_tech_' . ($n + $i), password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT), 1500000, 1500000 + 300 * $sumPrices]);
                $uid = (int) $pdo->lastInsertId();
                $dStmt->execute([$uid, $rf(0.5, 2.0), $rf(1.2, 2.2), $rf(0.5, 2.0), mt_rand(5, 30)]);
                foreach ($stocks as $st) $wStmt->execute([$uid, (int) $st['id'], (float) $st['price']]);
            }
            $t = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
            $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                           VALUES (?,?,'NEU','MARKET',NULL,0,0,?,?,?)")
                ->execute(['Na parkiet wchodzą algorytmy — ruszają fundusze analizy technicznej',
                           'Sześć nowych funduszy handluje wyłącznie na wskaźnikach AT. Sygnały techniczne będą teraz realnie ruszać kursami — sprawdź zakładkę Analiza na karcie spółki.',
                           $t, $t + 30, Db::now()]);
            Log::write('info', 'engine', 'bots.tech', 'dodano 6 botów technicznych (AT)');
        } catch (\Throwable $e) { Log::write('error', 'engine', 'bots.tech', $e->getMessage()); }
    }

    /* ---------- Nazwy botów (żeby w akcjonariacie wyglądały jak realni gracze/fundusze) ---------- */

    private const BOT_NAME_CORES = ['Bursztyn', 'Vistula', 'Kwarc', 'Meridian', 'Atlas', 'Fortis', 'Helvet', 'Piast',
        'Skala', 'Northgate', 'Bałtyk', 'Karpaty', 'Wawel', 'Odra', 'Sokół', 'Grunwald', 'Zenit', 'Aurora', 'Orion',
        'Delta', 'Sigma', 'Prometeusz', 'Feniks', 'Tytan', 'Kaskada', 'Bastion', 'Cyprys', 'Granit', 'Lazur', 'Onyks',
        'Nawigator', 'Kompas', 'Horyzont', 'Amber', 'Merkury', 'Saturn', 'Jowisz', 'Portus', 'Cedr', 'Klif'];
    private const BOT_NAME_INST = ['Fundusz %s', 'TFI %s', 'OFE %s', 'DM %s', '%s Capital', '%s Asset', '%s Invest', '%s Securities'];
    private const BOT_NAME_NICKS = ['byk', 'niedzwiedz', 'wilk_gieldowy', 'rekin', 'spekulant', 'kontrarianin', 'dywidenda_love',
        'inwestor', 'trader', 'makler', 'day_trader', 'swingowiec', 'cierpliwy_kap', 'chytry_grosz', 'zloty_strzal',
        'srebrny_byk', 'cyfrowy_wilk', 'papier_wartosc', 'grajek', 'parkiet', 'lowca_okazji', 'momentum', 'wartosciowy'];
    private const BOT_NAME_SURN = ['Kowalski', 'Nowak', 'Wisniewski', 'Lewandowski', 'Zielinski', 'Szymanski', 'Wozniak', 'Kaczmarek'];

    /** $n unikalnych, „ludzkich" nazw botów, nie kolidujących z $existing (małe litery = klucz unikalności). */
    public static function botNames(int $n, array $existing = []): array
    {
        $used = [];
        foreach ($existing as $e) $used[mb_strtolower((string) $e)] = 1;
        $out = [];
        $guard = 0;
        while (count($out) < $n && $guard++ < $n * 50 + 500) {
            if (mt_rand(0, 1) === 0) {
                $name = sprintf(self::BOT_NAME_INST[array_rand(self::BOT_NAME_INST)], self::BOT_NAME_CORES[array_rand(self::BOT_NAME_CORES)]);
            } else {
                $nick = self::BOT_NAME_NICKS[array_rand(self::BOT_NAME_NICKS)];
                $r = mt_rand(1, 3);
                $name = $r === 1 ? $nick . '_' . self::BOT_NAME_SURN[array_rand(self::BOT_NAME_SURN)]
                    : ($r === 2 ? $nick . mt_rand(72, 99) : $nick . '_' . mt_rand(100, 9999));
            }
            $k = mb_strtolower($name);
            if (isset($used[$k])) continue;
            $used[$k] = 1; $out[] = $name;
        }
        // awaryjne dopełnienie, gdyby pula się wyczerpała
        for ($i = 1; count($out) < $n; $i++) { $nm = 'inwestor_' . mt_rand(10000, 99999) . $i; if (!isset($used[mb_strtolower($nm)])) { $used[mb_strtolower($nm)] = 1; $out[] = $nm; } }
        return $out;
    }

    /** Jednorazowo (flaga bots_named): przezwij istniejące boty bot_* na losowe „ludzkie" nazwy. */
    private static function ensureBotNames(): void
    {
        if ((int) (self::one("SELECT v FROM game_state WHERE k='bots_named'") ?: 0) === 1) return;
        self::setState('bots_named', '1');
        try {
            $pdo = Db::pdo();
            $bots = self::all("SELECT id FROM users WHERE is_bot=1 AND username LIKE 'bot\\_%' ESCAPE '\\'");
            if (!$bots) return;
            $existing = self::col("SELECT username FROM users");
            $names = self::botNames(count($bots), $existing);
            $upd = $pdo->prepare("UPDATE users SET username=? WHERE id=?");
            foreach ($bots as $i => $b) $upd->execute([$names[$i], (int) $b['id']]);
            Log::write('info', 'engine', 'bots.rename', 'przezwano ' . count($bots) . ' botów na losowe nazwy');
        } catch (\Throwable $e) { Log::write('error', 'engine', 'bots.rename', $e->getMessage()); }
    }

    /** Docelowa populacja botów per strategia — animatorzy (mm) dają głębię arkusza,
     *  reszta („tania" w zleceniach) buduje różnorodny akcjonariat. */
    private const BOT_TARGET = ['mm' => 14, 'trend' => 18, 'rsi' => 18, 'fundamental' => 18, 'news' => 16, 'tech' => 16];
    /** Startowa gotówka i pakiet akcji na spółkę dla dokładanych botów (spójne z seed.php). */
    private const BOT_SEED = ['mm' => [8000000, 3000], 'trend' => [1500000, 300], 'rsi' => [1500000, 300],
        'fundamental' => [2000000, 300], 'news' => [1500000, 200], 'tech' => [1500000, 300]];

    /** Jednorazowo (flaga bots_expanded): dołóż botów do docelowej populacji — więcej płynności i większy akcjonariat. */
    private static function ensureBotPopulation(): void
    {
        if ((int) (self::one("SELECT v FROM game_state WHERE k='bots_expanded'") ?: 0) === 1) return;
        self::setState('bots_expanded', '1');
        try {
            $pdo = Db::pdo();
            $stocks = self::all("SELECT id, price FROM stocks");
            if (!$stocks) return;
            $sumPrices = array_sum(array_map(fn($st) => (float) $st['price'], $stocks));
            $rf = fn(float $a, float $b) => round($a + mt_rand() / mt_getrandmax() * ($b - $a), 2);
            $need = 0;
            $have = [];
            foreach (self::all("SELECT strategy, COUNT(*) c FROM bots GROUP BY strategy") as $r) $have[$r['strategy']] = (int) $r['c'];
            foreach (self::BOT_TARGET as $strat => $target) $need += max(0, $target - ($have[$strat] ?? 0));
            if ($need <= 0) return;
            $names = self::botNames($need, self::col("SELECT username FROM users"));
            $ni = 0;
            $uStmt = $pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash, start_equity) VALUES (?,?,1,?,?,?)");
            $bStmt = $pdo->prepare("INSERT INTO bots (user_id, strategy, news_reactivity, technical_sensitivity, risk_appetite, horizon) VALUES (?,?,?,?,?,?)");
            $wStmt = $pdo->prepare("INSERT INTO wallets (user_id, stock_id, qty, avg_price) VALUES (?,?,?,?)");
            $added = 0;
            foreach (self::BOT_TARGET as $strat => $target) {
                [$cash, $shares] = self::BOT_SEED[$strat];
                for ($i = ($have[$strat] ?? 0); $i < $target; $i++) {
                    $uStmt->execute([$names[$ni++], password_hash(bin2hex(random_bytes(6)), PASSWORD_DEFAULT), $strat, $cash, $cash + $shares * $sumPrices]);
                    $uid = (int) $pdo->lastInsertId();
                    $tsens = $strat === 'tech' ? $rf(1.2, 2.2) : $rf(0.5, 2.0);
                    $bStmt->execute([$uid, $strat, $rf(0.5, 2.0), $tsens, $rf(0.5, 2.0), mt_rand(5, 30)]);
                    foreach ($stocks as $st) $wStmt->execute([$uid, (int) $st['id'], $shares, (float) $st['price']]);
                    $added++;
                }
            }
            Log::write('info', 'engine', 'bots.expand', "dołożono $added botów (płynność + akcjonariat)");
        } catch (\Throwable $e) { Log::write('error', 'engine', 'bots.expand', $e->getMessage()); }
    }

    private static function cancelAllFor(int $uid): void
    {
        $pdo = Db::pdo();
        foreach (self::all("SELECT * FROM orders WHERE user_id=? AND status='active'", [$uid]) as $o) self::release($o);
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE user_id=? AND status='active'")->execute([$uid]);
    }

    /** Anuluj WSZYSTKIE zlecenia konta (też obronne SL/TP) i zwolnij rezerwacje — likwidacja subkonta wyzwania. */
    public static function releaseAllOrders(int $uid): void
    {
        $pdo = Db::pdo();
        foreach (self::all("SELECT * FROM orders WHERE user_id=? AND status IN ('active','pending')", [$uid]) as $o) self::release($o);
        $pdo->prepare("UPDATE orders SET status='cancelled' WHERE user_id=? AND status IN ('active','pending')")->execute([$uid]);
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
        $tickNow = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        // SL kroczący: próg podąża ZA rosnącym kursem (nigdy w dół) — zanim sprawdzimy wyzwalacze
        foreach (self::all("SELECT o.id, o.sl_price, o.trail_pct, s.price FROM orders o JOIN stocks s ON s.id=o.stock_id
                            WHERE o.status='pending' AND o.trail_pct IS NOT NULL") as $tr) {
            $newSl = round((float) $tr['price'] * (1 - (float) $tr['trail_pct'] / 100), 2);
            if ($newSl > (float) $tr['sl_price']) {
                $pdo->prepare("UPDATE orders SET sl_price=? WHERE id=? AND status='pending'")->execute([$newSl, (int) $tr['id']]);
            }
        }
        $nowTs = Db::now();
        $rows = self::all("SELECT o.*, s.price AS cur, s.ticker, s.halted_until FROM orders o JOIN stocks s ON s.id=o.stock_id WHERE o.status='pending'");
        foreach ($rows as $o) {
            if ($o['halted_until'] !== null && $o['halted_until'] !== '' && (string) $o['halted_until'] > $nowTs) continue;   // zawieszone notowania: stopy czekają na wznowienie
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
            if (!$ok && $txTo === $txFrom) {
                // pusta księga (np. tuż po wznowieniu notowań, zanim boty ją odbudują):
                // NIE konsumuj ochrony — przywróć zlecenie i spróbuj w kolejnym ticku
                $free = (int) (self::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$o['user_id'], $o['stock_id']]) ?: 0);
                if ($free >= (int) $o['qty']) {
                    $pdo->prepare("UPDATE wallets SET qty=qty-?, qty_reserved=qty_reserved+? WHERE user_id=? AND stock_id=?")
                        ->execute([(int) $o['qty'], (int) $o['qty'], $o['user_id'], $o['stock_id']]);
                    $pdo->prepare("UPDATE orders SET status='pending' WHERE id=?")->execute([$o['id']]);
                    Log::write('info', 'engine', 'stops.retry', ($hitSL ? 'SL' : 'TP') . " {$o['ticker']}: pusta księga — zlecenie obronne wraca do oczekujących", ['order_id' => (int) $o['id']]);
                    continue;
                }
            }
            Log::write($ok ? 'info' : 'warn', 'engine', $hitSL ? 'stops.sl' : 'stops.tp',
                sprintf('%s %s: kurs %s przekroczył próg %s — %s', $hitSL ? 'Stop-Loss' : 'Take-Profit', $o['ticker'],
                    number_format((float) $o['cur'], 2, ',', ' '),
                    number_format((float) ($hitSL ? $o['sl_price'] : $o['tp_price']), 2, ',', ' '), $msg),
                ['user_id' => (int) $o['user_id'], 'order_id' => (int) $o['id'], 'qty' => (int) $o['qty'], 'tx_from' => $txFrom, 'tx_to' => $txTo]);
            if (in_array((int) $o['user_id'], self::humanIds(), true)) {
                self::notify((int) $o['user_id'], 'stop',
                    ($hitSL ? '🛡️ Stop-Loss ' : '💰 Take-Profit ') . $o['ticker'] . ' wyzwolony przy ' . number_format((float) $o['cur'], 2, ',', ' ') . ' PLN — ' . $msg,
                    'order.php?id=' . (int) $o['id']);
                self::award((int) $o['user_id'], $hitSL ? 'sl_zadzialal' : 'tp_zadzialal');
            }
        }
    }

    /* ---------- Widełki statyczne (zawieszenia notowań) ---------- */

    public const HALT_BAND = 0.20;        // ±20% od otwarcia sesji zawiesza notowania (zapas: zwykłe zlecenia nie odpalają)
    public const HALT_MINUTES = 5;        // długość zawieszenia w minutach (wall-clock — niezależne od tempa crona)
    public const HALT_MAX_SESSION = 2;    // max zawieszeń spółki w jednej sesji

    /** Sekundy do wznowienia notowań (0 = handel dozwolony) — wall-clock. */
    public static function haltSecondsLeft(int $stockId): int
    {
        $until = self::one("SELECT halted_until FROM stocks WHERE id=?", [$stockId]);
        if ($until === false || $until === null || $until === '') return 0;
        $left = strtotime((string) $until) - time();
        return $left > 0 ? $left : 0;
    }

    /** Komunikat o zawieszeniu (null = handel dozwolony). Wołane przy każdym zleceniu. */
    private static function haltMessage(int $stockId): ?string
    {
        $left = self::haltSecondsLeft($stockId);
        if ($left <= 0) return null;
        $m = (int) ceil($left / 60);
        return '⏸ Notowania zawieszone (przekroczenie widełek) — wznowienie za ~' . $m . ' min. Zlecenie nie zostało przyjęte.';
    }

    /**
     * Widełki jak na prawdziwej giełdzie: |zmiana od otwarcia| >= 15% -> zawieszenie na 10 ticków.
     * Po wznowieniu widełki się ROZSZERZAJĄ (30% dla drugiego zawieszenia) — kurs może
     * dalej szukać równowagi, ale panika dostaje przymusową pauzę. Max 2 zawieszenia/sesję.
     */
    private static function checkHalts(int $t): void
    {
        $pdo = Db::pdo();
        $nowTs = Db::now();
        $untilTs = date('Y-m-d H:i:s', time() + self::HALT_MINUTES * 60);
        // kandydaci: spółki NIE zawieszone teraz (halted_until pusty lub już minął)
        foreach (self::all("SELECT id, ticker, name, price, day_open_price, halted_until, halts_session
                            FROM stocks WHERE day_open_price > 0 AND (halted_until IS NULL OR halted_until <= ?)", [$nowTs]) as $s) {
            $n = (int) $s['halts_session'];
            if ($n >= self::HALT_MAX_SESSION) continue;
            $chg = (float) $s['price'] / (float) $s['day_open_price'] - 1;
            $band = self::HALT_BAND * ($n + 1);   // 20%, po wznowieniu 40%
            if (abs($chg) < $band) continue;
            $pdo->prepare("UPDATE stocks SET halted_until=?, halts_session=halts_session+1 WHERE id=?")
                ->execute([$untilTs, (int) $s['id']]);
            $dir = $chg > 0 ? 'wzroście' : 'spadku';
            $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,kind,publish_tick,expire_tick,published_at)
                           VALUES (?,?,'NEU','COMPANY',?,1,0,'fundamental',?,?,?)")
                ->execute([
                    "⏸ Makleria zawiesza notowania {$s['ticker']} po $dir " . number_format(abs($chg) * 100, 1, ',', ' ') . '%',
                    "Kurs {$s['name']} przekroczył widełki statyczne ±" . round($band * 100) . "% od otwarcia sesji. Handel wstrzymany na ~" . self::HALT_MINUTES
                    . " minut — zlecenia można składać po wznowieniu. Po wznowieniu obowiązują rozszerzone widełki.",
                    (int) $s['id'], $t, $t + 30, Db::now(),
                ]);
            foreach (self::col("SELECT w.user_id FROM wallets w JOIN users u ON u.id=w.user_id
                                WHERE w.stock_id=? AND u.is_bot=0 AND (w.qty + w.qty_reserved) > 0", [(int) $s['id']]) as $uid) {
                self::notify((int) $uid, 'event', "⏸ Notowania {$s['ticker']} zawieszone po ruchu "
                    . ($chg > 0 ? '+' : '-') . number_format(abs($chg) * 100, 1, ',', ' ') . "% (masz te akcje). Wznowienie za ~" . self::HALT_MINUTES . ' min.',
                    'stock.php?id=' . (int) $s['id']);
            }
            Log::write('info', 'engine', 'halt.start', "{$s['ticker']}: zawieszenie #" . ($n + 1) . ' po ' . round($chg * 100, 1) . '%', ['until' => $untilTs]);
        }
    }

    /** Kapitał zamrożony poza kontem: aktywne lokaty + opłacone zapisy IPO przed przydziałem. */
    public static function lockedFunds(int $uid): float
    {
        $d = (float) (self::one("SELECT SUM(amount) FROM deposits WHERE user_id=? AND status='active'", [$uid]) ?: 0);
        $i = (float) (self::one("SELECT SUM(s.paid) FROM ipo_subs s JOIN ipo_offers o ON o.id=s.offer_id WHERE s.user_id=? AND o.status='open'", [$uid]) ?: 0);
        return round($d + $i + self::challengeLocked($uid), 2);
    }

    /** Wartość ZABLOKOWANA w wyzwaniach — wciąż majątek gracza (jak lokata), nie strata.
     *  Zapisy przed startem: buy-in + wpisowe (przed startem pełny zwrot). Wyzwania w toku:
     *  bieżąca wartość portfela-cienia (gotówka + zamrożone + akcje po kursie). Dzięki temu
     *  po zapisie kapitał NIE spada — buy-in schodzi z gotówki, ale wraca jako „zablokowane". */
    public static function challengeLocked(int $uid): float
    {
        $signup = (float) (self::one(
            "SELECT COALESCE(SUM(cp.buyin + cp.fee), 0)
             FROM challenge_players cp JOIN challenges c ON c.id = cp.challenge_id
             WHERE cp.user_id = ? AND c.status = 'signup' AND cp.shadow_user_id IS NULL", [$uid]) ?: 0);
        $running = (float) (self::one(
            "SELECT COALESCE(SUM(su.cash + su.cash_reserved + COALESCE(sv.v, 0)), 0)
             FROM challenge_players cp
             JOIN challenges c ON c.id = cp.challenge_id AND c.status = 'running'
             JOIN users su ON su.id = cp.shadow_user_id
             LEFT JOIN (SELECT w.user_id AS uid, SUM((w.qty + w.qty_reserved) * s.price) AS v
                        FROM wallets w JOIN stocks s ON s.id = w.stock_id GROUP BY w.user_id) sv ON sv.uid = su.id
             WHERE cp.user_id = ?", [$uid]) ?: 0);
        return round($signup + $running, 2);
    }

    /* ---------- Świece ---------- */

    public static function recordCandles(int $t): void
    {
        $pdo = Db::pdo();
        // Świece z REALNYCH transakcji (boty + GRACZE, także te złożone przez HTTP MIĘDZY tickami crona),
        // a nie tylko z handlu botów w tym ticku — inaczej ruch z zlecenia gracza nie pokazywał się na wykresie
        // (kurs skakał w bazie, ale świeca była płaska). Znacznik last_candle_tx to kursor ostatniej ujętej transakcji.
        [$trades, $maxTx] = self::tradesSinceCandle();
        foreach (self::all("SELECT id, price FROM stocks") as $st) {
            $sid = (int) $st['id'];
            $prev = self::closes($sid, 1);
            $o   = $prev ? (float) $prev[0] : (float) $st['price'];
            $cur = (float) $st['price'];   // aktualny kurs = ostatnia transakcja (bot lub gracz)
            $tr  = $trades[$sid] ?? [];
            if ($tr) { $ps = array_column($tr, 'p'); $c = $cur; $h = max(max($ps), $o, $cur); $l = min(min($ps), $o, $cur); $v = array_sum(array_column($tr, 'q')); }
            else     { $c = $cur; $h = max($o, $cur); $l = min($o, $cur); $v = 0; }
            $pdo->prepare("INSERT INTO candles (stock_id,t,o,h,l,c,v) VALUES (?,?,?,?,?,?,?)")->execute([$sid, $t, $o, $h, $l, $c, $v]);
        }
        self::setState('last_candle_tx', (string) $maxTx);   // kursor = dokładnie granica użyta wyżej (bez wyścigu z transakcją HTTP)
        // retencja: świece rosną 50/tick — trzymaj ~20k ticków wstecz (wystarcza na wykres sesyjny 80×200)
        if ($t % 500 === 0) $pdo->prepare("DELETE FROM candles WHERE t < ?")->execute([$t - 20000]);
    }

    /**
     * Transakcje (boty + gracze) od ostatniej ujętej świecy do BIEŻĄCEGO MAX(id) — pogrupowane per spółka.
     * Zwraca [trades, maxTx]; ten sam maxTx służy jako nowy kursor, więc żadna transakcja złożona w trakcie
     * przetwarzania nie zostaje pominięta (trafi do następnej świecy). Pierwszy raz (brak kursora) = od teraz.
     */
    private static function tradesSinceCandle(): array
    {
        $lw = self::one("SELECT v FROM game_state WHERE k='last_candle_tx'");
        $maxTx = (int) (self::one("SELECT COALESCE(MAX(id),0) FROM transactions") ?: 0);
        $lastTx = ($lw === false || $lw === null || $lw === '') ? $maxTx : (int) $lw;
        $out = [];
        if ($maxTx > $lastTx) {
            foreach (self::all("SELECT stock_id, price, qty FROM transactions WHERE id > ? AND id <= ? ORDER BY id ASC", [$lastTx, $maxTx]) as $tx) {
                $out[(int) $tx['stock_id']][] = ['p' => (float) $tx['price'], 'q' => (int) $tx['qty']];
            }
        }
        return [$out, $maxTx];
    }

    /* ---------- Raporty finansowe (miesięczne) ---------- */

    /** „Miesiąc" wzrostu zysku dla JEDNEGO raportu (stała normalizacja) — growth_potential×100/100 ≈ growth_potential%
     *  na raport, NIEZALEŻNIE od tego, co ile ticków raport wypada. Koniec z eksplozją zysków przy częstych raportach. */
    private const REPORT_GROWTH_UNIT = 100;

    /** Jednorazowo (flaga report_sched_v2): rozłóż next_report_tick wszystkich spółek RÓWNOMIERNIE na najbliższy
     *  „miesiąc" (N sesji). Naprawia stare światy, w których raporty wypadały po kilka na sesję i wszystkie naraz. */
    private static function ensureReportSchedule(): void
    {
        if ((int) (self::one("SELECT v FROM game_state WHERE k='report_sched_v2'") ?: 0) === 1) return;
        self::setState('report_sched_v2', '1');
        try {
            if ((int) (self::one("SELECT v FROM game_state WHERE k='report_sessions'") ?: 0) <= 0) self::setState('report_sessions', '30');
            $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
            [, , $tps] = self::sessionInfo($tick);
            $repSess    = max(1, (int) (self::one("SELECT v FROM game_state WHERE k='report_sessions'") ?: 30));
            $monthTicks = max(50, $repSess * max(1, (int) $tps));
            $pdo = Db::pdo();
            $upd = $pdo->prepare("UPDATE stocks SET next_report_tick=? WHERE id=?");
            foreach (self::col("SELECT id FROM stocks ORDER BY id") as $sid) {
                $upd->execute([$tick + mt_rand((int) round($monthTicks * 0.03), $monthTicks), (int) $sid]);   // różne dni w ciągu miesiąca
            }
            Log::write('info', 'engine', 'reports.reschedule', "rozłożono raporty na ~{$repSess} sesji ({$monthTicks} ticków)");
        } catch (\Throwable $e) { Log::write('warn', 'engine', 'reports.reschedule', $e->getMessage()); }
    }

    public static function generateReports(int $tick): void
    {
        self::ensureReportSchedule();   // jednorazowo: rozłóż raporty na ~miesiąc (różne dni), zamiast kilku na sesję
        $pdo = Db::pdo();
        $due = self::all("SELECT s.*, sec.profit_climate AS sector_climate FROM stocks s JOIN sectors sec ON sec.id = s.sector_id WHERE s.next_report_tick <= ?", [$tick]);
        if (!$due) return;
        $globalPer = max(1, (int) (self::one("SELECT v FROM game_state WHERE k='ticks_per_month'") ?: 20));
        // kolejny raport za ~N sesji (miesiąc), w tickach — adaptuje się do trybu godzin handlu (tps = długość sesji)
        [, , $tps] = self::sessionInfo($tick);
        $repSess    = max(1, (int) (self::one("SELECT v FROM game_state WHERE k='report_sessions'") ?: 30));
        $monthTicks = max(50, $repSess * max(1, (int) $tps));

        $mods = self::activeMods($tick);
        foreach ($due as $s) {
            $sid = (int) $s['id'];
            $shares = max(1.0, (float) $s['total_shares']);
            $prev = ((float) $s['last_profit']) ?: (float) $s['base_profit'];

            // oczekiwany wynik = poprzedni × ZNANY trend (miernik spółki + koniunktura branży + growth)
            // + CZASOWE nakładki z wydarzeń (kryzysy/boomy branż, kontrakty i afery spółek)
            $evTrend   = self::modVal($mods, 'stock', $sid, 'profit_trend');
            $evClimate = self::modVal($mods, 'sector', (int) $s['sector_id'], 'profit_climate');
            $trend_m  = ((float) $s['profit_trend'] + $evTrend + (float) $s['sector_climate'] + $evClimate + (float) $s['growth_potential'] * self::REPORT_GROWTH_UNIT) / 100.0;
            $expected = $prev * (1 + $trend_m);
            // faktyczny wynik = oczekiwany × niespodzianka (szum × agresywność, z sufitem)
            $noise    = (mt_rand(-10, 10) / 100.0) * max(0.2, min(1.4, (float) $s['aggressiveness']));
            $profit   = round(max(0, $expected * (1 + $noise)), 2);
            $eps      = round($profit * 12.0 / $shares, 4);
            $surprise = $expected != 0.0 ? ($profit - $expected) / abs($expected) * 100.0 : 0.0;

            // REAKCJA na wyniki = NIESPODZIANKA (oczekiwany wzrost rynek wycenił już wcześniej).
            // Wynik POWYŻEJ prognoz podbija fundament, PONIŻEJ — zbija; sam wzrost zysku r/r NIE
            // wystarcza, jeśli jest gorszy od oczekiwań (koniec z „słaby raport, a kurs w górę").
            // Docelową wycenę z zysków (C/Z × EPS) i tak dociąga kotwica 0.6%/tick w runTick
            // (last_eps zaktualizowane niżej) — po miss kurs najpierw spada, potem grinduje ku wartości.
            $cur   = (float) $s['fundamental'];
            $react = ($surprise / 100.0) * (0.5 + 0.5 * (float) $s['news_impact']);    // ~ wielkość niespodzianki
            if ($react < 0) $react /= max(0.5, (float) $s['financial_resilience']);    // odporne spadają mniej
            $react = max(-0.10, min(0.10, $react));                                     // cap skoku z raportu (żeby nie zawieszać co chwila)
            $newFund = max(1, round($cur * (1 + $react), 2));

            // ZMIANA NASTAWIENIA (re-rating C/Z): pojedynczy słaby raport to jeszcze chwilowy ruch,
            // ale SERIA (2–3 raporty w tę samą stronę) to zmiana sentymentu — trwale przesuwa wycenę.
            // Niższe C/Z = niższa wartość godziwa, ku której ciąży kurs (kotwica) i którą widzą boty
            // fundamentalne; boty techniczne łapią powstały trend przez sam kurs. Beaty re-rate w górę.
            $prevSurp  = array_map('floatval', self::col("SELECT surprise_pct FROM financial_reports WHERE stock_id=? ORDER BY id DESC LIMIT 2", [$sid]));
            $streakSum = $surprise; $streakN = 1;
            foreach ($prevSurp as $ps) { $streakSum += $ps; $streakN++; }
            $streakAvg = $streakSum / $streakN;                                          // średnia niespodzianka z ostatnich (do 3) raportów
            $sameDir   = isset($prevSurp[0]) && (($surprise < 0 && $prevSurp[0] < 0) || ($surprise > 0 && $prevSurp[0] > 0));
            $rerate    = max(-0.07, min(0.07, ($streakAvg / 100.0) * ($sameDir ? 0.9 : 0.45)));   // seria = mocniejszy re-rating
            $newPe     = max(4.0, min(60.0, (float) $s['pe_target'] * (1 + $rerate)));

            // następny raport tej spółki za ~miesiąc (N sesji) z rozrzutem ±15% — spółki raportują w RÓŻNE dni,
            // a nie wszystkie naraz; kadencja niezależna od długości ticka/sesji
            $nextRep = $tick + mt_rand((int) round($monthTicks * 0.85), (int) round($monthTicks * 1.15));
            $pdo->prepare("UPDATE stocks SET fundamental=?, last_profit=?, last_eps=?, pe_target=?, next_report_tick=? WHERE id=?")
                ->execute([$newFund, $profit, $eps, round($newPe, 2), $nextRep, $sid]);

            // dywidenda: spółka dzieli się zyskiem wg swojej polityki wypłat (payout% zysku / liczbę akcji);
            // wydarzenie (np. afera księgowa) może ją czasowo zawiesić
            $dps = 0.0;
            $payout = (float) ($s['dividend_payout'] ?? 0);
            if (self::modVal($mods, 'stock', $sid, 'dividend_pause') > 0) $payout = 0;
            if ($payout > 0 && $profit > 0) {
                $dps = floor($payout * $profit / $shares * 100) / 100;   // w dół, do grosza
                if ($dps >= 0.01) self::payDividend($sid, (string) $s['ticker'], $dps, $tick);
                else $dps = 0.0;
            }

            $period = 'Miesiąc ' . (int) round($tick / $globalPer);
            $marza = mt_rand(9, 22);                                  // marża netto % (kosmetyka raportu — zmienna, jak w realu)
            $rev = round($profit / ($marza / 100), 2); $cost = round($rev - $profit, 2);
            $pdo->prepare("INSERT INTO financial_reports (stock_id,tick,period,report_date,revenue,costs,net_profit,eps,expected_eps,surprise_pct,dividend)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$sid, $tick, $period, Db::now(), $rev, $cost, $profit, $eps, round((float) $s['last_eps'], 4), round($surprise, 2), $dps]);

            // powiadom ludzkich akcjonariuszy o raporcie ich spółki
            foreach (self::all("SELECT w.user_id FROM wallets w JOIN users u ON u.id=w.user_id
                                WHERE w.stock_id=? AND u.is_bot=0 AND (w.qty + w.qty_reserved) > 0", [$sid]) as $hh) {
                self::notify((int) $hh['user_id'], 'report',
                    "📊 {$s['ticker']} opublikował raport: zysk " . number_format($profit, 0, ',', ' ') . " PLN, niespodzianka " . ($surprise >= 0 ? '+' : '') . number_format($surprise, 1, ',', ' ') . '%',
                    "stock.php?id=$sid");
            }

            // KOMUNIKAT WYNIKOWY z pełną treścią: r/r, marża, EPS vs konsensus, komentarz
            // zarządu zależny od zaskoczenia. Klasa: fundamental (twarde liczby).
            $type = $surprise >= 3 ? 'POS' : ($surprise <= -3 ? 'NEG' : 'NEU');
            $rr = $prev > 0 ? ($profit / $prev - 1) * 100 : 0.0;
            $beat = $surprise >= 3 ? 'POWYŻEJ oczekiwań' : ($surprise <= -3 ? 'PONIŻEJ oczekiwań' : 'zgodnie z oczekiwaniami');
            $head = sprintf('Wyniki %s: zysk %s PLN (%s%s%% r/r) — %s',
                $s['ticker'], number_format($profit, 0, ',', ' '),
                $rr >= 0 ? '+' : '', number_format($rr, 1, ',', ' '), $beat);
            $mgmt = $surprise >= 8
                ? 'Zarząd: „Kwartał potwierdza siłę modelu biznesowego — portfel zamówień pozwala patrzeć na kolejne okresy z optymizmem."'
                : ($surprise <= -8
                    ? 'Zarząd: „Wyniki nas nie satysfakcjonują. Wdrażamy program poprawy rentowności i wrócimy do rynku z aktualizacją planów."'
                    : 'Zarząd ocenia wyniki jako zgodne z realizowaną strategią.');
            $body = 'Przychody: ' . number_format($rev, 0, ',', ' ') . ' PLN · zysk netto: ' . number_format($profit, 0, ',', ' ')
                . ' PLN (marża ' . $marza . '%) · EPS: ' . number_format($eps, 2, ',', ' ') . ' PLN wobec oczekiwanych '
                . number_format($expected * 12.0 / $shares, 2, ',', ' ') . ' PLN — zaskoczenie ' . ($surprise >= 0 ? '+' : '')
                . number_format($surprise, 1, ',', ' ') . '%. Zysk ' . ($rr >= 0 ? 'wzrósł' : 'spadł') . ' o '
                . number_format(abs($rr), 1, ',', ' ') . '% wobec poprzedniego raportu.'
                . ($dps > 0 ? ' Spółka wypłaca dywidendę ' . number_format($dps, 2, ',', ' ') . ' PLN na akcję.' : '')
                . ' ' . $mgmt;
            $impact = max(-0.08, min(0.08, $surprise / 100.0 * (float) $s['news_impact'] * 0.12));  // lekki dryf-echo (główna reakcja to skok fundamentu wyżej)
            $pdo->prepare("INSERT INTO news (headline,body,type,kind,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                           VALUES (?,?,?,'fundamental',?,?,1,?,?,?,?)")
                ->execute([$head, $body, $type, 'COMPANY', $sid, $impact, $tick, $tick + 8, Db::now()]);

            // REAKCJA ANALITYKÓW: duże zaskoczenie -> za kilka ticków dom maklerski
            // zmienia rekomendację (kaskada istniejącym mechanizmem wydarzeń)
            if (abs($surprise) >= 8 && mt_rand(1, 100) <= 60) {
                $pdo->prepare("INSERT INTO scheduled_events (due_tick, template_code, sector_id, stock_id) VALUES (?,?,?,?)")
                    ->execute([$tick + mt_rand(3, 8), $surprise > 0 ? 'rekomendacja_kupuj' : 'rekomendacja_sprzedaj', null, $sid]);
            }
        }
    }

    /**
     * Wypłata dywidendy: gotówka dla każdego akcjonariusza (też za akcje w zleceniach),
     * odcięcie kursu i fundamentu o wartość dywidendy (nie ma darmowych pieniędzy),
     * news ESPI + dziennik (ludzcy gracze dostają osobny wpis "skąd ta kasa").
     */
    private static function payDividend(int $sid, string $ticker, float $dps, int $tick): void
    {
        $pdo = Db::pdo();
        $holders = self::all("SELECT w.user_id, (w.qty + w.qty_reserved) AS n, u.is_bot
                              FROM wallets w JOIN users u ON u.id = w.user_id
                              WHERE w.stock_id = ? AND (w.qty + w.qty_reserved) > 0", [$sid]);
        $total = 0.0;
        foreach ($holders as $h) {
            $amt = round($dps * (int) $h['n'], 2);
            if ($amt <= 0) continue;
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$amt, $h['user_id']]);
            $total += $amt;
            if (!(int) $h['is_bot']) {
                self::ledger((int) $h['user_id'], $amt, 'dividend',
                    "Dywidenda $ticker: " . (int) $h['n'] . " szt. × " . number_format($dps, 2, ',', ' ') . " PLN", 'stock.php?id=' . $sid);
                Log::write('info', 'engine', 'dividend.received',
                    "Dywidenda $ticker: +" . number_format($amt, 2, ',', ' ') . " PLN (" . (int) $h['n'] . " szt. × " . number_format($dps, 2, ',', ' ') . " PLN)",
                    ['user_id' => (int) $h['user_id']]);
                self::notify((int) $h['user_id'], 'dividend',
                    "💰 Dywidenda $ticker: +" . number_format($amt, 2, ',', ' ') . " PLN (" . (int) $h['n'] . " szt. × " . number_format($dps, 2, ',', ' ') . ")",
                    "stock.php?id=$sid");
                self::award((int) $h['user_id'], 'pierwsza_dywidenda');
                $divCo = (int) self::one("SELECT COUNT(DISTINCT w.stock_id) FROM wallets w JOIN stocks st ON st.id=w.stock_id
                                          WHERE w.user_id=? AND (w.qty + w.qty_reserved) > 0 AND st.dividend_payout > 0", [$h['user_id']]);
                if ($divCo >= 5) self::award((int) $h['user_id'], 'rentier');
            }
        }
        // odcięcie dywidendy (kurs, fundament i otwarcie dnia w dół o DPS)
        $st = self::row("SELECT price, fundamental, day_open_price FROM stocks WHERE id=?", [$sid]);
        $pdo->prepare("UPDATE stocks SET price=?, fundamental=?, day_open_price=? WHERE id=?")->execute([
            max(1, round((float) $st['price'] - $dps, 2)),
            max(1, round((float) $st['fundamental'] - $dps, 2)),
            max(1, round((float) $st['day_open_price'] - $dps, 2)),
            $sid,
        ]);
        self::setState('dividends_paid', (string) round((float) (self::one("SELECT v FROM game_state WHERE k='dividends_paid'") ?: 0) + $total, 2));
        Log::write('info', 'engine', 'dividend.paid',
            "$ticker: dywidenda " . number_format($dps, 2, ',', ' ') . " PLN/akcję — wypłacono " . number_format($total, 2, ',', ' ') . " PLN (" . count($holders) . " akcjonariuszy)");
        // brak osobnego newsa ESPI o dywidendzie — jest już w komunikacie wynikowym spółki,
        // a akcjonariusze dostają powiadomienie „💰 Dywidenda …". Mniej duplikatów w strumieniu.
    }

    /* ---------- Newsy / ESPI (generowanie + ciągły wpływ) ---------- */

    public static function generateNews(int $tick): void
    {
        // Newsroom 2.0: cały strumień informacyjny (spółki/sektory/makro/komentarze
        // techniczne/konsensusy) generuje src/Newsroom.php — treści z kodu,
        // wypełniane danymi spółek. Stare szablony z bazy przeszły do historii.
        if (!class_exists('Newsroom')) require_once __DIR__ . '/Newsroom.php';
        Newsroom::onTick($tick);
    }

    /**
     * Ciągły, zanikający wpływ aktywnych newsów na wartość fundamentalną.
     * ROUTING WG KLASY: fundamental = pełna siła (twarde fakty przesuwają wycenę);
     * sentiment = ~45% (nastroje ruszają kursem głównie przez boty newsowe,
     * a po wygaśnięciu kotwica wyceny ściąga kurs z powrotem); technical = 0
     * (komentarz z wykresu nie zmienia wartości firmy — działa przez boty AT).
     */
    public const KIND_FUNDAMENT_WEIGHT = ['fundamental' => 1.0, 'sentiment' => 0.45, 'technical' => 0.0];
    // wzmocnienie reakcji kursu na newsy/ESPI: news rusza kurs+fundament razem (patrz applyNewsImpact).
    // 1.0 = jak dawniej (ledwo widoczne); wyżej = wyraźniejsze skoki. „wyraźnie" ≈ 1.6.
    public const NEWS_IMPACT_GAIN = 1.6;

    public static function applyNewsImpact(int $tick): void
    {
        $pdo = Db::pdo();
        $active = self::all("SELECT scope, target_id, impact_strength, publish_tick, expire_tick, kind
                             FROM news WHERE publish_tick <= ? AND expire_tick > ? AND impact_strength <> 0", [$tick, $tick]);
        foreach ($active as $nw) {
            $kw = self::KIND_FUNDAMENT_WEIGHT[$nw['kind'] ?? 'fundamental'] ?? 1.0;
            if ($kw <= 0) continue;   // technical (np. „nietypowy obrót") = flavor, nie rusza kursem
            $span  = max(1, (int) $nw['expire_tick'] - (int) $nw['publish_tick']);
            $decay = ((int) $nw['expire_tick'] - $tick) / $span;               // 1 -> 0
            // News ma WIDOCZNIE ruszać kursem: gain podbija reakcję, a ruch idzie w KURS I FUNDAMENT
            // naraz (jak market maker, l. 1197) — natychmiastowy i „trzyma się" (arbitraż nie widzi luki,
            // boty dokładają wolumen). Wcześniej ruszał tylko fundament -> kurs ledwo drgał, tonął w szumie.
            // Skala: mocny ESPI (impact ~0,5, dur ~12) daje ~+4-5% narastająco; słabszy news ~+1-2%.
            $nudge = ((float) $nw['impact_strength'] / 100.0) * $decay * $kw * self::NEWS_IMPACT_GAIN;
            if (abs($nudge) < 1e-9) continue;
            if ($nw['scope'] === 'COMPANY') {
                $pdo->prepare("UPDATE stocks SET price = ROUND(price * (1+?), 2), fundamental = ROUND(fundamental * (1+?), 2) WHERE id=? AND price > 1")->execute([$nudge, $nudge, (int) $nw['target_id']]);
            } elseif ($nw['scope'] === 'SECTOR') {
                $pdo->prepare("UPDATE stocks SET price = ROUND(price * (1+?), 2), fundamental = ROUND(fundamental * (1+?), 2) WHERE sector_id=? AND price > 1")->execute([$nudge, $nudge, (int) $nw['target_id']]);
            } else {
                $pdo->prepare("UPDATE stocks SET price = ROUND(price * (1+?), 2), fundamental = ROUND(fundamental * (1+?), 2) WHERE price > 1")->execute([$nudge, $nudge]);
            }
        }
    }

    /* ---------- Market maker (GM): zaplanowany ruch popytu/podaży ---------- */

    /** Zaplanuj ruch rynku: łączna zmiana $pct% na $durationTicks ticków, start za $delayTicks. Zwraca id. */
    public static function scheduleMarketMove(string $scope, ?int $targetId, float $pct, int $durationTicks, int $delayTicks, ?string $label = null): int
    {
        $scope = in_array($scope, ['MARKET', 'SECTOR', 'COMPANY'], true) ? $scope : 'MARKET';
        $tick  = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        $start = $tick + max(0, $delayTicks);
        $end   = $start + max(1, $durationTicks);
        Db::pdo()->prepare("INSERT INTO market_moves (scope, target_id, pct, start_tick, end_tick, status, label, created_at) VALUES (?,?,?,?,?,'active',?,?)")
            ->execute([$scope, $scope === 'MARKET' ? null : $targetId, round($pct, 2), $start, $end, $label !== null ? mb_substr($label, 0, 120) : null, Db::now()]);
        return (int) Db::pdo()->lastInsertId();
    }

    public static function cancelMarketMove(int $id): void
    {
        Db::pdo()->prepare("UPDATE market_moves SET status='cancelled' WHERE id=? AND status='active'")->execute([$id]);
    }

    /**
     * Zastosuj aktywne ruchy market makera. Każdy rozkłada łączne $pct na cały okres (składany
     * współczynnik na tick), ruszając KURS i FUNDAMENT razem — dzięki temu ruch „się trzyma"
     * (arbitraż nie widzi luki i nie odwraca go od razu), a boty i tak dokładają wolumen.
     */
    public static function applyMarketMoves(int $tick): void
    {
        $moves = self::all("SELECT * FROM market_moves WHERE status='active' AND start_tick <= ? AND end_tick >= ?", [$tick, $tick - 1]);
        if (!$moves) return;
        $pdo = Db::pdo();
        foreach ($moves as $m) {
            $start = (int) $m['start_tick'];
            $end   = max($start + 1, (int) $m['end_tick']);
            $dur   = max(1, $end - $start);
            $pct   = (float) $m['pct'];
            $f     = pow(1.0 + max(-0.95, min(5.0, $pct / 100.0)), 1.0 / $dur) - 1.0;   // składany współczynnik na tick
            if ($m['scope'] === 'SECTOR' && $m['target_id'] !== null) {
                $pdo->prepare("UPDATE stocks SET price = ROUND(price * (1+?), 2), fundamental = ROUND(fundamental * (1+?), 2) WHERE sector_id=? AND price > 1")->execute([$f, $f, (int) $m['target_id']]);
            } elseif ($m['scope'] === 'COMPANY' && $m['target_id'] !== null) {
                $pdo->prepare("UPDATE stocks SET price = ROUND(price * (1+?), 2), fundamental = ROUND(fundamental * (1+?), 2) WHERE id=? AND price > 1")->execute([$f, $f, (int) $m['target_id']]);
            } else {
                $pdo->prepare("UPDATE stocks SET price = ROUND(price * (1+?), 2), fundamental = ROUND(fundamental * (1+?), 2) WHERE price > 1")->execute([$f, $f]);
            }
            if ($tick >= $end) $pdo->prepare("UPDATE market_moves SET status='done' WHERE id=?")->execute([(int) $m['id']]);
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
             SELECT u.id, ?, ROUND(u.cash + u.cash_reserved + COALESCE(sv.v, 0) + COALESCE(dep.v, 0) + COALESCE(ipo.v, 0)
                                   + COALESCE(chs.v, 0) + COALESCE(chr.v, 0), 2)
             FROM users u
             LEFT JOIN (SELECT w.user_id AS uid, SUM((w.qty + w.qty_reserved) * s.price) AS v
                        FROM wallets w JOIN stocks s ON s.id = w.stock_id GROUP BY w.user_id) sv ON sv.uid = u.id
             LEFT JOIN (SELECT user_id AS uid, SUM(amount) AS v FROM deposits WHERE status='active' GROUP BY user_id) dep ON dep.uid = u.id
             LEFT JOIN (SELECT s2.user_id AS uid, SUM(s2.paid) AS v FROM ipo_subs s2
                        JOIN ipo_offers o2 ON o2.id = s2.offer_id AND o2.status='open' GROUP BY s2.user_id) ipo ON ipo.uid = u.id
             -- zablokowane w wyzwaniu (dla WŁAŚCICIELA): zapisy przed startem = buy-in+wpisowe
             LEFT JOIN (SELECT cp.user_id AS uid, SUM(cp.buyin + cp.fee) AS v FROM challenge_players cp
                        JOIN challenges c ON c.id = cp.challenge_id AND c.status='signup' AND cp.shadow_user_id IS NULL
                        GROUP BY cp.user_id) chs ON chs.uid = u.id
             -- wyzwania w toku = bieżąca wartość portfela-cienia
             LEFT JOIN (SELECT cp.user_id AS uid, SUM(su.cash + su.cash_reserved + COALESCE(ssv.v, 0)) AS v
                        FROM challenge_players cp
                        JOIN challenges c ON c.id = cp.challenge_id AND c.status='running'
                        JOIN users su ON su.id = cp.shadow_user_id
                        LEFT JOIN (SELECT w.user_id AS uid, SUM((w.qty + w.qty_reserved) * s.price) AS v
                                   FROM wallets w JOIN stocks s ON s.id = w.stock_id GROUP BY w.user_id) ssv ON ssv.uid = su.id
                        GROUP BY cp.user_id) chr ON chr.uid = u.id
             WHERE (u.is_bot = 0 AND u.role = 'player') OR u.role = 'challenger'"
        )->execute([$t]);
        if ($t % 500 === 0) Db::pdo()->prepare("DELETE FROM equity_history WHERE t < ?")->execute([$t - 10000]);
    }

    /* ---------- Godziny handlu (rynek otwarty jak prawdziwa giełda) ---------- */

    /** [włączone?, otwarcie 'HH:MM', zamknięcie 'HH:MM']. Domyślnie WŁĄCZONE 07:50-22:00 (czas polski). */
    public static function marketHours(): array
    {
        $en = self::one("SELECT v FROM game_state WHERE k='market_hours_enabled'");
        if ($en === false || $en === null) { $en = '1'; self::setState('market_hours_enabled', '1'); }
        $open  = (string) (self::one("SELECT v FROM game_state WHERE k='market_open_time'") ?: '07:50');
        $close = (string) (self::one("SELECT v FROM game_state WHERE k='market_close_time'") ?: '22:00');
        return [(int) $en === 1, $open, $close];
    }

    public static function nowWarsaw(): \DateTime
    {
        return new \DateTime('now', new \DateTimeZone('Europe/Warsaw'));
    }

    /** Czy giełda jest teraz otwarta? ($hm do testów: 'HH:MM' zamiast zegara) */
    public static function marketIsOpen(?string $hm = null): bool
    {
        [$en, $open, $close] = self::marketHours();
        if (!$en) return true;
        $hm = $hm ?? self::nowWarsaw()->format('H:i');
        return $hm >= $open && $hm < $close;
    }

    /* ---------- Sesje giełdowe i cel gry ---------- */

    /**
     * [numer sesji, ile zostało do końca, długość sesji].
     * Przy włączonych godzinach handlu sesja = dzień giełdowy: numer trzymany w stanie,
     * "zostało" to minuty do zamknięcia (0 po zamknięciu), długość = minuty dnia handlu.
     * Bez godzin handlu (lokalne testy): sesja = ticks_per_session ticków, jak dotąd.
     */
    public static function sessionInfo(?int $tick = null): array
    {
        [$en, $open, $close] = self::marketHours();
        if ($en) {
            $n = max(1, (int) (self::one("SELECT v FROM game_state WHERE k='session'") ?: 1));
            [$oh, $om] = array_map('intval', explode(':', $open));
            [$ch, $cm] = array_map('intval', explode(':', $close));
            $dayLen = max(1, ($ch * 60 + $cm) - ($oh * 60 + $om));
            $now = self::nowWarsaw();
            $cur = (int) $now->format('H') * 60 + (int) $now->format('i');
            $left = ($cur >= $oh * 60 + $om && $cur < $ch * 60 + $cm) ? ($ch * 60 + $cm - $cur) : 0;
            return [$n, $left, $dayLen];
        }
        $tps = max(1, (int) (self::one("SELECT v FROM game_state WHERE k='ticks_per_session'") ?: 20));
        if ($tick === null) $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        $n = intdiv(max(0, $tick), $tps) + 1;
        return [$n, $tps - (max(0, $tick) % $tps), $tps];
    }

    /** Tick, na którym otwarto BIEŻĄCĄ sesję (do obrotu dziennego). W trybie godzin handlu
     *  sessionInfo zwraca tps=dlugosc_dnia_w_minutach, więc (sesja-1)*tps NIE jest ticiem —
     *  trzeba czytać zapamiętany session_start_tick (ustawiany przy każdym rollu). */
    public static function sessionStartTick(): int
    {
        $v = self::one("SELECT v FROM game_state WHERE k='session_start_tick'");
        return ($v === false || $v === null) ? 0 : (int) $v;
    }

    /** Data (Y-m-d) danej sesji giełdowej lub null, gdy nieznana. Bieżąca sesja = dzisiejszy dzień. */
    public static function sessionDate(int $n): ?string
    {
        $d = self::one("SELECT trade_date FROM session_dates WHERE session=?", [$n]);
        if ($d !== false && $d !== null && $d !== '') return (string) $d;
        [$cur] = self::sessionInfo();
        if ($n === (int) $cur) {   // bieżąca sesja jeszcze niezapisana -> dzisiejsza data
            $sd = self::one("SELECT v FROM game_state WHERE k='session_date'");
            return ($sd !== false && $sd !== null && $sd !== '') ? (string) $sd : self::nowWarsaw()->format('Y-m-d');
        }
        return null;
    }

    /** Wyczyść cały strumień ESPI/wiadomości (numer sesji ani kursy nietknięte). Zwraca liczbę usuniętych. */
    public static function clearNews(): int
    {
        $n = (int) (self::one("SELECT COUNT(*) FROM news") ?: 0);
        Db::pdo()->exec("DELETE FROM news");
        Log::write('info', 'gm', 'news.clear', "wyczyszczono strumień ESPI: $n wpisów");
        return $n;
    }

    /** Na otwarciu nowej sesji: kurs otwarcia dnia + wygaśnięcie zleceń sesyjnych (ze zwrotem escrow). */
    private static function rollSession(int $tick): void
    {
        [$hoursOn] = self::marketHours();
        if ($hoursOn) {
            // sesja = dzień giełdowy: nowa sesja przy pierwszym ticku po otwarciu w nowym dniu
            if (!self::marketIsOpen()) return;
            $today = self::nowWarsaw()->format('Y-m-d');
            $lastDate = self::one("SELECT v FROM game_state WHERE k='session_date'");
            if ($lastDate === $today) return;
            $prev = (int) (self::one("SELECT v FROM game_state WHERE k='session'") ?: 1);
            if ($lastDate === false || $lastDate === null) {
                // pierwsza aktywacja trybu godzin (świeży świat albo istniejący po aktualizacji):
                // zakotwicz dzień i start sesji bez podbijania numeru
                self::setState('session_date', $today);
                self::setState('session_start_tick', (string) $tick);
                return;
            }
            $n = $prev + 1;
            self::setState('session_date', $today);
        } else {
            [$n] = self::sessionInfo($tick);
            $prev = (int) (self::one("SELECT v FROM game_state WHERE k='session'") ?: 0);
            if ($n === $prev) return;
        }
        // zapamiętaj datę tej sesji (numer -> dzień) do wyświetlania „Sesja #N · data"
        try {
            $rollDate = self::one("SELECT v FROM game_state WHERE k='session_date'") ?: self::nowWarsaw()->format('Y-m-d');
            Db::pdo()->prepare("INSERT INTO session_dates (session, trade_date) VALUES (?, ?)")->execute([$n, $rollDate]);
        } catch (\Throwable $e) { /* data tej sesji już zapisana — pomiń */ }
        // świece dzienne D1 (wykresy tydzień/miesiąc/rok): zrzut ZAMYKANEJ sesji,
        // koniecznie PRZED nadpisaniem day_open_price nowym kursem otwarcia
        try {
            $sst = (int) (self::one("SELECT v FROM game_state WHERE k='session_start_tick'") ?: 0);
            $pdoD = Db::pdo();
            $hl = [];
            foreach (self::all("SELECT stock_id, MAX(h) h, MIN(l) l, SUM(v) v FROM candles WHERE t > ? GROUP BY stock_id", [$sst]) as $r) {
                $hl[(int) $r['stock_id']] = $r;
            }
            $ins = $pdoD->prepare("INSERT INTO candles_daily (stock_id, session, o, h, l, c, v) VALUES (?,?,?,?,?,?,?)");
            foreach (self::all("SELECT id, price, day_open_price FROM stocks") as $st) {
                $sid2 = (int) $st['id'];
                $o = (float) $st['day_open_price'] > 0 ? (float) $st['day_open_price'] : (float) $st['price'];
                $c = (float) $st['price'];
                $h = isset($hl[$sid2]) ? max((float) $hl[$sid2]['h'], $o, $c) : max($o, $c);
                $l = isset($hl[$sid2]) ? min((float) $hl[$sid2]['l'], $o, $c) : min($o, $c);
                try { $ins->execute([$sid2, $n - 1, $o, $h, $l, $c, (int) ($hl[$sid2]['v'] ?? 0)]); }
                catch (\Throwable $e) { /* duplikat (powtórny roll) — pomiń */ }
            }
            if (($n % 50) === 0) $pdoD->prepare("DELETE FROM candles_daily WHERE session < ?")->execute([$n - 400]);  // ~rok+ historii
        } catch (\Throwable $e) { Log::write('warn', 'engine', 'candles.daily', $e->getMessage()); }
        Db::pdo()->exec("UPDATE stocks SET day_open_price = price");
        Db::pdo()->exec("UPDATE stocks SET halts_session = 0");   // nowa sesja = świeży limit zawieszeń (widełki)

        $expired = self::all("SELECT * FROM orders WHERE status='active' AND expires_session IS NOT NULL AND expires_session < ?", [$n]);
        foreach ($expired as $o) {
            self::release($o);
            if (in_array((int) $o['user_id'], self::humanIds(), true)) {
                self::notify((int) $o['user_id'], 'order', '⌛ Zlecenie #' . (int) $o['id'] . ' wygasło z końcem sesji — rezerwacja wróciła.', 'order.php?id=' . (int) $o['id']);
            }
        }
        if ($expired) {
            Db::pdo()->prepare("UPDATE orders SET status='expired' WHERE status='active' AND expires_session IS NOT NULL AND expires_session < ?")->execute([$n]);
            Log::write('info', 'engine', 'orders.expired', 'wygasło zleceń sesyjnych: ' . count($expired), ['session' => $n]);
        }
        // odznaki sesyjne: ±10% kapitału w zamkniętej właśnie sesji (z equity_history)
        try {
            [, , $tps2] = self::sessionInfo($tick);
            // start ZAMYKANEJ sesji: zapamiętany tick jej otwarcia (fallback: rachunek tickowy)
            $t0 = (int) (self::one("SELECT v FROM game_state WHERE k='session_start_tick'") ?: ($n - 2) * $tps2);
            $t1 = $tick;
            if ($t0 >= 0) {
                foreach (self::all("SELECT id FROM users WHERE is_bot=0 AND role='player'") as $hu) {
                    $e0 = (float) (self::one("SELECT equity FROM equity_history WHERE user_id=? AND t >= ? ORDER BY t ASC LIMIT 1", [$hu['id'], $t0]) ?: 0);
                    $e1 = (float) (self::one("SELECT equity FROM equity_history WHERE user_id=? AND t <= ? ORDER BY t DESC LIMIT 1", [$hu['id'], $t1]) ?: 0);
                    if ($e0 > 0 && $e1 > 0) {
                        if ($e1 >= $e0 * 1.10) self::award((int) $hu['id'], 'rajd_10');
                        if ($e1 <= $e0 * 0.90) self::award((int) $hu['id'], 'lekcja_pokory');
                    }
                }
            }
        } catch (\Throwable $e) { /* odznaki nie psują sesji */ }
        // wyzwania: start/rozstrzygnięcie/kolejna edycja na granicy sesji
        try {
            if (!class_exists('Challenges')) require_once __DIR__ . '/Challenges.php';
            Challenges::onRoll($n, $tick);
        } catch (\Throwable $e) { Log::write('error', 'engine', 'challenge.roll', $e->getMessage()); }
        // debiuty giełdowe: nowa spółka co N sesji, aż rynek osiągnie cel
        try {
            if (!class_exists('Ipo')) require_once __DIR__ . '/Ipo.php';
            Ipo::onRoll($n, $tick);
        } catch (\Throwable $e) { Log::write('error', 'engine', 'ipo.roll', $e->getMessage()); }
        // rekomendacje DM na otwarcie sesji (premium widzi od razu, reszta od jutra)
        try {
            if (!class_exists('Recommendations')) require_once __DIR__ . '/Recommendations.php';
            if (!class_exists('Tokens')) require_once __DIR__ . '/Tokens.php';
            Recommendations::onRoll($n, $tick);
        } catch (\Throwable $e) { Log::write('error', 'engine', 'reco.roll', $e->getMessage()); }
        // okres próbny premium: aktywni gracze dostają raz darmowe dni pełnego premium
        try {
            if (!class_exists('Tokens')) require_once __DIR__ . '/Tokens.php';
            Tokens::grantTrials($n);
        } catch (\Throwable $e) { Log::write('error', 'engine', 'trial.roll', $e->getMessage()); }
        // lokaty: wypłata zapadłych (kapitał + odsetki ze skarbca)
        try {
            if (!class_exists('Bank')) require_once __DIR__ . '/Bank.php';
            Bank::onRoll($n);
        } catch (\Throwable $e) { Log::write('error', 'engine', 'bank.roll', $e->getMessage()); }
        self::setState('session_start_tick', (string) $tick);
        self::setState('session', (string) $n);
    }

    /** Cel gry: gdy kapitał gracza (equity) osiągnie JEGO próg — zapisz sesję sukcesu + komunikat.
     *  Próg = osobisty cel gracza (users.goal_target) albo domyślny z panelu GM. */
    private static function checkGoal(int $tick): void
    {
        $default = (float) (self::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
        [$session] = self::sessionInfo($tick);
        $players = self::all("SELECT id, username, cash, cash_reserved, goal_target FROM users WHERE is_bot=0 AND role='player' AND goal_session IS NULL");
        foreach ($players as $p) {
            $target = $p['goal_target'] !== null ? (float) $p['goal_target'] : $default;
            if ($target <= 0) continue;
            $stockVal = (float) (self::one(
                "SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0) FROM wallets w JOIN stocks s ON s.id = w.stock_id WHERE w.user_id = ?",
                [$p['id']]
            ) ?: 0);
            $equity = (float) $p['cash'] + (float) $p['cash_reserved'] + $stockVal + self::lockedFunds((int) $p['id']);
            if ($equity >= $target) {
                Db::pdo()->prepare("UPDATE users SET goal_session=? WHERE id=?")->execute([$session, $p['id']]);
                self::notify((int) $p['id'], 'goal', '🏆 Cel gry osiągnięty! Twój kapitał przekroczył ' . number_format($target, 0, ',', ' ') . ' PLN w sesji #' . $session . '.', 'portfolio.php');
                self::award((int) $p['id'], 'milioner');
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

        // wydarzenia: wygaś stare modyfikatory, odpal zaplanowane kaskady/plotki
        $pdo->prepare("DELETE FROM active_effects WHERE expire_tick <= ?")->execute([$t]);
        self::$modsTick = -1;
        self::fireScheduledEvents($t);
        $mods = self::activeMods($t);

        // dynamika ceny fundamentalnej — STEROWALNA i zależna od otoczenia:
        //   dryf = (trend rynku+wydarzenia)*beta + (trend sektora+wydarzenia)*beta + bias(GM)
        //   szum skalowany zmiennością spółki × sektora (± modyfikatory z wydarzeń)
        $market = (float) (self::one("SELECT v FROM game_state WHERE k='sentiment'") ?: 0)
                + self::modVal($mods, 'market', null, 'sentiment');
        $stocks = self::all("SELECT s.id, s.sector_id, s.fundamental, s.bias, s.beta, s.volatility, s.growth_potential,
                                    s.pe_target, s.last_eps,
                                    sec.trend AS sector_trend, sec.market_beta AS sector_beta,
                                    sec.volatility AS sector_vol, sec.growth AS sector_growth
                             FROM stocks s JOIN sectors sec ON sec.id = s.sector_id");
        foreach ($stocks as $st) {
            $sid = (int) $st['id']; $secId = (int) $st['sector_id'];
            // zmienność: DNA spółki ma już w sobie profil branży (seed), więc sektor wchodzi
            // tylko pierwiastkiem (bez podwójnego liczenia) + twardy sufit — koniec z mnożnikiem x6
            $vol = min(2.2, max(0.1, (float) $st['volatility'] + self::modVal($mods, 'stock', $sid, 'volatility'))
                 * sqrt(max(0.1, (float) $st['sector_vol'] + self::modVal($mods, 'sector', $secId, 'volatility')
                          + self::modVal($mods, 'market', null, 'volatility'))));
            // uwaga: growth idzie teraz kanałem raportów (zyski -> wycena), nie w ciągłym dryfie
            $drift = ( $market * (float) $st['beta']
                     + ((float) $st['sector_trend'] + self::modVal($mods, 'sector', $secId, 'trend')) * (float) $st['sector_beta']
                     + (float) $st['bias'] ) / 100.0;
            // kalibracja: szum tła niski — duże ruchy mają pochodzić z WYDARZEŃ;
            // szoki rzadsze/łagodniejsze z twardym capem +-6%
            $noise = (mt_rand(-3, 3) / 1000) * $vol;
            $f = (float) $st['fundamental'] * (1 + $drift + $noise);
            if (mt_rand(1, 100) <= 3) $f *= 1 + max(-0.04, min(0.04, (mt_rand(-25, 25) / 1000) * $vol));
            // kotwica wyceny: fundament delikatnie ciazy ku wartosci z zyskow (C/Z x EPS) —
            // tlumi wielogodzinne rozjazdy; wydarzenia (+-1-2%/tick) i tak dominuja krotkoterminowo
            $fair = (float) $st['pe_target'] * (float) $st['last_eps'];
            if ($fair > 1) $f += ($fair - $f) * 0.006;
            $pdo->prepare("UPDATE stocks SET fundamental=? WHERE id=?")->execute([max(1, round($f, 2)), $st['id']]);
        }
        self::generateReports($t);   // raporty miesięczne -> skok wartości fundamentalnej + news/ESPI
        self::generateNews($t);      // losowe ESPI/newsy pozytywne i negatywne
        self::maybeRandomEvent($t);  // rzadkie wydarzenia: krach / hossa / kryzys i boom sektorowy
        self::applyNewsImpact($t);   // aktywne newsy lekko ruszają fundamentem (z zanikiem)
        self::applyMarketMoves($t);  // GM „market maker": zaplanowany, stopniowy ruch popytu/podaży

        self::checkStops();
        self::runBots();
        self::arbitrage();   // boty domykają lukę kurs -> wartość fundamentalna (kurs podąża za sterowaniem)

        foreach (self::all("SELECT id FROM stocks") as $st) self::matchBook((int) $st['id']);
        try { self::checkHalts($t); } catch (\Throwable $e) { Log::write('warn', 'engine', 'halt.check', $e->getMessage()); }
        self::recordCandles($t);
        // cache sygnału AT per spółka (skaner na Rynku i rekomendacje czytają kolumnę)
        try {
            if (!class_exists('Technical')) require_once __DIR__ . '/Technical.php';
            $upTa = $pdo->prepare("UPDATE stocks SET ta_signal=? WHERE id=?");
            foreach (self::col("SELECT id FROM stocks") as $sidTa) $upTa->execute([Technical::composite((int) $sidTa), (int) $sidTa]);
        } catch (\Throwable $e) { Log::write('warn', 'engine', 'ta.cache', $e->getMessage()); }
        try { self::signalAlerts(); } catch (\Throwable $e) { Log::write('warn', 'engine', 'ta.alerts', $e->getMessage()); }
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

    /**
     * PULS HANDLU: dodatkowa runda botow MIEDZY tickami swiata (cron wola co ~20 s).
     * Nie rusza czasu gry (raporty/sesje/wydarzenia/indeks bez zmian) — tylko handel:
     * stopy, boty, arbitraz, kojarzenie. Obrot dopisuje do biezacej swiecy minutowej.
     */
    public static function subRound(): void
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $t = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
            if ($t <= 0) { $pdo->rollBack(); return; }
            self::checkStops();
            self::runBots();
            self::arbitrage();
            foreach (self::all("SELECT id FROM stocks") as $st) self::matchBook((int) $st['id']);
            // scal z bieżącą świecą REALNE transakcje od kursora (handel botów tej podrundy + zlecenia graczy między nimi)
            [$trades, $maxTx] = self::tradesSinceCandle();
            foreach ($trades as $sid => $tr) {
                $c = self::row("SELECT h, l FROM candles WHERE stock_id=? AND t=?", [$sid, $t]);
                if (!$c) continue;
                $ps = array_column($tr, 'p');
                $cur = (float) self::one("SELECT price FROM stocks WHERE id=?", [$sid]);
                $pdo->prepare("UPDATE candles SET h=?, l=?, c=?, v=v+? WHERE stock_id=? AND t=?")
                    ->execute([max((float) $c['h'], max($ps), $cur), min((float) $c['l'], min($ps), $cur), $cur, array_sum(array_column($tr, 'q')), $sid, $t]);
            }
            self::setState('last_candle_tx', (string) $maxTx);   // kursor = granica użyta wyżej
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /* ---------- Sygnaly ---------- */

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

    /* ---------- Wydarzenia świata (katalog w EventCatalog, skutki 3-warstwowe) ---------- */

    /**
     * Wyzwól wydarzenie z katalogu (GM / losowe / kaskada). Tworzy news, nakłada
     * modyfikatory czasowe, planuje kaskady i rozstrzygnięcia plotek, powiadamia
     * zainteresowanych graczy. Zwraca nagłówek.
     */
    public static function triggerEvent(string $code, ?int $sectorId = null, ?int $stockId = null, string $source = 'gm'): string
    {
        $ev = EventCatalog::get($code);
        if (!$ev) return '';
        $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        $rand = Db::driver() === 'mysql' ? 'RAND()' : 'RANDOM()';

        // cel: sektor / spółka (losowany, gdy nie wskazano; spółki ważone news_frequency)
        $label = ''; $newsScope = $ev['scope']; $newsTarget = null;
        if ($ev['scope'] === 'SECTOR') {
            if (!empty($ev['fixed_sector'])) $sectorId = (int) (self::one("SELECT id FROM sectors WHERE symbol=?", [$ev['fixed_sector']]) ?: 0);
            if (!$sectorId) $sectorId = (int) self::one("SELECT id FROM sectors ORDER BY $rand LIMIT 1");
            $label = (string) self::one("SELECT name FROM sectors WHERE id=?", [$sectorId]);
            $newsTarget = $sectorId;
        } elseif ($ev['scope'] === 'COMPANY') {
            if (!$stockId) {
                $rows = self::all("SELECT id, news_frequency FROM stocks");
                $tot = 0; foreach ($rows as $r) $tot += max(1, (int) round((float) $r['news_frequency'] * 10));
                $pick = mt_rand(1, max(1, $tot)); $acc = 0;
                foreach ($rows as $r) { $acc += max(1, (int) round((float) $r['news_frequency'] * 10)); if ($pick <= $acc) { $stockId = (int) $r['id']; break; } }
            }
            $st = self::row("SELECT ticker, sector_id FROM stocks WHERE id=?", [$stockId]);
            $label = (string) ($st['ticker'] ?? ''); $sectorId = (int) ($st['sector_id'] ?? 0) ?: $sectorId;
            $newsTarget = $stockId;
        }

        // realizm: LOSOWE wydarzenia respektują cooldowny historii (odstęp na celu +
        // blokada powtórki/sprzeczności tego samego tematu); GM, kaskady i
        // rozstrzygnięcia plotek przechodzą zawsze — to kontynuacje, nie nowe historie
        if (!class_exists('Newsroom')) require_once __DIR__ . '/Newsroom.php';
        $topic = (string) ($ev['topic'] ?? 'inne');
        if ($source === 'los' && !Newsroom::storyAllowed($newsScope, $newsTarget, $topic, $tick)) return '';

        $head = str_replace('[T]', $label, $ev['head']);
        $body = str_replace('[T]', $label, $ev['body']);
        Db::pdo()->prepare("INSERT INTO news (template_id,headline,body,type,kind,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                            VALUES (?,?,?,?,?,?,?,0,?,?,?,?)")
            ->execute([Newsroom::topicHash($topic), $head, $body, $ev['type'], $ev['kind'] ?? 'fundamental', $newsScope, $newsTarget, $ev['impact'], $tick, $tick + $ev['duration'], Db::now()]);

        // modyfikatory czasowe (nakładki na bazę — same wygasają)
        foreach ($ev['effects'] ?? [] as [$target, $field, $delta, $dur]) {
            $tt = 'market'; $tid = null;
            if ($target === 'market' || $target === 'all_stocks') { $tt = 'market'; }
            elseif ($target === 'sector')                          { $tt = 'sector'; $tid = $sectorId; }
            elseif (str_starts_with($target, 'sector:'))           { $tt = 'sector'; $tid = (int) (self::one("SELECT id FROM sectors WHERE symbol=?", [substr($target, 7)]) ?: 0); }
            elseif ($target === 'stock')                           { $tt = 'stock';  $tid = $stockId; }
            if (($tt !== 'market' && !$tid)) continue;
            Db::pdo()->prepare("INSERT INTO active_effects (target_type, target_id, field, delta, expire_tick, source) VALUES (?,?,?,?,?,?)")
                ->execute([$tt, $tid, $field, $delta, $tick + (int) $dur, $code]);
        }
        self::$modsTick = -1;   // unieważnij cache modyfikatorów

        // kaskady (niezależne rzuty) — rzut od razu, odpalenie później
        foreach ($ev['follow_ups'] ?? [] as [$fcode, $chance, $dMin, $dMax]) {
            if (mt_rand(1, 100) > $chance) continue;
            Db::pdo()->prepare("INSERT INTO scheduled_events (due_tick, template_code, sector_id, stock_id) VALUES (?,?,?,?)")
                ->execute([$tick + mt_rand($dMin, $dMax), $fcode, $sectorId, null]);   // kaskada dziedziczy sektor
        }
        // rozstrzygnięcie plotki (wykluczające) — losujemy dopiero w dniu rozstrzygnięcia
        if (!empty($ev['resolve'])) {
            [$dMin, $dMax] = $ev['resolve_delay'] ?? [15, 30];
            Db::pdo()->prepare("INSERT INTO scheduled_events (due_tick, template_code, sector_id, stock_id, resolve_json) VALUES (?,?,?,?,?)")
                ->execute([$tick + mt_rand($dMin, $dMax), $code . '.resolve', $sectorId, $stockId, json_encode($ev['resolve'])]);
        }

        if ($ev['scope'] === 'MARKET') self::setState('last_event_tick', (string) $tick);   // cooldown tylko dla dużych
        Log::write('info', 'engine', "event.$code", $head . " (źródło: $source, wpływ {$ev['impact']}%/tick przez {$ev['duration']} t.)",
            ['sector_id' => $sectorId, 'stock_id' => $stockId]);
        self::notifyEventAudience($ev, $head, $sectorId, $stockId);
        return $head;
    }

    /** Powiadomienia celowane: rynek = wszyscy; sektor/spółka = tylko posiadacze akcji. */
    private static function notifyEventAudience(array $ev, string $head, ?int $sectorId, ?int $stockId): void
    {
        $prefix = $ev['type'] === 'NEG' ? '🚨 ' : '🚀 ';
        if ($ev['scope'] === 'MARKET') {
            foreach (self::humanIds() as $uid) self::notify($uid, 'event', $prefix . $head, 'wiadomosci.php');
        } elseif ($ev['scope'] === 'SECTOR' && $sectorId) {
            $uids = self::col("SELECT DISTINCT w.user_id FROM wallets w JOIN stocks s ON s.id=w.stock_id JOIN users u ON u.id=w.user_id
                               WHERE s.sector_id=? AND u.is_bot=0 AND (w.qty + w.qty_reserved) > 0", [$sectorId]);
            foreach ($uids as $uid) self::notify((int) $uid, 'event', $prefix . $head . ' (masz akcje z tej branży)', 'wiadomosci.php');
        } elseif ($ev['scope'] === 'COMPANY' && $stockId) {
            $uids = self::col("SELECT w.user_id FROM wallets w JOIN users u ON u.id=w.user_id
                               WHERE w.stock_id=? AND u.is_bot=0 AND (w.qty + w.qty_reserved) > 0", [$stockId]);
            foreach ($uids as $uid) self::notify((int) $uid, 'event', $prefix . $head . ' (masz te akcje!)', 'stock.php?id=' . $stockId);
        }
    }

    /** Odpal zaplanowane kaskady/rozstrzygnięcia, których czas nadszedł. */
    private static function fireScheduledEvents(int $tick): void
    {
        foreach (self::all("SELECT * FROM scheduled_events WHERE fired=0 AND due_tick <= ?", [$tick]) as $se) {
            Db::pdo()->prepare("UPDATE scheduled_events SET fired=1 WHERE id=?")->execute([$se['id']]);
            if ($se['resolve_json']) {   // plotka: wykluczający rzut w dniu rozstrzygnięcia
                $alts = json_decode((string) $se['resolve_json'], true) ?: [];
                $r = mt_rand(1, 100); $acc = 0;
                foreach ($alts as [$chance, $code]) {
                    $acc += (int) $chance;
                    if ($r <= $acc) { self::triggerEvent($code, $se['sector_id'] ? (int) $se['sector_id'] : null, $se['stock_id'] ? (int) $se['stock_id'] : null, 'plotka'); break; }
                }
            } else {
                self::triggerEvent((string) $se['template_code'], $se['sector_id'] ? (int) $se['sector_id'] : null, $se['stock_id'] ? (int) $se['stock_id'] : null, 'kaskada');
            }
        }
    }

    /* ---------- Modyfikatory czasowe (nakładki z wydarzeń) ---------- */

    private static int $modsTick = -1;
    private static array $mods = [];

    /** Aktywne modyfikatory pogrupowane: ['market'=>[pole=>Σ], 'sector'=>[id=>[pole=>Σ]], 'stock'=>[id=>[pole=>Σ]]]. */
    public static function activeMods(int $tick): array
    {
        if (self::$modsTick === $tick) return self::$mods;
        $m = ['market' => [], 'sector' => [], 'stock' => []];
        try {
            foreach (self::all("SELECT target_type, target_id, field, SUM(delta) d FROM active_effects WHERE expire_tick > ? GROUP BY target_type, target_id, field", [$tick]) as $r) {
                if ($r['target_type'] === 'market') $m['market'][$r['field']] = (float) $r['d'];
                else $m[$r['target_type']][(int) $r['target_id']][$r['field']] = (float) $r['d'];
            }
        } catch (\Throwable $e) { /* tabela może jeszcze nie istnieć w trakcie migracji */ }
        self::$modsTick = $tick; self::$mods = $m;
        return $m;
    }

    private static function modVal(array $m, string $type, ?int $id, string $field): float
    {
        return $type === 'market' ? (float) ($m['market'][$field] ?? 0) : (float) ($m[$type][$id][$field] ?? 0);
    }

    /** Losowe wydarzenia: duże rynkowe (rzadkie, cooldown) + mniejsze sektorowe/spółkowe (częstsze). */
    private static function maybeRandomEvent(int $tick): void
    {
        $on = self::one("SELECT v FROM game_state WHERE k='events_enabled'");
        if ($on !== false && $on !== null && (int) $on !== 1) return;   // brak klucza = włączone

        // duże wydarzenia rynkowe
        $chance = max(50, (int) (self::one("SELECT v FROM game_state WHERE k='event_chance'") ?: 900));
        $cooldown = (int) (self::one("SELECT v FROM game_state WHERE k='event_cooldown'") ?: 600);
        $last = (int) (self::one("SELECT v FROM game_state WHERE k='last_event_tick'") ?: -100000);
        if (mt_rand(1, $chance) === 1 && $tick - $last >= $cooldown) {
            $code = EventCatalog::pickRandom('MARKET');
            if ($code) { self::triggerEvent($code, null, null, 'los'); return; }
        }
        // mniejsze wydarzenia (sektor/spółka) — częstsze, własny krótszy cooldown
        $mChance = max(20, (int) (self::one("SELECT v FROM game_state WHERE k='minor_event_chance'") ?: 70));
        $mCooldown = (int) (self::one("SELECT v FROM game_state WHERE k='minor_event_cooldown'") ?: 20);
        $mLast = (int) (self::one("SELECT v FROM game_state WHERE k='last_minor_event_tick'") ?: -100000);
        if (mt_rand(1, $mChance) === 1 && $tick - $mLast >= $mCooldown) {
            $code = EventCatalog::pickRandom(mt_rand(1, 100) <= 65 ? 'COMPANY' : 'SECTOR');
            if ($code) { self::triggerEvent($code, null, null, 'los'); self::setState('last_minor_event_tick', (string) $tick); }
        }
    }

    /* ---------- Osiągnięcia (odznaki) ---------- */

    private static array $achCache = [];   // uid => set zdobytych kodów (na czas żądania)

    private static function hasAch(int $userId, string $code): bool
    {
        if (!isset(self::$achCache[$userId])) {
            self::$achCache[$userId] = array_fill_keys(self::col("SELECT code FROM achievements WHERE user_id=?", [$userId]), true);
        }
        return isset(self::$achCache[$userId][$code]);
    }

    /** Przyznaj odznakę (raz na gracza). Zwraca true, gdy przyznano po raz pierwszy. */
    public static function award(int $userId, string $code): bool
    {
        $userId = self::challengeOwner($userId);   // odznaki z handlu w wyzwaniu liczą się właścicielowi
        $a = Achievements::get($code);
        if (!$a || self::hasAch($userId, $code)) return false;
        try {
            Db::pdo()->prepare("INSERT INTO achievements (user_id, code, earned_at) VALUES (?,?,?)")
                ->execute([$userId, $code, Db::now()]);
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') Log::write('warn', 'engine', 'achievement.fail', $code . ': ' . $e->getMessage());
            return false;   // duplikat/wyścig — już ma
        }
        self::$achCache[$userId][$code] = true;
        self::notify($userId, 'achievement', "🎖️ Nowa odznaka: {$a[0]} {$a[1]} — {$a[2]}", 'gracz.php?id=' . $userId);
        Log::write('info', 'engine', 'achievement', "odznaka $code dla gracza #$userId");
        try {
            if (!class_exists('Tokens')) require_once __DIR__ . '/Tokens.php';
            Tokens::grant($userId, 2, 'achievement', 'odznaka: ' . $a[1]);
        } catch (\Throwable $e) { /* tokeny nie psują odznak */ }
        return true;
    }

    /** Odznaki transakcyjne — RAZ po rundzie kojarzenia (nie per fill), po indeksach. */
    public static function checkTradeAchievements(int $uid): void
    {
        try {
            self::award($uid, 'pierwsza_transakcja');
            if (!self::hasAch($uid, 'trader_100')) {
                $n = (int) self::one("SELECT COUNT(*) FROM transactions WHERE buyer_id=?", [$uid])
                   + (int) self::one("SELECT COUNT(*) FROM transactions WHERE seller_id=?", [$uid]);
                if ($n >= 100) self::award($uid, 'trader_100');
            }
            // day trader: sesja trwa (tick - start_sesji) ticków ≈ tyle minut zegarowych (cron 1/min)
            [$sess, , $tps] = self::sessionInfo();
            $tk = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
            $sst = (int) (self::one("SELECT v FROM game_state WHERE k='session_start_tick'") ?: ($sess - 1) * $tps);
            $into = min($tps, max(1, $tk - $sst));
            $cutoff = date('Y-m-d H:i:s', time() - $into * 60);
            $inSess = (int) self::one("SELECT COUNT(*) FROM transactions WHERE (buyer_id=? OR seller_id=?) AND created_at >= ?", [$uid, $uid, $cutoff]);
            if ($inSess >= 20) self::award($uid, 'day_trader');
            $div = (int) self::one("SELECT COUNT(DISTINCT stock_id) FROM wallets WHERE user_id=? AND (qty + qty_reserved) > 0", [$uid]);
            if ($div >= 10) self::award($uid, 'dywersyfikacja');
        } catch (\Throwable $e) { /* odznaki nie mogą psuć handlu */ }
    }

    /* ---------- Powiadomienia (dzwonek gracza) ---------- */

    private static ?array $humanIds = null;

    /**
     * Alerty sygnałów AT (funkcja Pakietu Analityka): gdy obserwowana spółka
     * wejdzie w MOCNY sygnał (|ta_signal| >= 0.35, jak Technical::verdict),
     * posiadacz pakietu dostaje 🔔. Anty-spam: alert dopiero przy ZMIANIE stanu
     * i najwyżej raz na spółkę na sesję.
     */
    public static function signalAlerts(): void
    {
        [$sess] = self::sessionInfo();
        $rows = self::all(
            "SELECT w.id, w.user_id, w.stock_id, w.alert_state, w.alert_session,
                    s.ticker, s.ta_signal
             FROM watchlist w
             JOIN stocks s ON s.id = w.stock_id
             JOIN premium_passes p ON p.user_id = w.user_id AND p.kind='analityk' AND p.until_session >= ?", [$sess]);
        if (!$rows) return;
        $upd = Db::pdo()->prepare("UPDATE watchlist SET alert_state=?, alert_session=? WHERE id=?");
        foreach ($rows as $w) {
            $ta = (float) $w['ta_signal'];
            $state = $ta >= 0.35 ? 'kupuj' : ($ta <= -0.35 ? 'sprzedaj' : '');
            if ($state === (string) $w['alert_state']) continue;
            $fire = $state !== '' && (int) $w['alert_session'] < $sess;
            $upd->execute([$state, $fire ? $sess : (int) $w['alert_session'], $w['id']]);
            if ($fire) {
                self::notify((int) $w['user_id'], 'signal',
                    '🔔 Alert AT: ' . $w['ticker'] . ' — mocny sygnał ' . strtoupper($state)
                    . ' (' . ($ta >= 0 ? '+' : '') . number_format($ta, 2, ',', ' ') . '). Obserwowana spółka.',
                    'stock.php?id=' . (int) $w['stock_id']);
            }
        }
    }

    /** Id ludzkich kont (gracze/admin/qa) — memo na czas żądania/ticka. */
    public static function humanIds(): array
    {
        if (self::$humanIds === null) {
            self::$humanIds = array_map('intval', self::col("SELECT id FROM users WHERE is_bot = 0"));
        }
        return self::$humanIds;
    }

    /** Konto-cień wyzwania -> id właściciela; zwykłe konta bez zmian. */
    public static function challengeOwner(int $userId): int
    {
        $role = self::one("SELECT role FROM users WHERE id=?", [$userId]);
        if ($role !== 'challenger') return $userId;
        $owner = (int) (self::one("SELECT user_id FROM challenge_players WHERE shadow_user_id=?", [$userId]) ?: 0);
        return $owner > 0 ? $owner : $userId;
    }

    /** Dodaj powiadomienie dla gracza (+ przytnij do ~50 najnowszych na gracza). Zapisuje też do dziennika. */
    public static function notify(int $userId, string $type, string $message, string $link = ''): void
    {
        try {
            $owner = self::challengeOwner($userId);
            if ($owner !== $userId) { $message = '⚔️ [wyzwanie] ' . $message; $userId = $owner; }
            $pdo = Db::pdo();
            $pdo->prepare("INSERT INTO notifications (user_id, type, message, link, created_at) VALUES (?,?,?,?,?)")
                ->execute([$userId, $type, mb_substr($message, 0, 250), $link ?: null, Db::now()]);
            $edge = self::one("SELECT id FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1 OFFSET 50", [$userId]);
            if ($edge) $pdo->prepare("DELETE FROM notifications WHERE user_id=? AND id <= ?")->execute([$userId, $edge]);
            self::journal($userId, $type, $message, $link);   // trwały ślad w dzienniku gracza
        } catch (\Throwable $e) { /* powiadomienia nie mogą wywalić silnika */ }
    }

    /**
     * Dziennik gracza: trwała oś czasu konta ("co się stało i kiedy").
     * Wpisy z powiadomień trafiają tu automatycznie; akcje własne gracza
     * (złożenie/anulowanie zlecenia, SL/TP) dopisują strony bezpośrednio.
     */
    public static function journal(int $userId, string $type, string $message, string $link = ''): void
    {
        try {
            $owner = self::challengeOwner($userId);
            if ($owner !== $userId) { $message = str_starts_with($message, '⚔️') ? $message : '⚔️ [wyzwanie] ' . $message; $userId = $owner; }
            $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
            Db::pdo()->prepare("INSERT INTO player_journal (user_id, ts, tick, type, message, link) VALUES (?,?,?,?,?,?)")
                ->execute([$userId, Db::now(), $tick, mb_substr($type, 0, 24), mb_substr($message, 0, 300), $link !== '' ? mb_substr($link, 0, 120) : null]);
            if (mt_rand(1, 50) === 1) {   // retencja: ~1000 najnowszych wpisów na gracza
                $edge = self::one("SELECT id FROM player_journal WHERE user_id=? ORDER BY id DESC LIMIT 1 OFFSET 1000", [$userId]);
                if ($edge) Db::pdo()->prepare("DELETE FROM player_journal WHERE user_id=? AND id <= ?")->execute([$userId, $edge]);
            }
        } catch (\Throwable $e) { /* dziennik nie może wywalić silnika */ }
    }

    /**
     * Księga gotówki: zapisuje przepływ, którego NIE da się odtworzyć z innych tabel
     * (np. dywidenda). Kupno/sprzedaż, IPO i lokaty historia.php czyta wprost z
     * transactions/ipo_subs/deposits — ich tu NIE dublujemy. amount>0 = wpływ, <0 = wypływ.
     * Tylko realne konta graczy (nie boty); subkonto wyzwania księgujemy na koncie głównym.
     * Nigdy nie może wywalić silnika.
     */
    public static function ledger(int $userId, float $amount, string $category, string $note = '', string $link = ''): void
    {
        try {
            $u = self::row("SELECT is_bot FROM users WHERE id=?", [$userId]);
            if (!$u || (int) $u['is_bot'] === 1) return;
            $owner = self::challengeOwner($userId);
            if ($owner !== $userId) $userId = $owner;
            [$session] = self::sessionInfo();
            $tick = (int) (self::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
            Db::pdo()->prepare("INSERT INTO cash_ledger (user_id, ts, session, tick, amount, category, note, link) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$userId, Db::now(), (int) $session, $tick, round($amount, 2), mb_substr($category, 0, 24), mb_substr($note, 0, 200), $link !== '' ? mb_substr($link, 0, 120) : null]);
        } catch (\Throwable $e) { /* księga nie może wywalić silnika */ }
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
