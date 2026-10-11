<?php
/**
 * „Twój tydzień w Maklerii” — tygodniowe podsumowanie gracza + publiczna karta wyniku.
 *
 * Po zamknięciu tygodnia (rozliczenie ligi tygodnia w Engine::settleLeague) każdy gracz dostaje
 * powiadomienie w grze, a kto podał e-mail i nie wyłączył raportu (users.weekly_mail) — także e-mail
 * tekstowy (Mailer). Karta wyniku (public/karta.php?u=TOKEN) jest publiczna: nie wymaga logowania,
 * pokazuje tylko to, co widać w rankingu (nick, stopy zwrotu, drabinka, odznaki) i kończy się
 * zaproszeniem do gry z linkiem polecającym gracza — to kanał wzrostu, nie tylko chwalenie się.
 * Token karty (users.share_token) jest losowy, ale z założenia PUBLICZNY (gracz podsyła go znajomym), więc NIE
 * służy do wypisania się z e-maili: link wypisu ma osobny token HMAC (offToken) i wymaga potwierdzenia POST-em.
 */
final class Weekly
{
    /** Token karty wyniku gracza — generowany leniwie przy pierwszym użyciu. */
    public static function shareToken(int $uid): string
    {
        $t = Engine::one("SELECT share_token FROM users WHERE id=?", [$uid]);
        if ($t !== false && $t !== null && $t !== '') return (string) $t;
        $t = bin2hex(random_bytes(16));
        Db::pdo()->prepare("UPDATE users SET share_token=? WHERE id=? AND (share_token IS NULL OR share_token='')")->execute([$t, $uid]);
        return (string) (Engine::one("SELECT share_token FROM users WHERE id=?", [$uid]) ?: $t);
    }

    public static function appUrl(): string
    {
        $cfg = require __DIR__ . '/../config.php';
        return rtrim((string) ($cfg['app_url'] ?? ''), '/');
    }

    public static function cardUrl(int $uid): string
    {
        return self::appUrl() . '/karta.php?u=' . self::shareToken($uid);
    }

    /** Token wypisu z e-maili: HMAC z sekretu aplikacji, zależny od gracza i jego tokenu karty — publiczny link karty go nie zdradza. */
    public static function offToken(int $uid): string
    {
        return substr(hash_hmac('sha256', 'weekly-off|' . $uid . '|' . self::shareToken($uid), Engine::appSecret()), 0, 32);
    }

