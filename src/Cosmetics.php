<?php
/**
 * Kosmetyka profilu — czysta monetyzacja bez wpływu na rozgrywkę.
 * Tytuł przy nicku (ranking/profil), kolor nicka na czacie, ramka awatara.
 * Kupione przedmioty lądują w user_items (na zawsze), założone trzymają
 * kolumny users.title / users.chat_color / users.frame (wartość do wyświetlenia).
 * Cena 0 = nagroda specjalna (np. z karnetu sezonowego) — nie do kupienia.
 */
final class Cosmetics
{
    /** Katalog: klucz => [typ, cena w żetonach, nazwa w sklepie, wartość zakładana] */
    public const ITEMS = [
        'title_rekin'   => ['title', 25, 'Tytuł: Rekin Parkietu',    'Rekin Parkietu'],
        'title_wilk'    => ['title', 25, 'Tytuł: Wilk z Książęcej',  'Wilk z Książęcej'],
        'title_byk'     => ['title', 20, 'Tytuł: Pogromca Bessy',    'Pogromca Bessy'],
        'title_wyga'    => ['title', 15, 'Tytuł: Stary Wyga',        'Stary Wyga'],
        'title_lowca'   => ['title', 15, 'Tytuł: Łowca Debiutów',    'Łowca Debiutów'],
        'title_legenda' => ['title', 0,  'Tytuł: Legenda Sezonu',    'Legenda Sezonu'],   // tylko z karnetu sezonowego
        'color_blekit'  => ['chat_color', 10, 'Kolor nicka: błękit',   '#2f81f7'],
        'color_szmaragd'=> ['chat_color', 10, 'Kolor nicka: szmaragd', '#1a9c6b'],
        'color_fiolet'  => ['chat_color', 10, 'Kolor nicka: fiolet',   '#8957e5'],
        'color_bursztyn'=> ['chat_color', 10, 'Kolor nicka: bursztyn', '#d29922'],
        'color_rubin'   => ['chat_color', 10, 'Kolor nicka: rubin',    '#d94a5a'],
        'frame_srebro'  => ['frame', 20, 'Srebrna ramka profilu', 'silver'],
        'frame_zloto'   => ['frame', 40, 'Złota ramka profilu',   'gold'],
    ];

    /** Kolumny users, które wolno ustawiać (typ przedmiotu => kolumna). */
    private const COLS = ['title' => 'title', 'chat_color' => 'chat_color', 'frame' => 'frame'];

    /** Posiadane przedmioty gracza (lista kluczy). */
    public static function owned(int $uid): array
    {
        return Engine::col("SELECT item FROM user_items WHERE user_id=?", [$uid]);
    }

    /** Kup przedmiot za żetony (i od razu załóż). Zwraca [ok, komunikat]. */
    public static function buy(int $uid, string $key): array
    {
        $it = self::ITEMS[$key] ?? null;
        if (!$it) return [false, 'Nie ma takiego przedmiotu.'];
        [$type, $price, $name] = $it;
        if ($price <= 0) return [false, 'Ten przedmiot to nagroda specjalna — nie da się go kupić.'];
        if (in_array($key, self::owned($uid), true)) return [false, 'Masz już ten przedmiot — załóż go poniżej.'];
        [$ok, $msg] = Tokens::spend($uid, $price, 'cosmetic', $name);
        if (!$ok) return [false, $msg];
        self::give($uid, $key);
        Engine::journal($uid, 'token', "🎨 Kupiono: $name (🪙 $price).", 'sklep.php');
        return [true, "$name — kupione i założone!"];
    }

    /** Przyznaj przedmiot bez opłaty (nagrody sezonowe itp.) i załóż go. */
    public static function give(int $uid, string $key): void
    {
        $it = self::ITEMS[$key] ?? null;
        if (!$it) return;
        try {
            Db::pdo()->prepare("INSERT INTO user_items (user_id, item, created_at) VALUES (?,?,?)")
                ->execute([$uid, $key, Db::now()]);
        } catch (Throwable $e) { /* duplikat = już ma */ }
        self::equip($uid, $key);
    }

    /** Załóż posiadany przedmiot ('' + typ = zdejmij). Zwraca [ok, komunikat]. */
    public static function equip(int $uid, string $key, string $clearType = ''): array
    {
        if ($key === '' && isset(self::COLS[$clearType])) {
            Db::pdo()->prepare("UPDATE users SET " . self::COLS[$clearType] . "='' WHERE id=?")->execute([$uid]);
            return [true, 'Zdjęto.'];
        }
        $it = self::ITEMS[$key] ?? null;
        if (!$it || !in_array($key, self::owned($uid), true)) return [false, 'Nie masz tego przedmiotu.'];
        [$type, , $name, $value] = $it;
        Db::pdo()->prepare("UPDATE users SET " . self::COLS[$type] . "=? WHERE id=?")->execute([$value, $uid]);
        return [true, "Założono: $name."];
    }
}
