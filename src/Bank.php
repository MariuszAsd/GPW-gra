<?php
/**
 * Lokaty (obligacje skarbowe gry): bezpieczne parkowanie gotówki na N sesji
 * za stały procent. Kapitał lokaty liczy się do kapitału gracza (ranking,
 * wykres, cel gry) — patrz Engine::lockedFunds(). Zerwanie przed terminem
 * zwraca kapitał BEZ odsetek. Odsetki wypłaca skarbiec gry (prowizje).
 * Tylko konto główne gracza — portfel wyzwania gra wyłącznie akcjami.
 */
final class Bank
{
    /** Oferta: sesje => oprocentowanie % za CAŁY okres (GM może nadpisać w game_state 'bank_offers'). */
    public const OFFERS = [3 => 1.0, 7 => 3.0, 14 => 7.5];
    public const MIN_AMOUNT = 1000.0;

    /** Aktualna oferta (z ewentualną nadpiską GM w formacie "3:1.0,7:3.0,14:7.5"). */
    public static function offers(): array
    {
        $raw = Engine::one("SELECT v FROM game_state WHERE k='bank_offers'");
        if ($raw === false || $raw === null || trim((string) $raw) === '') return self::OFFERS;
        $out = [];
        foreach (explode(',', (string) $raw) as $pair) {
            [$t, $r] = array_pad(explode(':', trim($pair)), 2, null);
            if ((int) $t > 0 && $r !== null) $out[(int) $t] = max(0.0, min(100.0, (float) str_replace(',', '.', $r)));
        }
        return $out ?: self::OFFERS;
    }

