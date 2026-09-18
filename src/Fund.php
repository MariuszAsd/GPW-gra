<?php
/**
 * Fundusz indeksowy MAK40 — jednostki wyceniane według Indeksu MAK40 (cały rynek ważony kapitalizacją):
 * 1 jednostka = indeks / 10 PLN (≈ 100 PLN przy bazie 1000 pkt). Gracz kupuje za dowolną kwotę i sprzedaje,
 * kiedy chce; prowizja jak od akcji (przy sprzedaży, do skarbca).
 *
 * Zamknięta ekonomia: gotówka za jednostki trafia do PULI funduszu (game_state.fund_pool — jest w świecie,
 * liczy ją Engine::worldCash). Przy sprzedaży pula oddaje koszt zakupu, a zysk/stratę względem indeksu
 * rozlicza SKARBIEC (tak jak odsetki lokat) — suma pieniądza w świecie nie zmienia się nigdy.
 * Wartość jednostek liczy się do kapitału gracza (ranking, ligi, drabinka) jak akcje.
 * Tylko konto główne gracza — portfel wyzwania gra wyłącznie akcjami.
 */
final class Fund
{
    public const NAME = 'MAK40';
    public const MIN_AMOUNT = 100.0;
    public const UNIT_DIV = 10;   // wycena jednostki = indeks / 10

    /** Wycena jednostki (NAV) — z bieżącego indeksu, 4 miejsca (jednostki są ułamkowe). */
    public static function nav(): float
    {
        return round(Engine::indexValue() / self::UNIT_DIV, 4);
    }

    /** Pula funduszu = gotówka wpłacona za jednostki, jeszcze niewypłacona (w świecie). */
    public static function pool(): float
    {
        $v = Engine::one("SELECT v FROM game_state WHERE k='fund_pool'");
        return ($v === false || $v === null) ? 0.0 : round((float) $v, 2);
    }

