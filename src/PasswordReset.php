<?php
/**
 * Reset hasła przez e-mail: token jednorazowy, ważny 60 minut.
 * Bezpieczeństwo: w bazie tylko sha256 tokenu (jawny token wyłącznie w mailu),
 * brak ujawniania istnienia konta (zawsze ta sama odpowiedź), limit 3 próśb
 * na konto na godzinę, token kasowany po użyciu.
 */
final class PasswordReset
{
    public const TTL_MIN = 60;

    /**
     * Prośba o reset dla adresu e-mail. Zwraca [ok, tokenJawny|null, uid|null] —
     * ok=false TYLKO przy przekroczonym limicie (strona i tak pokazuje neutralny
     * komunikat, żeby nie zdradzać, czy konto istnieje).
     */
    public static function request(string $email): array
    {
        $email = mb_strtolower(trim($email));
        $u = Engine::row("SELECT id, username FROM users WHERE email=? AND is_bot=0", [$email]);
        if (!$u) return [true, null, null];   // neutralnie: nie zdradzamy braku konta

        $hourAgo = date('Y-m-d H:i:s', time() - 3600);
        $recent = (int) Engine::one("SELECT COUNT(*) FROM password_resets WHERE user_id=? AND created_at > ?", [$u['id'], $hourAgo]);
        if ($recent >= 3) {
            Log::write('warn', 'auth', 'reset.ratelimit', 'limit próśb o reset dla #' . $u['id']);
            return [false, null, null];
        }

        $token = bin2hex(random_bytes(32));
        Db::pdo()->prepare("INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)")
            ->execute([(int) $u['id'], hash('sha256', $token), date('Y-m-d H:i:s', time() + self::TTL_MIN * 60), Db::now()]);
        Log::write('info', 'auth', 'reset.request', 'wysłano link resetu dla ' . $u['username']);
        return [true, $token, (int) $u['id']];
    }

    /** Zwraca user_id dla ważnego (nieużytego, nieprzeterminowanego) tokenu albo null. */
    public static function validate(string $token): ?int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
        $r = Engine::row("SELECT user_id FROM password_resets WHERE token_hash=? AND used_at IS NULL AND expires_at > ?",
            [hash('sha256', $token), Db::now()]);
        return $r ? (int) $r['user_id'] : null;
    }

    /** Ustaw nowe hasło ważnym tokenem (jednorazowo). Zwraca [ok, komunikat]. */
    public static function consume(string $token, string $newPass): array
    {
        $uid = self::validate($token);
        if ($uid === null) return [false, 'Link wygasł albo został już użyty — poproś o nowy.'];
        if (strlen($newPass) < 6) return [false, 'Hasło musi mieć co najmniej 6 znaków.'];
        $pdo = Db::pdo();
        // oznaczenie użycia PRZED zmianą hasła — warunek used_at IS NULL ubija wyścig dwóch kart
        $st = $pdo->prepare("UPDATE password_resets SET used_at=? WHERE token_hash=? AND used_at IS NULL");
        $st->execute([Db::now(), hash('sha256', $token)]);
        if ($st->rowCount() === 0) return [false, 'Link został już użyty — poproś o nowy.'];
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($newPass, PASSWORD_DEFAULT), $uid]);
        Engine::journal($uid, 'system', '🔑 Hasło zostało zmienione (reset przez e-mail).');
        Log::write('info', 'auth', 'reset.done', "hasło zresetowane dla #$uid");
        return [true, 'Hasło zmienione — możesz się zalogować.'];
    }
}
