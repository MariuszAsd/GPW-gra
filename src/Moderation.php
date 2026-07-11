<?php
/**
 * Moderacja treści graczy (forum spółek + czat rynkowy).
 *
 * Zasada: wpis NIGDY nie jest blokowany w całości — niedozwolone słowa są
 * wygwiazdkowane (pierwsza litera + ***), a incydent trafia do rejestru:
 * gracz dostaje ostrzeżenie z numerem przewinienia, każdy admin powiadomienie,
 * pełna lista z oryginalną treścią czeka w panelu GM (sekcja Moderacja).
 *
 * Wykrywanie odporne na maskowanie: małe litery, zdjęte ogonki (ą→a),
 * podmiany leet (0→o, 3→e, @→a, v→w…), usunięte separatory w słowie
 * (k.u.r.w.a) i ściśnięte powtórki liter (kuuurwa).
 */
final class Moderation
{
    /** Rdzenie wykrywane WEWNĄTRZ słowa (jednoznaczne — nie łapią zwykłych słów). */
    private const STEMS = [
        // wulgaryzmy
        'kurw', 'kurew', 'chuj', 'huj', 'pierdol', 'pierdal', 'pierdziel',
        'jebac', 'jebie', 'jebia', 'jebna', 'jebne', 'jebany', 'jebani', 'jebana', 'jebane', 'jebaka',
        'zajeb', 'wyjeb', 'dojeb', 'najeb', 'podjeb', 'rozjeb', 'ujeb', 'wjeb', 'zjeb', 'objeb', 'przejeb', 'przyjeb', 'odjeb',
        'pizd', 'kutas', 'fiut', 'cipk', 'cipsko', 'dziwk', 'skurwiel', 'skurwysyn',
        'dupczy', 'przyglup',
        // obelgi na tle tożsamości i mowa nienawiści
        'pedaly', 'pedale', 'pedalstwo', 'ciapat', 'brudas', 'podczlowiek',
        // tabu (gloryfikacja nazizmu nie przejdzie w grze o giełdzie)
        'swastyk',
        // angielskie klasyki
        'fuck', 'motherfuck', 'nigger', 'nigga', 'faggot', 'bitch', 'cunt', 'whore', 'asshole',
    ];

    /** Całe słowa (po normalizacji) — osobno, bo jako rdzenie łapałyby niewinne wyrazy
     *  (ciota vs ciotka, suka vs sukces, gnoju vs ogniowy itd.). */
    private const WORDS = [
        'ciota', 'cioto', 'cioty', 'pedal', 'pedala', 'pedalu', 'suka', 'suko', 'szmata', 'szmato',
        'debil', 'debilu', 'debile', 'debilko', 'kretyn', 'kretynie', 'kretyni', 'idiota', 'idioto', 'idiotka', 'idioci',
        'duren', 'durniu', 'gnoj', 'gnoju', 'gnoje', 'gnojek', 'gnojku', 'frajer', 'frajerze', 'frajerzy',
        'szmaciarz', 'szmaciarzu', 'menda', 'mendo', 'scierwo', 'padalec', 'padalcu',
        'hitler', 'hitlera', 'hitlerowi', 'nazista', 'nazisto', 'nazisci', 'heil', 'shit',
    ];

    /** Normalizacja słowa do porównań: ogonki, leet, separatory, powtórki. */
    public static function normalize(string $word): string
    {
        $w = mb_strtolower($word);
        $w = strtr($w, ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z']);
        $w = strtr($w, ['0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't', '$' => 's', '@' => 'a', '!' => 'i', 'v' => 'w', 'q' => 'k', 'x' => 'ks']);
        $w = preg_replace('/[^a-z]/', '', $w) ?? '';
        return preg_replace('/(.)\1+/', '$1', $w) ?? '';   // kuuurwa -> kurwa
    }

    /**
     * Wygwiazdkowanie: zwraca [tekst po cenzurze, lista wykrytych słów (oryginały)].
     * Tekst dzielony po białych znakach — gwiazdkujemy CAŁE słowo (pierwsza litera zostaje).
     */
    public static function censor(string $text): array
    {
        $hits = [];
        $out = preg_replace_callback('/\S+/u', function ($m) use (&$hits) {
            $orig = $m[0];
            $norm = self::normalize($orig);
            if ($norm === '') return $orig;
            $bad = in_array($norm, self::WORDS, true);
            if (!$bad) foreach (self::STEMS as $s) { if (str_contains($norm, $s)) { $bad = true; break; } }
            if (!$bad) return $orig;
            $hits[] = $orig;
            $first = mb_substr($orig, 0, 1);
            return $first . str_repeat('*', max(2, mb_strlen($orig) - 1));
        }, $text) ?? $text;
        return [$out, $hits];
    }

    /**
     * Rejestr incydentu po udanym wpisie z wykrytymi słowami:
     * zapis do mod_incidents + ostrzeżenie dla gracza + alert dla adminów.
     * Zwraca numer przewinienia gracza.
     */
    public static function report(int $userId, string $context, ?int $stockId, string $original, array $hits): int
    {
        $strike = (int) Engine::one("SELECT COUNT(*) FROM mod_incidents WHERE user_id=?", [$userId]) + 1;
        Db::pdo()->prepare("INSERT INTO mod_incidents (user_id, context, stock_id, original, words, strike_no, created_at) VALUES (?,?,?,?,?,?,?)")
            ->execute([$userId, $context, $stockId, mb_substr($original, 0, 300), mb_substr(implode(', ', $hits), 0, 200), $strike, Db::now()]);
        Engine::notify($userId, 'moderation',
            "⚠️ Moderacja: Twój wpis zawierał niedozwolone słownictwo i został wygwiazdkowany. "
            . "To przewinienie nr $strike — wiemy o każdym takim wpisie. Kolejne może skończyć się usunięciem konta z gry.",
            $context === 'forum' && $stockId ? "stock.php?id=$stockId&tab=forum" : 'market.php');
        $who = (string) (Engine::one("SELECT username FROM users WHERE id=?", [$userId]) ?: ('#' . $userId));
        foreach (Engine::col("SELECT id FROM users WHERE role='admin'") as $aid) {
            Engine::notify((int) $aid, 'moderation',
                "🚨 Moderacja: $who użył niedozwolonych słów (" . mb_substr(implode(', ', $hits), 0, 60) . ") "
                . ($context === 'forum' ? 'na forum spółki' : 'na czacie rynku') . " — przewinienie nr $strike. Szczegóły w panelu GM.",
                'gm.php#moderacja');
        }
        Log::write('warn', 'moderation', 'words.detected', "$who ($context): " . implode(', ', $hits), ['user_id' => $userId, 'strike' => $strike]);
        return $strike;
    }
}
