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
 *
 * ZAPIS JEST PRZYROSTOWY, nie bezwzględny. Blokada świata zatrzymuje tick i panel GM, ale NIE żądania HTTP
 * graczy: Engine::place/marketOrder biegną w swoich krótkich transakcjach równolegle z rekoncyliacją. Gdyby
 * korekta wpisywała policzone wcześniej liczby, zlecenie złożone w oknie między odczytem planu a zapisem
 * traciłoby pokrycie (aktywne zlecenie bez rezerwacji = nowa blizna, a przy realizacji akcje bez zapłaty).
 * Plan daje więc RÓŻNICĘ (Σ zleceń − zamrożone, liczone z jednego zrzutu) i zapis to „cash = cash − d,
 * cash_reserved = cash_reserved + d”. Różnica komutuje z każdą równoległą operacją, która sama trzyma
 * niezmiennik (zlecenie, realizacja, lokata, fundusz), więc stan końcowy jest poprawny niezależnie od ruchu.
 * Z tego samego powodu zlecenia do anulowania wybiera się RAZ, w planie (po id) — zlecenie złożone w oknie
 * nie jest w zrzucie i rekoncyliacja go nie dotyka.
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
     * Plan dla wiersza gotówki: [nowa_gotówka, nowa_rezerwacja, uwaga, do_anulowania_zleceń, ids_do_anulowania].
     * Dwie pierwsze wartości to PODGLĄD dla GM (jak wyglądałoby konto, gdyby nic się nie ruszało);
     * apply() zapisuje różnicę nowa_rezerwacja − zamrożone, nie te liczby.
     * Gdy zleceń kupna jest na więcej niż gracz ma łącznie, samo przesunięcie dałoby ujemną gotówkę —
     * wtedy anulujemy najnowsze zlecenia kupna, aż reszta mieści się w sumie.
     */
    public static function planCash(array $r): array
    {
        $total = round((float) $r['cash'] + (float) $r['cash_reserved'], 2);
        $need  = round((float) $r['should_be'], 2);
        if ($total < -0.005) return [(float) $r['cash'], (float) $r['cash_reserved'], 'ujemna suma — pominięte, wymaga ręcznej decyzji', 0, []];
        if ($need > $total + 0.005) {
            $keep = 0.0; $ids = [];
            foreach (Engine::all("SELECT id, qty, price FROM orders WHERE user_id=? AND side='buy' AND status IN ('active','pending') ORDER BY id ASC", [(int) $r['id']]) as $o) {
                $v = round((float) $o['qty'] * (float) $o['price'], 2);
                if ($keep + $v <= $total + 0.005) $keep = round($keep + $v, 2); else $ids[] = (int) $o['id'];
            }
            return [round($total - $keep, 2), $keep, 'anuluje ' . count($ids) . ' zleceń kupna (brak pokrycia)', count($ids), $ids];
        }
        return [round($total - $need, 2), $need, '', 0, []];
    }

    /** Plan dla wiersza akcji: [nowe_qty, nowa_rezerwacja, uwaga, do_anulowania_zleceń, dotworzone_akcje, ids_do_anulowania]. */
    public static function planQty(array $r): array
    {
        $total = (int) $r['qty'] + (int) $r['qty_reserved'];
        $need  = (int) $r['should_be'];
        $made  = $total < 0 ? -$total : 0;
        $total = max(0, $total);
        if ($need > $total) {
            // zleceń sprzedaży na więcej akcji niż łącznie posiada: zwykłe zlecenia od najnowszych do anulowania,
            // obronne (SL/TP) na końcu — gracz woli stracić świeże zlecenie niż osłonę pozycji
            $keep = 0; $ids = [];
            foreach (Engine::all("SELECT id, qty FROM orders WHERE user_id=? AND stock_id=? AND side='sell' AND status IN ('active','pending')
                                  ORDER BY CASE WHEN status='pending' THEN 0 ELSE 1 END, id ASC", [(int) $r['user_id'], (int) $r['stock_id']]) as $o) {
                if ($keep + (int) $o['qty'] <= $total) $keep += (int) $o['qty']; else $ids[] = (int) $o['id'];
            }
            $note = 'anuluje ' . count($ids) . ' zleceń sprzedaży (brak pokrycia)' . ($made ? ", dotwarza $made szt." : '');
            return [$total - $keep, $keep, $note, count($ids), $made, $ids];
        }
        return [$total - $need, $need, $made ? "dotwarza $made szt. (ujemna suma)" : '', 0, $made, []];
    }

    /**
     * Wykonaj korekty. Wołający trzyma blokadę świata (Engine::worldLock), my trzymamy transakcję.
     * Zwraca podsumowanie do komunikatu dla GM. Każdy zmieniony wiersz ma osobny wpis w dzienniku.
     * Zapis przyrostowy (patrz nagłówek pliku): UPDATE ... = kolumna ± różnica, nigdy = wartość z planu.
     */
    public static function apply(int $adminId): array
    {
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            // gotówka: wolne − d, zamrożone + d — suma per gracz z definicji bez zmian
            $updUser   = $pdo->prepare("UPDATE users SET cash = ROUND(cash - ?, 2), cash_reserved = ROUND(cash_reserved + ?, 2) WHERE id=?");
            $updWallet = $pdo->prepare("UPDATE wallets SET qty = qty + ?, qty_reserved = qty_reserved + ? WHERE user_id=? AND stock_id=?");
            $cancel    = $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=? AND status IN ('active','pending')");
            $nCash = 0; $nQty = 0; $nCancel = 0; $made = 0; $skipped = 0;

            foreach (self::cashIssues() as $r) {
                [$cash, $res, $note, $toCancel, $ids] = $r['plan'];
                if ($toCancel === 0 && $note !== '') { $skipped++; continue; }   // ujemna suma — nie ruszamy
                // strażnik pieniądza: plan musi zachować sumę gracza (wolne + zamrożone). Sumy ze SELECT-ów przed/po
                // nie nadają się do tego na MySQL — REPEATABLE READ czyta ten sam zrzut i nie widzi gotówki,
                // która w oknie zmieniła właściciela (realizacja cudzego zlecenia).
                $drift = round(($cash + $res) - ((float) $r['cash'] + (float) $r['cash_reserved']), 2);
                if (abs($drift) > 0.005) throw new RuntimeException(sprintf('plan gotówki %s zmieniłby pieniądz w świecie o %.2f PLN — korekta wycofana', $r['username'], $drift));
                foreach ($ids as $oid) {
                    // tylko zlecenia ze zrzutu planu; claim-first — zrealizowane/anulowane w międzyczasie pomijamy
                    $cancel->execute([$oid]);
                    if ($cancel->rowCount() !== 1) continue;
                    $nCancel++;
                    if ((int) $r['is_bot'] === 0) Engine::notify((int) $r['id'], 'order', "Zlecenie kupna #$oid anulowane przez administrację (porządkowanie rezerwacji).", 'portfolio.php');
                }
                $d = round($res - (float) $r['cash_reserved'], 2);   // o tyle rośnie rezerwacja (i maleje wolna gotówka)
                $updUser->execute([$d, $d, (int) $r['id']]);
                Log::write('warn', 'gm', 'reconcile.cash', "korekta gotówki {$r['username']}: wolne {$r['cash']} → $cash, zamrożone {$r['cash_reserved']} → $res (zapis przyrostowy: Δzamrożone " . ($d >= 0 ? '+' : '') . number_format($d, 2, '.', '') . ')' . ($note !== '' ? " ($note)" : ''),
                    ['user_id' => (int) $r['id'], 'admin' => $adminId, 'should_be' => (float) $r['should_be'], 'delta' => $d]);
                $nCash++;
            }

            foreach (self::qtyIssues() as $r) {
                [$qty, $res, $note, $toCancel, $created, $ids] = $r['plan'];
                foreach ($ids as $oid) {
                    $cancel->execute([$oid]);
                    if ($cancel->rowCount() !== 1) continue;
                    $nCancel++;
                    if ((int) $r['is_bot'] === 0) Engine::notify((int) $r['user_id'], 'order', "Zlecenie sprzedaży #$oid ({$r['ticker']}) anulowane przez administrację (porządkowanie rezerwacji).", 'portfolio.php');
                }
                // Δwolne + Δzarezerwowane = dotworzone akcje (0 poza przypadkiem ujemnej sumy)
                $dq = $qty - (int) $r['qty'];
                $dr = $res - (int) $r['qty_reserved'];
                if ($dq + $dr !== $created) throw new RuntimeException("plan akcji {$r['username']}/{$r['ticker']} niespójny (Δ=" . ($dq + $dr) . ", dotworzone=$created)");
                $updWallet->execute([$dq, $dr, (int) $r['user_id'], (int) $r['stock_id']]);
                $made += $created;
                Log::write('warn', 'gm', 'reconcile.qty', "korekta akcji {$r['username']} / {$r['ticker']}: wolne {$r['qty']} → $qty, zarezerwowane {$r['qty_reserved']} → $res (zapis przyrostowy: Δwolne $dq, Δzarezerwowane $dr)" . ($note !== '' ? " ($note)" : ''),
                    ['user_id' => (int) $r['user_id'], 'stock_id' => (int) $r['stock_id'], 'admin' => $adminId, 'should_be' => (int) $r['should_be']]);
                $nQty++;
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
