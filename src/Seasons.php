<?php
/**
 * Sezon — ścieżki nagród przy seriach wyzwań (wzór: karnet sezonowy z gier F2P).
 *
 * Seria wyzwań (challenge_series, np. "Liga") = sezon. Każda rozstrzygnięta
 * edycja serii daje uczestnikom PUNKTY SEZONOWE wg miejsca. Punkty odblokowują
 * progi nagród: ścieżka DARMOWA (tokeny) dla wszystkich, ścieżka PREMIUM
 * (karnet za tokeny) z większymi nagrodami i tytułem "Legenda Sezonu" na końcu.
 * Karnet kupiony później wypłaca wstecz premium z już osiągniętych progów.
 * Zero pay-to-win: nagrody to tokeny i kosmetyka, nie PLN w grze.
 */
final class Seasons
{
    /** Cena karnetu premium (tokeny). */
    public const PASS_PRICE = 30;

    /** Progi nagród: [punkty, tokeny darmowe, tokeny premium]. Ostatni próg premium daje też tytuł. */
    public const MILESTONES = [
        [10,  2,  4],
        [25,  3,  6],
        [50,  5,  10],
        [90,  8,  15],
        [140, 12, 25],
    ];

    /** Przedmiot-nagroda za ostatni próg ścieżki premium (katalog w Cosmetics). */
    public const FINAL_ITEM = 'title_legenda';

    /** Punkty za miejsce w edycji: 1. = 25, 2. = 18, 3. = 14, płatne miejsca 10, udział 5. */
    public static function pointsFor(int $rank, int $players): int
    {
        if ($rank === 1) return 25;
        if ($rank === 2) return 18;
        if ($rank === 3) return 14;
        $paid = max(1, (int) round($players * 0.2));   // jak payoutSplit: top ~20%
        return $rank <= $paid ? 10 : 5;
    }

    /** Bieżący sezon = najnowsza aktywna seria wyzwań (GM tworzy serie w panelu). */
    public static function active(): ?array
    {
        return Engine::row("SELECT * FROM challenge_series WHERE enabled=1 ORDER BY id DESC LIMIT 1");
    }

    /** Postęp gracza w sezonie (wiersz season_progress albo null). */
    public static function progress(int $seriesId, int $uid): ?array
    {
        return Engine::row("SELECT * FROM season_progress WHERE series_id=? AND user_id=?", [$seriesId, $uid]);
    }

    /** Dopisz punkty za rozstrzygniętą edycję i wypłać osiągnięte progi. */
    public static function addPoints(int $seriesId, int $uid, int $pts, string $note): void
    {
        if ($pts <= 0) return;
        try {
            $pdo = Db::pdo();
            try {
                $pdo->prepare("INSERT INTO season_progress (series_id, user_id, points, premium, granted_upto) VALUES (?,?,0,0,0)")
                    ->execute([$seriesId, $uid]);
            } catch (Throwable $e) { /* wiersz już jest */ }
            $pdo->prepare("UPDATE season_progress SET points = points + ? WHERE series_id=? AND user_id=?")
                ->execute([$pts, $seriesId, $uid]);
            $row = self::progress($seriesId, $uid);
            Engine::journal($uid, 'challenge', "🏁 +$pts pkt sezonowych — $note (razem: {$row['points']} pkt).", 'sezon.php');
            self::grantMilestones($row);
        } catch (Throwable $e) { Log::write('warn', 'engine', 'season.points', $e->getMessage()); }
    }

    /** Kup karnet premium (tokeny) — wypłaca wstecz premium z już osiągniętych progów. */
    public static function buyPass(int $uid, int $seriesId): array
    {
        $s = Engine::row("SELECT * FROM challenge_series WHERE id=?", [$seriesId]);
        if (!$s) return [false, 'Nie ma takiego sezonu.'];
        $pdo = Db::pdo();
        try {
            $pdo->prepare("INSERT INTO season_progress (series_id, user_id, points, premium, granted_upto) VALUES (?,?,0,0,0)")
                ->execute([$seriesId, $uid]);
        } catch (Throwable $e) { /* wiersz już jest */ }
        $row = self::progress($seriesId, $uid);
        if ((int) $row['premium'] === 1) return [false, 'Masz już karnet tego sezonu.'];
        [$ok, $msg] = Tokens::spend($uid, self::PASS_PRICE, 'season', 'Karnet: ' . $s['name']);
        if (!$ok) return [false, $msg];
        $pdo->prepare("UPDATE season_progress SET premium=1 WHERE series_id=? AND user_id=?")->execute([$seriesId, $uid]);
        // retro-wypłata ścieżki premium za progi wypłacone przed zakupem karnetu
        $granted = (int) $row['granted_upto'];
        for ($i = 0; $i < $granted && $i < count(self::MILESTONES); $i++) {
            [$need, , $prem] = self::MILESTONES[$i];
            Tokens::grant($uid, $prem, 'season', "karnet: próg $need pkt (wstecz)");
            if ($i === count(self::MILESTONES) - 1) Cosmetics::give($uid, self::FINAL_ITEM);
        }
        Engine::journal($uid, 'token', '🏁 Kupiono karnet sezonu: ' . $s['name'] . '.', 'sezon.php');
        Log::write('info', 'player', 'season.pass', $s['name'] . " — karnet dla #$uid");
        return [true, 'Karnet aktywny! Ścieżka premium odblokowana' . ($granted > 0 ? ' (nagrody wstecz wypłacone)' : '') . '.'];
    }

    /** Wypłać wszystkie osiągnięte, a jeszcze nie wypłacone progi nagród. */
    private static function grantMilestones(array $row): void
    {
        $uid = (int) $row['user_id'];
        $premium = (int) $row['premium'] === 1;
        $granted = (int) $row['granted_upto'];
        $points = (int) $row['points'];
        foreach (self::MILESTONES as $i => [$need, $free, $prem]) {
            if ($i < $granted || $points < $need) continue;
            Tokens::grant($uid, $free, 'season', "sezon: próg $need pkt");
            if ($premium) {
                Tokens::grant($uid, $prem, 'season', "karnet: próg $need pkt");
                if ($i === count(self::MILESTONES) - 1) Cosmetics::give($uid, self::FINAL_ITEM);
            }
            $granted = $i + 1;
        }
        if ($granted !== (int) $row['granted_upto']) {
            Db::pdo()->prepare("UPDATE season_progress SET granted_upto=? WHERE id=?")->execute([$granted, $row['id']]);
        }
    }

    /** Tabela sezonu (top N wg punktów). */
    public static function standings(int $seriesId, int $limit = 15): array
    {
        return Engine::all(
            "SELECT sp.points, sp.premium, u.id AS uid, u.username, u.title
             FROM season_progress sp JOIN users u ON u.id = sp.user_id
             WHERE sp.series_id=? ORDER BY sp.points DESC, sp.id ASC LIMIT " . (int) $limit, [$seriesId]);
    }
}
