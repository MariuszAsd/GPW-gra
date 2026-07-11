<?php
/**
 * Wyzwania inwestycyjne (konkursy na N sesji).
 *
 * Zasady: gracz wpłaca buy-in + wpisowe (fee_pct% buy-inu). Buy-in trafia na
 * ODDZIELNE subkonto wyzwania (użytkownik-cień, role='challenger'), którym gracz
 * handluje w tym samym arkuszu co wszyscy. Wpisowe zbiera się w puli (pot) i po
 * ostatniej sesji dzielą je najlepsi — przegrani realnie finansują wygranych,
 * ale maksymalna strata jest znana z góry. Ekonomia gry pozostaje zamknięta:
 * żadna złotówka nie powstaje z powietrza.
 *
 * Cykl życia (haki w Engine::rollSession): signup -> running (start_session)
 * -> finished (po end_session). Za mało chętnych => cancelled + pełny zwrot.
 */
final class Challenges
{
    /** Domyślne parametry edycji (GM może nadpisać przy tworzeniu). */
    public const DEFAULTS = [
        'buyin'       => 20000.0,
        'fee_pct'     => 10.0,
        'signup_sess' => 2,     // ile sesji trwają zapisy
        'duration'    => 14,    // ile sesji trwa handel
        'min_players' => 3,
    ];

    /**
     * Podział puli SKALUJE SIĘ z liczbą uczestników: nagradzane top ~20% miejsc
     * (min. 1), udziały maleją geometrycznie — im wyższe miejsce, tym większy
     * kawałek puli. 10 graczy => 2 płatne miejsca, 10 000 => 2 000.
     * Zwraca [udział % miejsca 1., 2., ...] (suma = 100).
     */
    public static function payoutSplit(int $players): array
    {
        $places = max(1, (int) round($players * 0.2));
        $w = []; $sum = 0.0;
        for ($i = 0; $i < $places; $i++) { $w[$i] = 0.7 ** $i; $sum += $w[$i]; }
        return array_map(fn($x) => $x / $sum * 100, $w);
    }

    /** Wszystkie otwarte edycje (zapisy + trwające) — może ich być kilka naraz o różnych stawkach. */
    public static function activeAll(): array
    {
        return Engine::all("SELECT * FROM challenges WHERE status IN ('signup','running') ORDER BY buyin ASC, id ASC");
    }

