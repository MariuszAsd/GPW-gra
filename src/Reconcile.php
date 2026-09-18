<?php
/**
 * Rekoncyliacja rezerwacji — narzędzie GM do sprzątania „blizn" po dawnych wyścigach silnika.
 *
 * Od lipcowych poprawek (claim-first, place() w transakcji, osłony botów) silnik nie produkuje nowych
 * rozjazdów, ale rekordy sprzed poprawek zostały na produkcji: zamrożona gotówka bez zlecenia, ujemna
 * rezerwacja po podwójnym zwrocie, boty z ujemną ilością akcji po podwójnej rezerwacji. QA zgłasza je
 * w każdym przebiegu jako te same 3 asercje.
 *
 * To narzędzie NIGDY nie uruchamia się samo: tylko z przycisku w panelu GM, po podglądzie różnic,
 * pod blokadą świata (żeby nie ścigać się z tickiem) i z wpisem w dzienniku dla każdego wiersza.
 *
 * Zasada korekty: SUMA per gracz zostaje. Gotówka + zamrożona = bez zmian, akcje + zarezerwowane = bez zmian.
 * Zmienia się tylko podział na „wolne" i „zamrożone", tak żeby rezerwacje równały się aktywnym zleceniom
 * (dokładnie tak, jak liczy je QA). Pieniądz w świecie się nie zmienia. Jedyny wyjątek: ujemna SUMA akcji
 * (bot sprzedał więcej niż miał) — tam ilość idzie na zero, a dziennik zapisuje, ile akcji „dotworzono".
 * Ujemnej SUMY gotówki nie ruszamy (wymagałoby stworzenia pieniądza) — wiersz zostaje do ręcznej decyzji.
 */