    /** Założenie lokaty z wolnej gotówki (atomowo). Zwraca [ok, komunikat]. */
    public static function open(int $userId, float $amount, int $termSessions): array
    {
        $offers = self::offers();
        if (!isset($offers[$termSessions])) return [false, 'Nie ma lokaty na taki okres.'];
        $amount = round($amount, 2);
        if ($amount < self::MIN_AMOUNT) return [false, 'Minimalna kwota lokaty to ' . number_format(self::MIN_AMOUNT, 0, ',', ' ') . ' PLN.'];
        $u = Engine::row("SELECT role FROM users WHERE id=?", [$userId]);
        if (!$u || !in_array($u['role'], ['player', 'qa'], true)) return [false, 'Lokaty są dostępne tylko dla graczy (konto główne).'];

        $rate = $offers[$termSessions];
        [$session] = Engine::sessionInfo();
        $pdo = Db::pdo();
        // pobranie atomowe — warunek cash >= amount chroni przed podwójnym POST-em
        $st = $pdo->prepare("UPDATE users SET cash = cash - ? WHERE id = ? AND cash >= ?");
        $st->execute([$amount, $userId, $amount]);
        if ($st->rowCount() === 0) return [false, 'Za mało wolnej gotówki.'];
        try {
            $pdo->prepare("INSERT INTO deposits (user_id, amount, rate_pct, start_session, end_session, status, created_at)
                           VALUES (?,?,?,?,?, 'active', ?)")
                ->execute([$userId, $amount, $rate, $session, $session + $termSessions, Db::now()]);
        } catch (\Throwable $e) {   // INSERT padł (deadlock/limit typów) — gotówka wraca, nic nie ginie
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$amount, $userId]);
            Log::write('error', 'engine', 'bank.open', $e->getMessage(), ['user_id' => $userId]);
            return [false, 'Nie udało się założyć lokaty — środki wróciły na konto. Spróbuj ponownie.'];
        }
        $payout = round($amount * (1 + $rate / 100), 2);
        $odm = self::sesje($termSessions);
        Engine::journal($userId, 'system', '🏦 Założono lokatę: ' . number_format($amount, 2, ',', ' ')
            . " PLN na $termSessions $odm ($rate%). Wypłata " . number_format($payout, 2, ',', ' ') . ' PLN w sesji #' . ($session + $termSessions) . '.', 'portfolio.php?tab=lok');
        return [true, 'Lokata założona: wypłata ' . number_format($payout, 2, ',', ' ') . ' PLN w sesji #' . ($session + $termSessions)
            . '. Kapitał lokaty nadal liczy się do Twojego wyniku.'];
    }

    /** Zerwanie lokaty przed terminem: kapitał wraca, odsetki przepadają. */
    public static function breakDeposit(int $userId, int $depositId): array
    {
        $pdo = Db::pdo();
        // najpierw oznacz (atomowo), potem zwróć środki — odporne na podwójny POST
        $st = $pdo->prepare("UPDATE deposits SET status='broken' WHERE id=? AND user_id=? AND status='active'");
        $st->execute([$depositId, $userId]);
        if ($st->rowCount() === 0) return [false, 'Nie znaleziono aktywnej lokaty.'];
        $amount = (float) Engine::one("SELECT amount FROM deposits WHERE id=?", [$depositId]);
        try {
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$amount, $userId]);
        } catch (\Throwable $e) {   // zwrot padł — cofnij oznaczenie, żeby lokata nie zginęła
            $pdo->prepare("UPDATE deposits SET status='active' WHERE id=?")->execute([$depositId]);
            Log::write('error', 'engine', 'bank.break', $e->getMessage(), ['deposit' => $depositId]);
            return [false, 'Nie udało się zerwać lokaty — spróbuj ponownie.'];
        }
        Engine::journal($userId, 'system', '🏦 Zerwano lokatę: ' . number_format($amount, 2, ',', ' ') . ' PLN wróciło (bez odsetek).', 'portfolio.php?tab=lok');
        return [true, 'Lokata zerwana — ' . number_format($amount, 2, ',', ' ') . ' PLN wróciło na konto (odsetki przepadły).'];
    }

    /** Hak na granicy sesji: wypłata lokat, którym minął termin (kapitał + odsetki). */
    public static function onRoll(int $session): void
    {
        $pdo = Db::pdo();
        foreach (Engine::all("SELECT * FROM deposits WHERE status='active' AND end_session <= ?", [$session]) as $d) {
            // oznacz atomowo — powtórny roll nie wypłaci drugi raz
            $st = $pdo->prepare("UPDATE deposits SET status='paid' WHERE id=? AND status='active'");
            $st->execute([(int) $d['id']]);
            if ($st->rowCount() === 0) continue;
            $payout = round((float) $d['amount'] * (1 + (float) $d['rate_pct'] / 100), 2);
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$payout, (int) $d['user_id']]);
            $interest = round($payout - (float) $d['amount'], 2);
            // odsetki finansuje skarbiec gry (tam trafiają prowizje); skarbiec może zejść pod zero —
            // to jawna dotacja gry widoczna w panelu GM. Uwaga na MySQL: rowCount() po UPDATE liczy
            // wiersze ZMIENIONE, więc istnienie wiersza sprawdzamy SELECT-em, nie rowCount-em.
            if (abs($interest) >= 0.005) {
                if (Engine::one("SELECT COUNT(*) FROM game_state WHERE k='treasury'")) {
                    $pdo->prepare("UPDATE game_state SET v = ROUND(v - ?, 2) WHERE k='treasury'")->execute([$interest]);
                } else {
                    $pdo->prepare("INSERT INTO game_state (k, v) VALUES ('treasury', ?)")->execute([(string) (-$interest)]);
                }
            }
            Engine::notify((int) $d['user_id'], 'system', '🏦 Lokata wypłacona: ' . number_format($payout, 2, ',', ' ')
                . ' PLN (w tym odsetki ' . number_format($interest, 2, ',', ' ') . ' PLN).', 'portfolio.php?tab=lok');
            Log::write('info', 'engine', 'bank.payout', 'lokata #' . $d['id'] . ' wypłacona', ['user_id' => $d['user_id'], 'payout' => $payout]);
        }
    }

    /** Odmiana: 2-4 sesje, 5+ sesji (dla 22-24 znów sesje itd.). */
    public static function sesje(int $n): string
    {
        return ($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 12 || $n % 100 > 14)) ? 'sesje' : 'sesji';
    }

    /** Aktywne lokaty gracza (do widoku Portfela). */
    public static function activeFor(int $userId): array
    {
        return Engine::all("SELECT * FROM deposits WHERE user_id=? AND status='active' ORDER BY end_session ASC", [$userId]);
    }
}
