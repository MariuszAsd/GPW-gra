<?php
/**
 * Tokeny inwestora — waluta premium (monetyzacja).
 *
 * ZASADA PROJEKTOWA: tokenów NIE wymienia się na PLN w grze (zero pay-to-win
 * w rankingu). Kupują INFORMACJĘ i WYGODĘ: skaner sygnałów AT, rekomendacje
 * domu maklerskiego dzień przed resztą — jak przywileje klientów premium
 * prawdziwych biur maklerskich. Każda operacja ląduje w token_ledger
 * (saldo po operacji) — pełny audyt do obsługi reklamacji.
 *
 * Zdobywanie: +10 powitalne, nagrody z wyzwań (podium), +2 za każdą odznakę.
 * Docelowo: pakiety za prawdziwe pieniądze (sklep pokazuje ofertę; płatności
 * dojdą po podpięciu operatora — na razie tokeny przyznaje GM).
 */
final class Tokens
{
    /** Pakiety premium: klucz => [sesji (dni), cena w tokenach, nazwa, opis] */
    public const PASSES = [
        'analityk' => [7, 15, 'Pakiet Analityka',
            'Skaner sygnałów AT przy każdej spółce na Rynku, alerty 🔔 przy mocnym sygnale na obserwowanych spółkach + rekomendacje domu maklerskiego DZIEŃ WCZEŚNIEJ niż pozostali gracze.'],
        'raport' => [7, 25, 'Raport Premium',
            'Pełny raport analityczny DM na karcie każdej spółki: wycena z premią/dyskontem, historia wyników i dywidend, profil ryzyka i charakter spółki — wiedza, którą inni muszą zgadywać.'],
    ];

    public static function balance(int $uid): int
    {
        return (int) (Engine::one("SELECT tokens FROM users WHERE id=?", [$uid]) ?: 0);
    }

    /** Przyznaj tokeny (nagrody, GM, przyszłe zakupy). */
    public static function grant(int $uid, int $n, string $reason, string $note = ''): void
    {
        if ($n <= 0) return;
        try {
            $uid = Engine::challengeOwner($uid);
            $pdo = Db::pdo();
            $pdo->prepare("UPDATE users SET tokens = tokens + ? WHERE id=?")->execute([$n, $uid]);
            $bal = self::balance($uid);
            $pdo->prepare("INSERT INTO token_ledger (user_id, delta, balance, reason, note, created_at) VALUES (?,?,?,?,?,?)")
                ->execute([$uid, $n, $bal, mb_substr($reason, 0, 40), $note !== '' ? mb_substr($note, 0, 160) : null, Db::now()]);
            Engine::notify($uid, 'token', "🪙 +$n Tokenów inwestora" . ($note !== '' ? " — $note" : '') . " (saldo: $bal).", 'sklep.php');
        } catch (\Throwable $e) { Log::write('warn', 'engine', 'tokens.grant', $e->getMessage()); }
    }