final class Reconcile
{
    /** Rozjazdy gotówki: zamrożone ≠ Σ zleceń kupna (aktywne + stop-buy), albo ujemne saldo. */
    public static function cashIssues(): array
    {
        // kupna aktywne + stop-buy czekające na przebicie (pending) — oba trzymają rezerwację ilość × cena
        $should = "COALESCE((SELECT SUM(o.qty*o.price) FROM orders o WHERE o.user_id=u.id AND o.side='buy' AND o.status IN ('active','pending')),0)";
        $rows = Engine::all("SELECT u.id, u.username, u.is_bot, u.cash, u.cash_reserved, $should AS should_be
                             FROM users u WHERE ABS(u.cash_reserved - $should) > 0.005 OR u.cash < -0.005
                             ORDER BY u.is_bot, u.id");
        foreach ($rows as &$r) $r['plan'] = self::planCash($r);
        return $rows;
    }

    /** Rozjazdy akcji: zarezerwowane ≠ Σ aktywnych zleceń sprzedaży + obronnych, albo ujemne ilości. */
    public static function qtyIssues(): array
    {
        $should = "COALESCE((SELECT SUM(o.qty) FROM orders o WHERE o.user_id=w.user_id AND o.stock_id=w.stock_id AND o.side='sell' AND o.status IN ('active','pending')),0)";
        $rows = Engine::all("SELECT w.user_id, u.username, u.is_bot, w.stock_id, s.ticker, w.qty, w.qty_reserved, $should AS should_be
                             FROM wallets w JOIN users u ON u.id=w.user_id JOIN stocks s ON s.id=w.stock_id
                             WHERE w.qty < 0 OR w.qty_reserved < 0 OR w.qty_reserved <> $should
                             ORDER BY u.is_bot, w.user_id, w.stock_id");
        foreach ($rows as &$r) $r['plan'] = self::planQty($r);
        return $rows;
    }

    /**
     * Plan dla wiersza gotówki: [nowa_gotówka, nowa_rezerwacja, uwaga, do_anulowania_zleceń].
     * Gdy zleceń kupna jest na więcej niż gracz ma łącznie, samo przesunięcie dałoby ujemną gotówkę —
     * wtedy anulujemy najnowsze zlecenia kupna, aż reszta mieści się w sumie.
     */
    public static function planCash(array $r): array
    {
        $total = round((float) $r['cash'] + (float) $r['cash_reserved'], 2);
        $need  = round((float) $r['should_be'], 2);
        if ($total < -0.005) return [(float) $r['cash'], (float) $r['cash_reserved'], 'ujemna suma — pominięte, wymaga ręcznej decyzji', 0];
        if ($need > $total + 0.005) {
            $keep = 0.0; $cancel = 0;
            foreach (Engine::all("SELECT id, qty, price FROM orders WHERE user_id=? AND side='buy' AND status IN ('active','pending') ORDER BY id ASC", [(int) $r['id']]) as $o) {
                $v = round((float) $o['qty'] * (float) $o['price'], 2);
                if ($keep + $v <= $total + 0.005) $keep = round($keep + $v, 2); else $cancel++;
            }
            return [round($total - $keep, 2), $keep, "anuluje $cancel zleceń kupna (brak pokrycia)", $cancel];
        }
        return [round($total - $need, 2), $need, '', 0];
    }

    /** Plan dla wiersza akcji: [nowe_qty, nowa_rezerwacja, uwaga, do_anulowania_zleceń, dotworzone_akcje]. */
    public static function planQty(array $r): array
    {
        $total = (int) $r['qty'] + (int) $r['qty_reserved'];
        $need  = (int) $r['should_be'];
        $made  = $total < 0 ? -$total : 0;
        $total = max(0, $total);
        if ($need > $total) {
            // zleceń sprzedaży na więcej akcji niż łącznie posiada: zwykłe zlecenia od najnowszych do anulowania,
            // obronne (SL/TP) na końcu — gracz woli stracić świeże zlecenie niż osłonę pozycji
            $keep = 0; $cancel = 0;
            foreach (Engine::all("SELECT id, qty FROM orders WHERE user_id=? AND stock_id=? AND side='sell' AND status IN ('active','pending')
                                  ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END, id ASC", [(int) $r['user_id'], (int) $r['stock_id']]) as $o) {
                if ($keep + (int) $o['qty'] <= $total) $keep += (int) $o['qty']; else $cancel++;
            }
            $note = "anuluje $cancel zleceń sprzedaży (brak pokrycia)" . ($made ? ", dotwarza $made szt." : '');
            return [$total - $keep, $keep, $note, $cancel, $made];
        }
        return [$total - $need, $need, $made ? "dotwarza $made szt. (ujemna suma)" : '', 0, $made];
    }

    /**
     * Wykonaj korekty. Wołający trzyma blokadę świata (Engine::worldLock), my trzymamy transakcję.
     * Zwraca podsumowanie do komunikatu dla GM. Każdy zmieniony wiersz ma osobny wpis w dzienniku.
     */
    public static function apply(int $adminId): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            $moneyBefore = (float) Engine::one("SELECT COALESCE(SUM(cash),0)+COALESCE(SUM(cash_reserved),0) FROM users");
            $updUser   = $pdo->prepare("UPDATE users SET cash=?, cash_reserved=? WHERE id=?");
            $updWallet = $pdo->prepare("UPDATE wallets SET qty=?, qty_reserved=? WHERE user_id=? AND stock_id=?");
            $cancel    = $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status IN ('active','pending')");
            $nCash = 0; $nQty = 0; $nCancel = 0; $made = 0; $skipped = 0;

            foreach (self::cashIssues() as $r) {
                [$cash, $res, $note, $toCancel] = $r['plan'];
                if ($toCancel === 0 && $note !== '') { $skipped++; continue; }   // ujemna suma — nie ruszamy
                if ($toCancel > 0) {
                    // od najnowszych: tyle, ile nie mieści się w sumie (plan liczył od najstarszych, które zostają)
                    $keep = 0.0; $total = round((float) $r['cash'] + (float) $r['cash_reserved'], 2);
                    foreach (Engine::all("SELECT id, qty, price FROM orders WHERE user_id=? AND side='buy' AND status IN ('active','pending') ORDER BY id ASC", [(int) $r['id']]) as $o) {
                        $v = round((float) $o['qty'] * (float) $o['price'], 2);
                        if ($keep + $v <= $total + 0.005) { $keep = round($keep + $v, 2); continue; }
                        $cancel->execute([(int) $o['id']]); $nCancel += $cancel->rowCount();
                        if ((int) $r['is_bot'] === 0) Engine::notify((int) $r['id'], 'order', "Zlecenie kupna #{$o['id']} anulowane przez administrację (porządkowanie rezerwacji).", 'portfolio.php');
                    }
                }
                $updUser->execute([$cash, $res, (int) $r['id']]);
                Log::write('warn', 'gm', 'reconcile.cash', "korekta gotówki {$r['username']}: wolne {$r['cash']} → $cash, zamrożone {$r['cash_reserved']} → $res" . ($note !== '' ? " ($note)" : ''),
                    ['user_id' => (int) $r['id'], 'admin' => $adminId, 'should_be' => (float) $r['should_be']]);
                $nCash++;
            }

            foreach (self::qtyIssues() as $r) {
                [$qty, $res, $note, $toCancel, $created] = $r['plan'];
                if ($toCancel > 0) {
                    $keep = 0; $total = max(0, (int) $r['qty'] + (int) $r['qty_reserved']);
                    foreach (Engine::all("SELECT id, qty FROM orders WHERE user_id=? AND stock_id=? AND side='sell' AND status IN ('active','pending')
                                          ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END, id ASC", [(int) $r['user_id'], (int) $r['stock_id']]) as $o) {
                        if ($keep + (int) $o['qty'] <= $total) { $keep += (int) $o['qty']; continue; }
                        $cancel->execute([(int) $o['id']]); $nCancel += $cancel->rowCount();
                        if ((int) $r['is_bot'] === 0) Engine::notify((int) $r['user_id'], 'order', "Zlecenie sprzedaży #{$o['id']} ({$r['ticker']}) anulowane przez administrację (porządkowanie rezerwacji).", 'portfolio.php');
                    }
                }
                $updWallet->execute([$qty, $res, (int) $r['user_id'], (int) $r['stock_id']]);
                $made += $created;
                Log::write('warn', 'gm', 'reconcile.qty', "korekta akcji {$r['username']} / {$r['ticker']}: wolne {$r['qty']} → $qty, zarezerwowane {$r['qty_reserved']} → $res" . ($note !== '' ? " ($note)" : ''),
                    ['user_id' => (int) $r['user_id'], 'stock_id' => (int) $r['stock_id'], 'admin' => $adminId, 'should_be' => (int) $r['should_be']]);
                $nQty++;
            }

            $moneyAfter = (float) Engine::one("SELECT COALESCE(SUM(cash),0)+COALESCE(SUM(cash_reserved),0) FROM users");
            if (abs($moneyAfter - $moneyBefore) > 0.05) {
                // nie ma prawa się zdarzyć (suma per gracz zostaje) — jeśli jednak, cofamy wszystko
                throw new RuntimeException(sprintf('pieniądz w świecie zmieniłby się o %.2f PLN — korekta wycofana', $moneyAfter - $moneyBefore));
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            Log::write('error', 'gm', 'reconcile.fail', $e->getMessage(), ['admin' => $adminId]);
            throw $e;
        }
        $summary = ['cash' => $nCash, 'qty' => $nQty, 'cancelled' => $nCancel, 'created' => $made, 'skipped' => $skipped];
        Log::write('warn', 'gm', 'reconcile.done', "rekoncyliacja: gotówka $nCash, akcje $nQty, anulowane zlecenia $nCancel, dotworzone akcje $made, pominięte $skipped", ['admin' => $adminId]);
        return $summary;
    }
}
