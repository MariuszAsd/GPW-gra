<?php
/**
 * Codzienna pętla: seria logowań (streak) + misje dnia.
 *
 * SERIA: pierwsza aktywność w nowym dniu = +1 token; co 7. dzień serii bonus +3.
 * Przerwa dłuższa niż 1 dzień zeruje serię. Dzień = data serwera (spójna
 * z created_at w całej bazie).
 *
 * MISJE: 3 dziennie, wspólne dla wszystkich (rotacja deterministyczna z daty —
 * gracze mogą porównywać na czacie). Zaliczenie sprawdzane po stanie bazy
 * z danego dnia, nagroda wypłaca się raz (UNIQUE user_id+day+code).
 * Wartości nagród celowo skromne: aktywny tydzień ≈ tygodniowy Pakiet Analityka.
 */
final class Daily
{
    public const STREAK_DAILY = 1;   // tokeny za pierwszy wjazd dnia
    public const STREAK_BONUS = 3;   // dodatkowo co 7. dzień serii

    /** Katalog misji: kod => [opis, tokeny, SQL zwracający >0 gdy zaliczona (:uid, :day)]. */
    public const MISSIONS = [
        'kup' => ['Kup akcje dowolnej spółki', 1,
            "SELECT COUNT(*) FROM transactions WHERE buyer_id=:uid AND created_at LIKE :day"],
        'sprzedaj' => ['Sprzedaj akcje z portfela', 1,
            "SELECT COUNT(*) FROM transactions WHERE seller_id=:uid AND created_at LIKE :day"],
        'limit' => ['Złóż zlecenie (LIMIT albo PKC)', 1,
            "SELECT COUNT(*) FROM orders WHERE user_id=:uid AND sl_price IS NULL AND tp_price IS NULL AND created_at LIKE :day"],
        'sltp' => ['Ustaw zlecenie obronne SL/TP', 1,
            "SELECT COUNT(*) FROM orders WHERE user_id=:uid AND (sl_price IS NOT NULL OR tp_price IS NOT NULL) AND created_at LIKE :day"],
        'czat' => ['Napisz na czacie rynkowym', 1,
            "SELECT COUNT(*) FROM chat_messages WHERE user_id=:uid AND created_at LIKE :day"],
        'obserwuj' => ['Dodaj spółkę do obserwowanych ★', 1,
            "SELECT COUNT(*) FROM watchlist WHERE user_id=:uid AND created_at LIKE :day"],
        'dwie_spolki' => ['Handluj dwiema różnymi spółkami', 2,
            "SELECT COUNT(DISTINCT stock_id) - 1 FROM transactions WHERE (buyer_id=:uid OR seller_id=:uid) AND created_at LIKE :day"],
        'obrot' => ['Zrób 5 000 PLN obrotu', 2,
            "SELECT CASE WHEN COALESCE(SUM(qty*price),0) >= 5000 THEN 1 ELSE 0 END FROM transactions WHERE (buyer_id=:uid OR seller_id=:uid) AND created_at LIKE :day"],
    ];

    public static function today(): string { return date('Y-m-d'); }

    /**
     * Pierwsza aktywność dnia: podbij serię i wypłać nagrodę. Wołane z _boot
     * dla zalogowanych graczy (strażnik w sesji PHP trzyma koszt przy zerze).
     */
    public static function touch(int $uid): void
    {
        try {
            $day = self::today();
            $st = Engine::row("SELECT * FROM daily_state WHERE user_id=?", [$uid]);
            if ($st && $st['last_day'] === $day) return;   // dziś już odhaczone

            $yesterday = date('Y-m-d', strtotime($day . ' -1 day'));
            $streak = ($st && $st['last_day'] === $yesterday) ? (int) $st['streak'] + 1 : 1;
            $pdo = Db::pdo();
            if ($st) $pdo->prepare("UPDATE daily_state SET last_day=?, streak=? WHERE user_id=?")->execute([$day, $streak, $uid]);
            else {
                try { $pdo->prepare("INSERT INTO daily_state (user_id, last_day, streak) VALUES (?,?,?)")->execute([$uid, $day, $streak]); }
                catch (\Throwable $e) { return; }   // wyścig dwóch żądań — drugie odpuszcza
            }
            $bonus = $streak % 7 === 0 ? self::STREAK_BONUS : 0;
            Tokens::grant($uid, self::STREAK_DAILY + $bonus, 'daily',
                "seria: dzień $streak" . ($bonus > 0 ? " (bonus tygodnia +$bonus)" : ''));
        } catch (\Throwable $e) { Log::write('warn', 'engine', 'daily.touch', $e->getMessage()); }
    }

    /** Trzy misje na dany dzień — deterministyczna rotacja wspólna dla wszystkich graczy. */
    public static function missionsFor(string $day): array
    {
        $codes = array_keys(self::MISSIONS);
        $seed = crc32('misje|' . $day);
        $picked = [];
        for ($i = 0; count($picked) < 3; $i++) {
            $c = $codes[($seed + $i * 7 + $i * $i) % count($codes)];
            if (!in_array($c, $picked, true)) $picked[] = $c;
        }
        return $picked;
    }

    /**
     * Stan misji gracza na dziś + automatyczna wypłata świeżo zaliczonych.
     * Zwraca listę [code, opis, tokeny, done].
     */
    public static function missions(int $uid): array
    {
        $day = self::today();
        $granted = Engine::col("SELECT code FROM daily_missions WHERE user_id=? AND day=?", [$uid, $day]);
        $out = [];
        foreach (self::missionsFor($day) as $code) {
            [$desc, $tokens, $sql] = self::MISSIONS[$code];
            $done = in_array($code, $granted, true);
            if (!$done) {
                try {
                    $st = Db::pdo()->prepare(str_replace([':uid', ':day'], ['?', '?'], $sql));
                    // kolejność placeholderów odpowiada kolejności :uid/:day w SQL
                    $args = [];
                    foreach (self::placeholderOrder($sql) as $ph) $args[] = $ph === ':uid' ? $uid : $day . '%';
                    $st->execute($args);
                    if ((int) $st->fetchColumn() > 0) {
                        Db::pdo()->prepare("INSERT INTO daily_missions (user_id, day, code, tokens, created_at) VALUES (?,?,?,?,?)")
                            ->execute([$uid, $day, $code, $tokens, Db::now()]);
                        Tokens::grant($uid, $tokens, 'daily', 'misja dnia: ' . $desc);
                        $done = true;
                    }
                } catch (\Throwable $e) { /* duplikat przy wyścigu = już wypłacone */ $done = true; }
            }
            $out[] = ['code' => $code, 'desc' => $desc, 'tokens' => $tokens, 'done' => $done];
        }
        return $out;
    }

    /** Kolejność wystąpień :uid/:day w SQL (żeby pozycyjne bindowanie było poprawne). */
    private static function placeholderOrder(string $sql): array
    {
        preg_match_all('/:(uid|day)/', $sql, $m);
        return array_map(fn($x) => ':' . $x, $m[1]);
    }

    /** Bieżąca seria gracza (0 gdy brak). */
    public static function streak(int $uid): int
    {
        $st = Engine::row("SELECT last_day, streak FROM daily_state WHERE user_id=?", [$uid]);
        if (!$st) return 0;
        $day = self::today();
        $yesterday = date('Y-m-d', strtotime($day . ' -1 day'));
        return in_array($st['last_day'], [$day, $yesterday], true) ? (int) $st['streak'] : 0;
    }
}
