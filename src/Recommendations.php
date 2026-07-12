<?php
/**
 * Rekomendacje domu maklerskiego "DM Makleria".
 *
 * Na otwarciu każdej sesji analitycy wydają rekomendacje dla ~5 spółek
 * (rotacja deterministyczna — każda spółka wraca regularnie): werdykt
 * kupuj/trzymaj/sprzedaj + cena docelowa z wyceny fundamentalnej
 * (C/Z docelowe x EPS) z lekką domieszką sygnału technicznego.
 *
 * MONETYZACJA (jak w prawdziwych DM): posiadacze Pakietu Analityka widzą
 * rekomendacje NATYCHMIAST po publikacji; pozostali gracze — od NASTĘPNEJ
 * sesji (dzień później). Rekomendacje bywają trafne, nie nieomylne —
 * to wskazówka, nie wyrocznia.
 */
final class Recommendations
{
    /** Hak na granicy sesji: publikacja rekomendacji dla nowej sesji. */
    public static function onRoll(int $session, int $tick): void
    {
        if (!class_exists('Technical')) require_once __DIR__ . '/Technical.php';
        $stocks = Engine::all("SELECT id, ticker, price, pe_target, last_eps, tech_affinity FROM stocks ORDER BY id");
        if (!$stocks) return;
        $step = max(1, (int) ceil(count($stocks) / 5));   // ~5 spółek na sesję, pełna rotacja co ~step sesji
        $ins = Db::pdo()->prepare("INSERT INTO recommendations (stock_id, session, verdict, target_price, note, created_at) VALUES (?,?,?,?,?,?)");
        foreach ($stocks as $i => $st) {
            if (($i + $session) % $step !== 0) continue;
            $price = (float) $st['price'];
            $fair = (float) $st['pe_target'] * (float) $st['last_eps'];
            if ($fair <= 0 || $price <= 0) continue;
            $ta = Technical::composite((int) $st['id']);
            $target = round($fair * (1 + 0.06 * $ta * (float) $st['tech_affinity']), 2);   // lekka domieszka AT
            $upside = ($target / $price - 1) * 100;
            if ($upside >= 8)       { $verdict = 'kupuj';    $note = 'Wycena wskazuje potencjał wzrostu ' . number_format($upside, 0) . '%. '; }
            elseif ($upside <= -8)  { $verdict = 'sprzedaj'; $note = 'Kurs ' . number_format(-$upside, 0) . '% powyżej wyceny analityków. '; }
            else                    { $verdict = 'trzymaj';  $note = 'Kurs blisko wyceny godziwej. '; }
            $note .= $ta > 0.2 ? 'Sygnały techniczne sprzyjają.' : ($ta < -0.2 ? 'Technika ostrzega przed słabością.' : 'Obraz techniczny neutralny.');
            try { $ins->execute([(int) $st['id'], $session, $verdict, $target, $note, Db::now()]); }
            catch (\Throwable $e) { /* duplikat (powtórny roll) — pomiń */ }
        }
        // retencja: trzymaj ~60 sesji wstecz
        if (($session % 20) === 0) Db::pdo()->prepare("DELETE FROM recommendations WHERE session < ?")->execute([$session - 60]);
    }

    /**
     * Rekomendacje widoczne dla gracza: premium (Pakiet Analityka) widzi bieżącą
     * sesję + poprzednią; pozostali tylko do sesji-1. Zwraca też liczbę ukrytych
     * dzisiejszych (teaser dla nie-premium).
     */
    public static function visibleFor(int $uid, int $limit = 12): array
    {
        [$session] = Engine::sessionInfo();
        $premium = Tokens::hasPass($uid, 'analityk');
        $maxSession = $premium ? $session : $session - 1;
        $rows = Engine::all(
            "SELECT r.*, s.ticker, s.name, s.price
             FROM recommendations r JOIN stocks s ON s.id = r.stock_id
             WHERE r.session <= ? ORDER BY r.session DESC, r.id DESC LIMIT " . (int) $limit, [$maxSession]);
        $hiddenToday = $premium ? 0 : (int) Engine::one("SELECT COUNT(*) FROM recommendations WHERE session = ?", [$session]);
        return [$rows, $hiddenToday, $premium, $session];
    }
}