    /**
     * Dane tygodnia $period (np. '2026-W38') dla gracza: migawka na starcie tygodnia vs migawka na starcie
     * następnego (obie robi rolka sesji), miejsce w lidze, indeks w tym czasie, transakcje i odznaki z tygodnia.
     * Zwraca null, gdy gracz nie ma migawki startowej (dołączył później) — wtedy nie ma czego podsumować.
     */
    public static function summary(int $uid, string $period, ?string $nextPeriod = null): ?array
    {
        $u = Engine::row("SELECT id, username, email, weekly_mail, start_equity, ref_code FROM users WHERE id=? AND is_bot=0 AND role='player'", [$uid]);
        if (!$u) return null;
        $start = Engine::row("SELECT equity, index_value, created_at FROM equity_snapshots WHERE user_id=? AND kind='week' AND period=?", [$uid, $period]);
        if (!$start || (float) $start['equity'] <= 0) return null;
        $end = $nextPeriod !== null
            ? Engine::row("SELECT equity, index_value, created_at FROM equity_snapshots WHERE user_id=? AND kind='week' AND period=?", [$uid, $nextPeriod])
            : null;
        $eqEnd = $end ? (float) $end['equity'] : Engine::playerEquity($uid);
        $idxEnd = $end && $end['index_value'] !== null ? (float) $end['index_value'] : Engine::indexValue();
        $till = $end ? (string) $end['created_at'] : Db::now();
        $ret = ($eqEnd / (float) $start['equity'] - 1) * 100;
        $idxRet = ($start['index_value'] !== null && (float) $start['index_value'] > 0) ? ($idxEnd / (float) $start['index_value'] - 1) * 100 : null;
        $rank = Engine::row("SELECT rank, prize, tokens FROM league_results WHERE kind='week' AND period=? AND user_id=?", [$period, $uid]);
        $n = (int) Engine::one("SELECT COUNT(*) FROM league_results WHERE kind='week' AND period=?", [$period]);
        $trades = (int) Engine::one("SELECT (SELECT COUNT(*) FROM transactions WHERE buyer_id=? AND created_at >= ? AND created_at < ?) + (SELECT COUNT(*) FROM transactions WHERE seller_id=? AND created_at >= ? AND created_at < ?)",
            [$uid, $start['created_at'], $till, $uid, $start['created_at'], $till]);
        $badges = Engine::col("SELECT code FROM achievements WHERE user_id=? AND earned_at >= ? AND earned_at < ?", [$uid, $start['created_at'], $till]);
        $allRet = (float) $u['start_equity'] > 0 ? (Engine::playerEquity($uid) / (float) $u['start_equity'] - 1) * 100 : 0.0;
        return [
            'uid' => $uid, 'username' => $u['username'], 'email' => (string) ($u['email'] ?? ''), 'weekly_mail' => (int) $u['weekly_mail'] === 1,
            'period' => $period, 'equity_start' => (float) $start['equity'], 'equity_end' => $eqEnd, 'ret' => $ret,
            'index_ret' => $idxRet, 'alpha' => $idxRet === null ? null : $ret - $idxRet,
            'rank' => $rank ? (int) $rank['rank'] : null, 'players' => $n, 'prize' => $rank ? (float) $rank['prize'] : 0.0, 'tokens' => $rank ? (int) $rank['tokens'] : 0,
            'trades' => $trades, 'badges' => $badges, 'all_ret' => $allRet, 'rung' => Engine::ladderRung($allRet),
        ];
    }

    private static function pct(?float $v, int $dec = 1): string
    {
        if ($v === null) return '—';
        return ($v >= 0 ? '+' : '') . number_format($v, $dec, ',', ' ') . '%';
    }

    /** Treść e-maila (tekst) — używana też w teście QA. */
    public static function mailBody(array $s): string
    {
        $card = self::cardUrl((int) $s['uid']);
        $lines = [];
        $lines[] = "Cześć {$s['username']},";
        $lines[] = '';
        $lines[] = "Tydzień {$s['period']} w Maklerii: " . self::pct($s['ret']) . ' (kapitał ' . number_format($s['equity_start'], 0, ',', ' ') . ' → ' . number_format($s['equity_end'], 0, ',', ' ') . ' PLN).';
        if ($s['index_ret'] !== null) $lines[] = 'Indeks MAK40 w tym czasie: ' . self::pct($s['index_ret']) . ' — ' . ($s['alpha'] >= 0 ? 'pobiłeś rynek o ' : 'rynek był lepszy o ') . number_format(abs($s['alpha']), 1, ',', ' ') . ' pkt proc.';
        if ($s['rank'] !== null) $lines[] = "Liga tygodnia: {$s['rank']}. miejsce na {$s['players']}" . ($s['prize'] > 0 ? ' — nagroda ' . number_format($s['prize'], 0, ',', ' ') . ' PLN ze skarbca gry' : '') . '.';
        else $lines[] = 'Liga tygodnia: bez klasyfikacji (liczą się gracze z choć jedną transakcją w tygodniu).';
        $lines[] = "Transakcji w tygodniu: {$s['trades']}.";
        if ($s['badges']) {
            $names = array_map(fn($c) => (Achievements::get($c)[1] ?? $c), $s['badges']);
            $lines[] = 'Nowe odznaki: ' . implode(', ', $names) . '.';
        }
        $lines[] = 'Od startu: ' . self::pct($s['all_ret']) . ($s['rung'] ? ' — szczebel drabinki ' . (Achievements::get($s['rung'][2])[1] ?? '+' . $s['rung'][0] . '%') : '') . '.';
        $lines[] = '';
        $lines[] = 'Twoja karta wyniku (możesz ją podesłać znajomym — każdy, kto dołączy z niej, to Twoje polecenie):';
        $lines[] = $card;
        $lines[] = '';
        $lines[] = 'Zagraj dalej: ' . self::appUrl() . '/pulpit.php';
        $lines[] = '';
        $lines[] = 'Nie chcesz tych raportów? Wyłącz je tutaj (poprosimy o jedno potwierdzenie): ' . $card . '&off=1&t=' . self::offToken((int) $s['uid']);
        return implode("\n", $lines);
    }