    /** Wpisy gracza w nie-zakończonych wyzwaniach (może grać w kilku naraz). */
    public static function entriesFor(int $userId): array
    {
        return Engine::all(
            "SELECT cp.*, c.status AS ch_status, c.name AS ch_name, c.buyin AS ch_buyin,
                    c.start_session, c.end_session
             FROM challenge_players cp JOIN challenges c ON c.id = cp.challenge_id
             WHERE cp.user_id = ? AND c.status IN ('signup','running')", [$userId]);
    }

    /** Nowa edycja. $opts nadpisuje DEFAULTS; $session = bieżąca sesja. */
    public static function create(?array $opts, int $session): int
    {
        $o = array_merge(self::DEFAULTS, $opts ?? []);
        $nr = (int) Engine::one("SELECT COUNT(*) FROM challenges") + 1;
        $name = trim((string) ($o['name'] ?? '')) !== '' ? trim($o['name']) : "Wyzwanie #$nr";
        $start = $session + max(1, (int) $o['signup_sess']);
        $end   = $start + max(1, (int) $o['duration']) - 1;
        Db::pdo()->prepare("INSERT INTO challenges (name, status, buyin, fee_pct, pot, min_players, start_session, end_session, created_at)
                            VALUES (?,?,?,?,0,?,?,?,?)")
            ->execute([mb_substr($name, 0, 80), 'signup', round((float) $o['buyin'], 2),
                       max(0.0, min(50.0, (float) $o['fee_pct'])), max(2, (int) $o['min_players']), $start, $end, Db::now()]);
        $cid = (int) Db::pdo()->lastInsertId();
        Log::write('info', 'engine', 'challenge.create', "$name: zapisy do sesji $start, handel do sesji $end", ['buyin' => $o['buyin']]);
        foreach (Engine::all("SELECT id FROM users WHERE is_bot=0 AND role='player'") as $p) {
            Engine::notify((int) $p['id'], 'challenge',
                "⚔️ Ruszyły zapisy: $name! Buy-in " . number_format((float) $o['buyin'], 0, ',', ' ')
                . " PLN + wpisowe, handel przez " . (int) $o['duration'] . " sesji. Zapisy do sesji #$start.", 'wyzwania.php');
        }
        return $cid;
    }

    /** Zapis gracza: pobiera buy-in + wpisowe z konta głównego (atomowo). */
    public static function join(int $userId, int $challengeId): array
    {
        $ch = Engine::row("SELECT * FROM challenges WHERE id=? AND status='signup'", [$challengeId]);
        if (!$ch) return [false, 'Zapisy na to wyzwanie są zamknięte.'];
        [$session] = Engine::sessionInfo();
        if ($session >= (int) $ch['start_session']) return [false, 'Zapisy właśnie się skończyły — wyzwanie startuje.'];
        $u = Engine::row("SELECT id, role, cash FROM users WHERE id=?", [$userId]);
        if (!$u || $u['role'] !== 'player') return [false, 'Tylko gracze mogą brać udział w wyzwaniach.'];

        $buyin = round((float) $ch['buyin'], 2);
        $fee   = round($buyin * (float) $ch['fee_pct'] / 100, 2);
        $total = round($buyin + $fee, 2);

        $pdo = Db::pdo();
        // pobranie środków atomowo — warunek cash >= total w UPDATE chroni przed podwójnym kliknięciem
        $st = $pdo->prepare("UPDATE users SET cash = cash - ? WHERE id = ? AND cash >= ?");
        $st->execute([$total, $userId, $total]);
        if ($st->rowCount() === 0) return [false, 'Za mało gotówki: potrzeba ' . number_format($total, 2, ',', ' ') . ' PLN (buy-in + wpisowe).'];
        try {
            $pdo->prepare("INSERT INTO challenge_players (challenge_id, user_id, buyin, fee, joined_at) VALUES (?,?,?,?,?)")
                ->execute([$challengeId, $userId, $buyin, $fee, Db::now()]);
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$total, $userId]);  // zwrot przy duplikacie
            return [false, 'Już jesteś zapisany na to wyzwanie.'];
        }
        $pdo->prepare("UPDATE challenges SET pot = pot + ? WHERE id = ?")->execute([$fee, $challengeId]);
        Log::write('info', 'player', 'challenge.join', "zapis do wyzwania #$challengeId", ['user_id' => $userId, 'buyin' => $buyin, 'fee' => $fee]);
        Engine::notify($userId, 'challenge', '⚔️ Zapisano do: ' . $ch['name'] . '. Zablokowano ' . number_format($total, 2, ',', ' ')
            . ' PLN (buy-in ' . number_format($buyin, 0, ',', ' ') . ' + wpisowe ' . number_format($fee, 0, ',', ' ') . ').', 'wyzwania.php');
        return [true, 'Zapisano! Zablokowano ' . number_format($total, 2, ',', ' ') . ' PLN. Start: sesja #' . (int) $ch['start_session'] . '.'];
    }