    private static function poolAdd(float $delta): void
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare("UPDATE game_state SET v = ROUND(v + ?, 2) WHERE k='fund_pool'");
        $st->execute([round($delta, 2)]);
        if ($st->rowCount() === 0 && Engine::one("SELECT COUNT(*) FROM game_state WHERE k='fund_pool'") == 0) {
            $pdo->prepare("INSERT INTO game_state (k, v) VALUES ('fund_pool', ?)")->execute([(string) round($delta, 2)]);
        }
    }

    /** Pozycja gracza: jednostki, koszt zakupu, bieżąca wartość, wynik. */
    public static function position(int $uid): array
    {
        $r = Engine::row("SELECT units, cost FROM fund_positions WHERE user_id=?", [$uid]);
        $units = $r ? (float) $r['units'] : 0.0;
        $cost  = $r ? (float) $r['cost'] : 0.0;
        $value = round($units * self::nav(), 2);
        return ['units' => $units, 'cost' => $cost, 'value' => $value, 'pl' => round($value - $cost, 2)];
    }

    /** Wartość jednostek gracza (do kapitału). Bez pozycji = 0 bez liczenia indeksu. */
    public static function value(int $uid): float
    {
        $units = Engine::one("SELECT units FROM fund_positions WHERE user_id=?", [$uid]);
        if ($units === false || $units === null || (float) $units <= 0) return 0.0;
        return round((float) $units * self::nav(), 2);
    }

    /** Kupno jednostek za kwotę (atomowo z wolnej gotówki). Zwraca [ok, komunikat]. */
    public static function buy(int $uid, float $amount): array
    {
        $amount = round($amount, 2);
        if ($amount < self::MIN_AMOUNT) return [false, 'Minimalna kwota zakupu to ' . number_format(self::MIN_AMOUNT, 0, ',', ' ') . ' PLN.'];
        $u = Engine::row("SELECT role FROM users WHERE id=?", [$uid]);
        if (!$u || !in_array($u['role'], ['player', 'qa'], true)) return [false, 'Fundusz jest dostępny tylko dla graczy (konto główne).'];
        $nav = self::nav();
        if ($nav <= 0) return [false, 'Brak wyceny indeksu — spróbuj za chwilę.'];
        $units = round($amount / $nav, 4);
        if ($units <= 0) return [false, 'Kwota za mała na choć ułamek jednostki.'];
        $pdo = Db::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE users SET cash = cash - ? WHERE id = ? AND cash >= ?");
            $st->execute([$amount, $uid, $amount]);
            if ($st->rowCount() === 0) { if ($own) $pdo->rollBack(); return [false, 'Za mało wolnej gotówki.']; }
            $ins = Db::driver() === 'mysql'
                ? "INSERT IGNORE INTO fund_positions (user_id, units, cost, updated_at) VALUES (?, 0, 0, ?)"
                : "INSERT OR IGNORE INTO fund_positions (user_id, units, cost, updated_at) VALUES (?, 0, 0, ?)";
            $pdo->prepare($ins)->execute([$uid, Db::now()]);
            $pdo->prepare("UPDATE fund_positions SET units = units + ?, cost = cost + ?, updated_at = ? WHERE user_id = ?")->execute([$units, $amount, Db::now(), $uid]);
            self::poolAdd($amount);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $msg = 'Kupiono ' . number_format($units, 4, ',', ' ') . ' jednostek ' . self::NAME . ' po ' . number_format($nav, 2, ',', ' ') . ' PLN za ' . number_format($amount, 2, ',', ' ') . ' PLN.';
        Engine::ledger($uid, -$amount, 'fundusz', 'Fundusz ' . self::NAME . ': zakup ' . number_format($units, 4, ',', ' ') . ' jednostek', 'portfolio.php?tab=lok');
        Engine::journal($uid, 'system', '📊 ' . $msg . ' Wartość jednostek liczy się do Twojego kapitału.', 'portfolio.php?tab=lok');
        return [true, $msg . ' Wartość jednostek liczy się do Twojego kapitału.'];
    }

    /** Sprzedaż jednostek (null = wszystkie) po bieżącej wycenie, minus prowizja. Zwraca [ok, komunikat]. */
    public static function sell(int $uid, ?float $units): array
    {
        $pos = Engine::row("SELECT units, cost FROM fund_positions WHERE user_id=?", [$uid]);
        $held = $pos ? (float) $pos['units'] : 0.0;
        if ($held <= 0) return [false, 'Nie masz jednostek funduszu.'];
        $all = $units === null || $units >= $held - 0.00005;
        $units = $all ? $held : round($units, 4);
        if ($units <= 0) return [false, 'Podaj liczbę jednostek do sprzedaży.'];
        $nav = self::nav();
        $value = round($units * $nav, 2);
        // koszt oddawany przez pulę proporcjonalnie do sprzedawanej części; reszta (zysk/strata) to skarbiec
        $costPart = $all ? round((float) $pos['cost'], 2) : round((float) $pos['cost'] * $units / $held, 2);
        $fee = round($value * Engine::feeRate(), 2);
        $payout = round($value - $fee, 2);
        $pdo = Db::pdo();
        $own = !$pdo->inTransaction();
        if ($own) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE fund_positions SET units = units - ?, cost = cost - ?, updated_at = ? WHERE user_id = ? AND units >= ?");
            $st->execute([$units, $costPart, Db::now(), $uid, $units - 0.00005]);
            if ($st->rowCount() === 0) { if ($own) $pdo->rollBack(); return [false, 'Nie masz tylu jednostek (pozycja zmieniła się w międzyczasie).']; }
            if ($all) $pdo->prepare("DELETE FROM fund_positions WHERE user_id = ?")->execute([$uid]);
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$payout, $uid]);
            self::poolAdd(-$costPart);
            // zysk płaci skarbiec, stratę zatrzymuje skarbiec; prowizja jak od akcji — do skarbca
            Engine::treasuryAdjust(-round($value - $costPart, 2) + $fee);
            if ($own) $pdo->commit();
        } catch (\Throwable $e) {
            if ($own && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        $pl = round($value - $costPart, 2);
        $msg = 'Sprzedano ' . number_format($units, 4, ',', ' ') . ' jednostek ' . self::NAME . ' po ' . number_format($nav, 2, ',', ' ') . ' PLN: '
             . number_format($payout, 2, ',', ' ') . ' PLN na konto (prowizja ' . number_format($fee, 2, ',', ' ') . ' PLN), wynik '
             . ($pl >= 0 ? '+' : '') . number_format($pl, 2, ',', ' ') . ' PLN.';
        Engine::ledger($uid, $payout, 'fundusz', 'Fundusz ' . self::NAME . ': sprzedaż ' . number_format($units, 4, ',', ' ') . ' jednostek (wynik ' . ($pl >= 0 ? '+' : '') . number_format($pl, 2, ',', ' ') . ' PLN)', 'portfolio.php?tab=lok');
        Engine::journal($uid, 'system', '📊 ' . $msg, 'portfolio.php?tab=lok');
        return [true, $msg];
    }
}