    /** Po zamknięciu tygodnia: powiadomienie w grze dla każdego + e-mail dla tych, którzy chcą. Zwraca [powiadomienia, maile]. */
    public static function sendAll(string $period, string $nextPeriod): array
    {
        if (!class_exists('Mailer')) require_once __DIR__ . '/Mailer.php';
        if (!class_exists('Achievements')) require_once __DIR__ . '/Achievements.php';
        $n = 0; $m = 0;
        foreach (Engine::col("SELECT id FROM users WHERE is_bot=0 AND role='player'") as $uid) {
            try {
                $s = self::summary((int) $uid, $period, $nextPeriod);
                if ($s === null) continue;
                $short = 'Tydzień ' . $period . ': ' . self::pct($s['ret'])
                       . ($s['index_ret'] !== null ? ' (MAK40 ' . self::pct($s['index_ret']) . ')' : '')
                       . ($s['rank'] !== null ? " · {$s['rank']}. miejsce w lidze tygodnia" : '') . '. Twoja karta wyniku do podesłania znajomym →';
                Engine::notify((int) $uid, 'system', '📬 ' . $short, 'konto.php#karta');
                $n++;
                if ($s['email'] !== '' && $s['weekly_mail']) {
                    if (Mailer::send($s['email'], 'Twój tydzień w Maklerii — ' . $period . ': ' . self::pct($s['ret']), self::mailBody($s))) $m++;
                }
            } catch (\Throwable $e) { Log::write('warn', 'engine', 'weekly.fail', "gracz #$uid: " . $e->getMessage()); }
        }
        Log::write('info', 'engine', 'weekly.sent', "tydzień $period: $n powiadomień, $m e-maili");
        return [$n, $m];
    }

    /**
     * Wysyłka ODŁOŻONA: tick zapisuje tylko znacznik `weekly_pending` = „poprzedni|bieżący” okres (w swojej transakcji),
     * a wysyłkę robi ten krok — z cron/tick.php i gm.php PO tickach, poza transakcją i poza blokadą świata.
     * Dlaczego: e-maile idą przez mail() hostingu (sekundy), a wysyłka wewnątrz ticka trzymała przez ten czas blokady
     * wierszy i — po wycofanym ticku — szła drugi raz z następnego ticka (gracz dostawał dwa identyczne raporty).
     * Znacznik przejmujemy claim-first (UPDATE ... WHERE v = odczytana wartość, rowCount === 1), więc dwa równoległe
     * procesy nie wyślą tego samego tygodnia dwa razy. Zwraca [powiadomienia, maile] albo [0, 0], gdy nie było nic do wysłania.
     */
    public static function flushPending(): array
    {
        $pending = Engine::one("SELECT v FROM game_state WHERE k='weekly_pending'");
        if ($pending === false || $pending === null || $pending === '' || strpos((string) $pending, '|') === false) return [0, 0];
        $claim = Db::pdo()->prepare("UPDATE game_state SET v='' WHERE k='weekly_pending' AND v=?");
        $claim->execute([(string) $pending]);
        if ($claim->rowCount() !== 1) return [0, 0];   // ktoś inny właśnie przejął ten tydzień
        [$prev, $cur] = explode('|', (string) $pending, 2);
        return self::sendAll($prev, $cur);
    }
}
