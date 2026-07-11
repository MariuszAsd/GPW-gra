<?php
/**
 * Żetony Maklera — waluta premium (monetyzacja).
 *
 * ZASADA PROJEKTOWA: żetonów NIE wymienia się na PLN w grze (zero pay-to-win
 * w rankingu). Kupują INFORMACJĘ i WYGODĘ: skaner sygnałów AT, rekomendacje
 * domu maklerskiego dzień przed resztą — jak przywileje klientów premium
 * prawdziwych biur maklerskich. Każda operacja ląduje w token_ledger
 * (saldo po operacji) — pełny audyt do obsługi reklamacji.
 *
 * Zdobywanie: +10 powitalne, nagrody z wyzwań (podium), +2 za każdą odznakę.
 * Docelowo: pakiety za prawdziwe pieniądze (sklep pokazuje ofertę; płatności
 * dojdą po podpięciu operatora — na razie żetony przyznaje GM).
 */
final class Tokens
{
    /** Pakiety premium: klucz => [sesji (dni), cena w żetonach, nazwa, opis] */
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

    /** Przyznaj żetony (nagrody, GM, przyszłe zakupy). */
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
            Engine::notify($uid, 'token', "🪙 +$n Żetonów Maklera" . ($note !== '' ? " — $note" : '') . " (saldo: $bal).", 'sklep.php');
        } catch (\Throwable $e) { Log::write('warn', 'engine', 'tokens.grant', $e->getMessage()); }
    }

    /** Wydaj żetony ATOMOWO (odmowa przy braku środków). Zwraca [ok, komunikat]. */
    public static function spend(int $uid, int $n, string $reason, string $note = ''): array
    {
        if ($n <= 0) return [false, 'Nieprawidłowa kwota.'];
        $pdo = Db::pdo();
        $st = $pdo->prepare("UPDATE users SET tokens = tokens - ? WHERE id=? AND tokens >= ?");
        $st->execute([$n, $uid, $n]);
        if ($st->rowCount() === 0) return [false, 'Za mało Żetonów Maklera (potrzeba: ' . $n . ', masz: ' . self::balance($uid) . ').'];
        $bal = self::balance($uid);
        $pdo->prepare("INSERT INTO token_ledger (user_id, delta, balance, reason, note, created_at) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, -$n, $bal, mb_substr($reason, 0, 40), $note !== '' ? mb_substr($note, 0, 160) : null, Db::now()]);
        return [true, "Wydano $n żetonów (saldo: $bal)."];
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

    /** Kup/przedłuż pakiet za żetony. Zwraca [ok, komunikat]. */
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