    /** Odwołanie wyzwania (GM lub za mało chętnych) — pełny zwrot buy-in + wpisowe. */
    public static function cancel(int $challengeId, string $why): void
    {
        $ch = Engine::row("SELECT * FROM challenges WHERE id=? AND status IN ('signup','running')", [$challengeId]);
        if (!$ch) return;
        $pdo = Db::pdo();
        foreach (Engine::all("SELECT * FROM challenge_players WHERE challenge_id=?", [$challengeId]) as $cp) {
            if (!empty($cp['shadow_user_id'])) {
                self::settlePlayer($ch, $cp);                                       // subkonto (buy-in w obecnej formie) wraca w całości
                $refund = round((float) $cp['fee'], 2);                             // do zwrotu zostaje samo wpisowe
            } else {
                $refund = round((float) $cp['buyin'] + (float) $cp['fee'], 2);      // przed startem: pełny zwrot
            }
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$refund, $cp['user_id']]);
            Engine::notify((int) $cp['user_id'], 'challenge', '⚔️ Wyzwanie „' . $ch['name'] . "” odwołane ($why). Środki wróciły na konto.", 'wyzwania.php');
        }
        $pdo->prepare("UPDATE challenges SET status='cancelled', pot=0 WHERE id=?")->execute([$challengeId]);
        Log::write('warn', 'engine', 'challenge.cancel', $ch['name'] . " odwołane: $why", []);
    }

    /** Hak wołany przy zmianie sesji (z Engine::rollSession). */
    public static function onRoll(int $session, int $tick): void
    {
        foreach (Engine::all("SELECT * FROM challenges WHERE status='signup' AND start_session <= ?", [$session]) as $ch) {
            $players = Engine::all("SELECT cp.*, u.username FROM challenge_players cp JOIN users u ON u.id=cp.user_id WHERE cp.challenge_id=?", [$ch['id']]);
            if (count($players) >= (int) $ch['min_players']) self::start($ch, $players, $session, $tick);
            else self::cancel((int) $ch['id'], 'za mało uczestników — minimum ' . (int) $ch['min_players']);
        }
        foreach (Engine::all("SELECT * FROM challenges WHERE status='running' AND end_session < ?", [$session]) as $ch) {
            self::finish($ch, $tick);
        }
        // serie cykliczne (np. liga co N sesji): każda otwiera zapisy nowej edycji wg własnego rytmu
        foreach (Engine::all("SELECT * FROM challenge_series WHERE enabled=1 AND next_session <= ?", [$session]) as $s) {
            $n = (int) $s['editions'] + 1;
            self::create([
                'name'        => $s['name'] . ' #' . $n,
                'buyin'       => (float) $s['buyin'],
                'fee_pct'     => (float) $s['fee_pct'],
                'signup_sess' => (int) $s['signup_sess'],
                'duration'    => (int) $s['duration'],
                'min_players' => (int) $s['min_players'],
            ], $session);
            Db::pdo()->prepare("UPDATE challenge_series SET editions=?, next_session=? WHERE id=?")
                ->execute([$n, $session + max(1, (int) $s['every_sessions']), $s['id']]);
        }

        // automatyczna kolejna edycja, gdy nie ma ŻADNEJ otwartej (GM może wyłączyć w panelu;
        // ręcznie utworzone edycje i serie żyją równolegle i wstrzymują automat)
        $auto = Engine::one("SELECT v FROM game_state WHERE k='challenge_auto'");
        $autoOn = ($auto === false || $auto === null) ? true : ((int) $auto === 1);
        if ($autoOn && !self::activeAll()) self::create(null, $session);
    }

    /** Start: subkonto (użytkownik-cień) z buy-inem dla każdego uczestnika. */
    private static function start(array $ch, array $players, int $session, int $tick): void
    {
        $pdo = Db::pdo();
        $cid = (int) $ch['id'];
        // gdyby świat przeskoczył kilka sesji — wyrównaj okno handlu do pełnej długości
        $dur = (int) $ch['end_session'] - (int) $ch['start_session'];
        $end = $session + $dur;
        foreach ($players as $cp) {
            $base = mb_substr((string) $cp['username'], 0, 40) . '~w' . $cid;
            try {
                $pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash, cash_reserved, joined_session, start_equity)
                               VALUES (?, '!', 1, 'challenger', ?, 0, ?, ?)")
                    ->execute([$base, round((float) $cp['buyin'], 2), $session, round((float) $cp['buyin'], 2)]);
            } catch (Throwable $e) {   // kolizja nazwy (teoretyczna) — dopisz id wpisu
                $base .= '_' . (int) $cp['id'];
                $pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash, cash_reserved, joined_session, start_equity)
                               VALUES (?, '!', 1, 'challenger', ?, 0, ?, ?)")
                    ->execute([$base, round((float) $cp['buyin'], 2), $session, round((float) $cp['buyin'], 2)]);
            }
            $sid = (int) $pdo->lastInsertId();
            $pdo->prepare("UPDATE challenge_players SET shadow_user_id=? WHERE id=?")->execute([$sid, $cp['id']]);
            Engine::notify((int) $cp['user_id'], 'challenge', '⚔️ ' . $ch['name'] . ' WYSTARTOWAŁO! Handlujesz portfelem '
                . number_format((float) $cp['buyin'], 0, ',', ' ') . ' PLN do końca sesji #' . $end . '. Powodzenia!', 'wyzwania.php');
        }
        $pdo->prepare("UPDATE challenges SET status='running', start_session=?, end_session=? WHERE id=?")->execute([$session, $end, $cid]);
        $pot = (float) Engine::one("SELECT pot FROM challenges WHERE id=?", [$cid]);
        $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                       VALUES (?,?,'POS','MARKET',NULL,0,0,?,?,?)")
            ->execute(['⚔️ ' . $ch['name'] . ' wystartowało — ' . count($players) . ' inwestorów walczy o pulę ' . number_format($pot, 0, ',', ' ') . ' PLN',
                       'Konkurs trwa do końca sesji #' . $end . '. Tabela wyników na żywo w zakładce Wyzwania.',
                       $tick, $tick + 30, Db::now()]);
        Log::write('info', 'engine', 'challenge.start', $ch['name'] . ': ' . count($players) . ' graczy, pula ' . $pot, []);
    }

    /** Bieżąca tabela wyników trwającego wyzwania (equity subkont, malejąco). */
    public static function leaderboard(int $challengeId): array
    {
        return Engine::all(
            "SELECT cp.user_id, cp.buyin, cp.shadow_user_id, cp.final_equity, cp.final_rank, cp.prize, u.username,
                    ROUND(su.cash + su.cash_reserved + COALESCE(sv.v, 0), 2) AS equity
             FROM challenge_players cp
             JOIN users u  ON u.id  = cp.user_id
             JOIN users su ON su.id = cp.shadow_user_id
             LEFT JOIN (SELECT w.user_id AS uid, SUM((w.qty + w.qty_reserved) * s.price) AS v
                        FROM wallets w JOIN stocks s ON s.id = w.stock_id GROUP BY w.user_id) sv ON sv.uid = su.id
             WHERE cp.challenge_id = ?
             ORDER BY equity DESC, cp.id ASC", [$challengeId]);
    }

    /** Likwidacja subkonta: zwrot gotówki i przeniesienie akcji na konto główne. Zwraca equity końcowe. */
    private static function settlePlayer(array $ch, array $cp): float
    {
        $pdo = Db::pdo();
        $sid = (int) $cp['shadow_user_id'];
        $uid = (int) $cp['user_id'];
        Engine::releaseAllOrders($sid);   // anuluje zlecenia (też obronne SL/TP) i zwalnia rezerwacje
        $cash = (float) Engine::one("SELECT cash + cash_reserved FROM users WHERE id=?", [$sid]);
        $stockVal = 0.0;
        foreach (Engine::all("SELECT w.stock_id, w.qty, w.qty_reserved, w.avg_price, s.price
                              FROM wallets w JOIN stocks s ON s.id=w.stock_id
                              WHERE w.user_id=? AND (w.qty > 0 OR w.qty_reserved > 0)", [$sid]) as $w) {
            $q = (int) $w['qty'] + (int) $w['qty_reserved'];
            $stockVal += $q * (float) $w['price'];
            Engine::ensureWallet($uid, (int) $w['stock_id']);
            $own = Engine::row("SELECT qty, avg_price FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $w['stock_id']]);
            $newQty = (int) $own['qty'] + $q;
            $newAvg = $newQty > 0 ? round(((int) $own['qty'] * (float) $own['avg_price'] + $q * (float) $w['avg_price']) / $newQty, 4) : 0;
            $pdo->prepare("UPDATE wallets SET qty=?, avg_price=? WHERE user_id=? AND stock_id=?")->execute([$newQty, $newAvg, $uid, $w['stock_id']]);
        }
        $pdo->prepare("DELETE FROM wallets WHERE user_id=?")->execute([$sid]);
        $pdo->prepare("UPDATE users SET cash=cash+? WHERE id=?")->execute([round($cash, 2), $uid]);
        $pdo->prepare("UPDATE users SET cash=0, cash_reserved=0 WHERE id=?")->execute([$sid]);
        return round($cash + $stockVal, 2);
    }

    /** Finał: ranking po equity, wypłata puli, przeniesienie środków, odznaka i ogłoszenie. */
    private static function finish(array $ch, int $tick): void
    {
        $pdo = Db::pdo();
        $cid = (int) $ch['id'];
        $rows = Engine::all("SELECT cp.*, u.username FROM challenge_players cp JOIN users u ON u.id=cp.user_id WHERE cp.challenge_id=?", [$cid]);
        $results = [];
        foreach ($rows as $cp) {
            $eq = !empty($cp['shadow_user_id']) ? self::settlePlayer($ch, $cp) : 0.0;
            $results[] = ['cp' => $cp, 'equity' => $eq];
        }
        usort($results, fn($a, $b) => $b['equity'] <=> $a['equity']);

        $pot   = round((float) $ch['pot'], 2);
        $split = self::payoutSplit(count($results));
        $paid  = 0.0;
        $podium = [];
        foreach ($results as $i => $r) {
            $rank  = $i + 1;
            $cp    = $r['cp'];
            $prize = 0.0;
            if (isset($split[$i])) {
                // ostatnie premiowane miejsce dostaje resztę puli — bez gubienia groszy
                $prize = ($i === count($split) - 1) ? round($pot - $paid, 2) : round($pot * $split[$i] / 100, 2);
                $paid  = round($paid + $prize, 2);
            }
            $pdo->prepare("UPDATE challenge_players SET final_equity=?, final_rank=?, prize=? WHERE id=?")
                ->execute([$r['equity'], $rank, $prize, $cp['id']]);
            if ($prize > 0) $pdo->prepare("UPDATE users SET cash=cash+? WHERE id=?")->execute([$prize, $cp['user_id']]);
            $ret = (float) $cp['buyin'] > 0 ? ($r['equity'] / (float) $cp['buyin'] - 1) * 100 : 0;
            $msg = '⚔️ ' . $ch['name'] . ' zakończone! Miejsce ' . $rank . '/' . count($results)
                 . ', wynik ' . number_format($ret, 1, ',', ' ') . '%'
                 . ($prize > 0 ? ', nagroda ' . number_format($prize, 2, ',', ' ') . ' PLN 🏆' : '')
                 . '. Akcje i gotówka wróciły na konto główne.';
            Engine::notify((int) $cp['user_id'], 'challenge', $msg, 'wyzwania.php');
            if ($rank <= 3) $podium[] = $rank . '. ' . $cp['username'] . ' (' . number_format($ret, 1, ',', ' ') . '%)';
            if ($rank === 1) Engine::award((int) $cp['user_id'], 'zwyciezca_wyzwania');
            // Żetony Maklera za podium (monetyzacja zdobywalna grą, nie tylko portfelem)
            if (!class_exists('Tokens')) require_once __DIR__ . '/Tokens.php';
            if ($rank === 1) Tokens::grant((int) $cp['user_id'], 10, 'challenge', 'wygrana: ' . $ch['name']);
            elseif ($rank <= 3) Tokens::grant((int) $cp['user_id'], 5, 'challenge', 'podium: ' . $ch['name']);
        }
        $pdo->prepare("UPDATE challenges SET status='finished', pot=0 WHERE id=?")->execute([$cid]);
        $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                       VALUES (?,?,'POS','MARKET',NULL,0,0,?,?,?)")
            ->execute(['🏆 ' . $ch['name'] . ' rozstrzygnięte! Wygrywa ' . ($results[0]['cp']['username'] ?? '—'),
                       'Podium: ' . implode(' · ', $podium) . '. Pula ' . number_format($pot, 0, ',', ' ') . ' PLN wypłacona.',
                       $tick, $tick + 30, Db::now()]);
        Log::write('info', 'engine', 'challenge.finish', $ch['name'] . ' rozstrzygnięte', ['podium' => $podium, 'pot' => $pot]);
    }
}