    /** Wydaj tokeny ATOMOWO (odmowa przy braku środków). Zwraca [ok, komunikat]. */
    public static function spend(int $uid, int $n, string $reason, string $note = ''): array
    {
        if ($n <= 0) return [false, 'Nieprawidłowa kwota.'];
        $pdo = Db::pdo();
        $st = $pdo->prepare("UPDATE users SET tokens = tokens - ? WHERE id=? AND tokens >= ?");
        $st->execute([$n, $uid, $n]);
        if ($st->rowCount() === 0) return [false, 'Za mało Tokenów inwestora (potrzeba: ' . $n . ', masz: ' . self::balance($uid) . ').'];
        $bal = self::balance($uid);
        $pdo->prepare("INSERT INTO token_ledger (user_id, delta, balance, reason, note, created_at) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, -$n, $bal, mb_substr($reason, 0, 40), $note !== '' ? mb_substr($note, 0, 160) : null, Db::now()]);
        return [true, "Wydano $n tokenów (saldo: $bal)."];
    }

    /** Czy pakiet aktywny (do sesji włącznie)? */
    public static function hasPass(int $uid, string $kind): bool
    {
        [$session] = Engine::sessionInfo();
        $until = Engine::one("SELECT until_session FROM premium_passes WHERE user_id=? AND kind=?", [$uid, $kind]);
        return $until !== false && $until !== null && (int) $until >= $session;
    }

    /** Sesja końca pakietu (null = brak/nieaktywny). */
    public static function passUntil(int $uid, string $kind): ?int
    {
        $until = Engine::one("SELECT until_session FROM premium_passes WHERE user_id=? AND kind=?", [$uid, $kind]);
        if ($until === false || $until === null) return null;
        [$session] = Engine::sessionInfo();
        return (int) $until >= $session ? (int) $until : null;
    }

    /**
     * Okres próbny premium ("spróbuj zanim kupisz" — standard F2P): aktywni
     * gracze dostają RAZ darmowe dni WSZYSTKICH pakietów. Warunki (strojone
     * w GM): aktywność w >= trial_active_days różnych dniach kalendarzowych
     * i pierwsza aktywność >= trial_min_age_days temu. Pomijamy graczy,
     * którzy już mieli jakikolwiek pakiet (znają premium). Znacznik
     * jednorazowości: user_items 'trial_premium'. Wołane raz na sesję
     * z Engine::rollSession.
     */
    public static function grantTrials(int $session): void
    {
        $on = Engine::one("SELECT v FROM game_state WHERE k='trial_enabled'");
        if (!(($on === false || $on === null) ? true : (int) $on === 1)) return;
        $needDays = max(1, (int) (Engine::one("SELECT v FROM game_state WHERE k='trial_active_days'") ?: 5));
        $minAge   = max(1, (int) (Engine::one("SELECT v FROM game_state WHERE k='trial_min_age_days'") ?: 7));
        $sessions = max(1, (int) (Engine::one("SELECT v FROM game_state WHERE k='trial_sessions'") ?: 2));
        $cutoff = date('Y-m-d H:i:s', time() - $minAge * 86400);

        // $needDays wchodzi do SQL jako zwalidowany int (bind PDO daje tekst,
        // a SQLite porównuje wtedy int z tekstem zawsze jako fałsz)
        $eligible = Engine::all(
            "SELECT u.id, u.username FROM users u
             WHERE u.is_bot = 0 AND u.role = 'player'
               AND NOT EXISTS (SELECT 1 FROM user_items i WHERE i.user_id = u.id AND i.item = 'trial_premium')
               AND NOT EXISTS (SELECT 1 FROM premium_passes p WHERE p.user_id = u.id)
               AND (SELECT COUNT(DISTINCT SUBSTR(j.ts, 1, 10)) FROM player_journal j WHERE j.user_id = u.id) >= $needDays
               AND (SELECT MIN(j.ts) FROM player_journal j WHERE j.user_id = u.id) <= ?", [$cutoff]);

        $pdo = Db::pdo();
        $until = $session + $sessions - 1;   // np. 2 dni = bieżąca sesja + następna
        foreach ($eligible as $u) {
            $uid = (int) $u['id'];
            try {
                $pdo->prepare("INSERT INTO user_items (user_id, item, created_at) VALUES (?, 'trial_premium', ?)")
                    ->execute([$uid, Db::now()]);
            } catch (\Throwable $e) { continue; }   // wyścig dwóch ticków — znacznik już jest
            foreach (array_keys(self::PASSES) as $kind) {
                $pdo->prepare("INSERT INTO premium_passes (user_id, kind, until_session, created_at) VALUES (?,?,?,?)")
                    ->execute([$uid, $kind, $until, Db::now()]);
            }
            Engine::notify($uid, 'token',
                "🎁 Prezent za aktywną grę: $sessions dni PEŁNEGO premium gratis! Skaner AT, alerty 🔔, rekomendacje dzień wcześniej i Raport DM — aktywne do końca sesji #$until. Przetestuj wszystko!",
                'sklep.php');
            Log::write('info', 'engine', 'trial.grant', "trial premium dla {$u['username']} do sesji #$until");
        }
    }

    /** Kup/przedłuż pakiet za tokeny. Zwraca [ok, komunikat]. */
    public static function buyPass(int $uid, string $kind): array
    {
        if (!isset(self::PASSES[$kind])) return [false, 'Nie ma takiego pakietu.'];
        [$days, $price, $name] = self::PASSES[$kind];
        [$session] = Engine::sessionInfo();
        [$ok, $msg] = self::spend($uid, $price, 'pass', $name);
        if (!$ok) return [false, $msg];
        $pdo = Db::pdo();
        $cur = Engine::one("SELECT until_session FROM premium_passes WHERE user_id=? AND kind=?", [$uid, $kind]);
        $from = max($session - 1, ($cur !== false && $cur !== null) ? (int) $cur : 0);   // przedłużenie doklejane do końca
        $until = $from + $days;
        if ($cur === false || $cur === null) {
            $pdo->prepare("INSERT INTO premium_passes (user_id, kind, until_session, created_at) VALUES (?,?,?,?)")
                ->execute([$uid, $kind, $until, Db::now()]);
        } else {
            $pdo->prepare("UPDATE premium_passes SET until_session=? WHERE user_id=? AND kind=?")->execute([$until, $uid, $kind]);
        }
        Engine::journal($uid, 'token', "🪙 Aktywowano: $name (do sesji #$until).", 'sklep.php');
        Log::write('info', 'player', 'tokens.pass', "$name dla #$uid do sesji $until");
        return [true, "$name aktywny do końca sesji #$until. Miłego skanowania!"];
    }
}
