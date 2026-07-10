<?php
/**
 * System migracji bazy — dzięki niemu DEPLOY sam aktualizuje schemat bez utraty danych.
 *
 * Model (jak w Rails/Laravel):
 *   - Schema.php  = PEŁNY, aktualny schemat (świeża instalacja bierze go w całości),
 *   - Migrator    = przyrostowe migracje dla ISTNIEJĄCYCH baz (ADD COLUMN / CREATE TABLE),
 *   - schema_meta = wersja schematu w bazie.
 *
 * Przy KAŻDEJ zmianie schematu:
 *   1) zaktualizuj Schema.php (pełny obraz),
 *   2) podbij Schema::VERSION,
 *   3) dopisz tutaj migrację przyrostową dla tej wersji (addytywną).
 * Po deployu Migrator::ensure() (wołane w _boot i cronie) automatycznie ją zastosuje.
 */
final class Migrator
{
    /** Migracje przyrostowe: klucz = docelowa wersja, wartość = lista poleceń SQL (addytywnych). */
    public static function migrations(): array
    {
        return [
            // v2: miernik trendu zysków spółki + koniunktura wyników sektora
            2 => [
                "ALTER TABLE stocks  ADD COLUMN profit_trend   DECIMAL(6,3) NOT NULL DEFAULT 0",
                "ALTER TABLE sectors ADD COLUMN profit_climate DECIMAL(6,3) NOT NULL DEFAULT 0",
            ],
            // v3: cel gry i sesje (sesja dołączenia gracza + sesja osiągnięcia celu)
            3 => [
                "ALTER TABLE users ADD COLUMN joined_session INT NOT NULL DEFAULT 1",
                "ALTER TABLE users ADD COLUMN goal_session INT NULL",
            ],
            // v4: kapitał startowy (baza wyniku % w rankingu) + backfill dla istniejących graczy
            4 => [
                "ALTER TABLE users ADD COLUMN start_equity DECIMAL(15,2) NOT NULL DEFAULT 0",
                "UPDATE users SET start_equity = 100000 WHERE is_bot = 0 AND role = 'player' AND start_equity = 0",
            ],
            // v5: dziennik logów (QA / silnik / akcje graczy)
            5 => [
                "CREATE TABLE logs (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    ts VARCHAR(19) NOT NULL,
                    tick INT NOT NULL DEFAULT 0,
                    level VARCHAR(10) NOT NULL DEFAULT 'info',
                    source VARCHAR(20) NOT NULL,
                    event VARCHAR(50) NOT NULL,
                    message TEXT NULL,
                    context TEXT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_logs ON logs (level, id)",
            ],
            // v6: indeks giełdowy (historia wartości per tick)
            6 => [
                "CREATE TABLE index_history (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    t INT NOT NULL,
                    value DECIMAL(12,2) NOT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_index_t ON index_history (t)",
            ],
            // v7: ważność zleceń (sesyjne/bezterminowe) + historia kapitału graczy (wykres portfela)
            7 => [
                "ALTER TABLE orders ADD COLUMN expires_session INT NULL",
                "CREATE TABLE equity_history (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    t INT NOT NULL,
                    equity DECIMAL(15,2) NOT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_equity ON equity_history (user_id, t)",
            ],
            // v8: pierwotna wielkość zlecenia (archiwum zleceń pokazuje "zrealizowano X/Y")
            8 => [
                "ALTER TABLE orders ADD COLUMN qty_init INT NOT NULL DEFAULT 0",
                "UPDATE orders SET qty_init = qty WHERE qty_init = 0",
            ],
            // v9: SL/TP jako zlecenia obronne per pakiet (status 'pending'), nie atrybut całej pozycji;
            //     istniejące SL/TP z portfeli konwertujemy na zlecenia obronne (całość wolnych akcji)
            9 => [
                "ALTER TABLE orders ADD COLUMN sl_price DECIMAL(15,2) NULL",
                "ALTER TABLE orders ADD COLUMN tp_price DECIMAL(15,2) NULL",
                "INSERT INTO orders (user_id, stock_id, side, qty, qty_init, price, status, sl_price, tp_price, created_at)
                 SELECT user_id, stock_id, 'sell', qty, qty, 0, 'pending', sl_price, tp_price, '" . Db::now() . "'
                 FROM wallets WHERE (sl_price IS NOT NULL OR tp_price IS NOT NULL) AND qty > 0",
                "UPDATE wallets SET qty_reserved = qty_reserved + qty, qty = 0
                 WHERE (sl_price IS NOT NULL OR tp_price IS NOT NULL) AND qty > 0",
                "UPDATE wallets SET sl_price = NULL, tp_price = NULL WHERE sl_price IS NOT NULL OR tp_price IS NOT NULL",
            ],
            // v10: powiązanie transakcji ze zleceniami (strona szczegółów zlecenia z osią czasu)
            10 => [
                "ALTER TABLE transactions ADD COLUMN buy_order_id INT NULL",
                "ALTER TABLE transactions ADD COLUMN sell_order_id INT NULL",
                "CREATE INDEX ix_tx_buyorder ON transactions (buy_order_id)",
                "CREATE INDEX ix_tx_sellorder ON transactions (sell_order_id)",
            ],
            // v11: dywidendy — polityka wypłat spółki + zapis dywidendy w raporcie;
            //      backfill: spółki wzrostowe płacą mało (5-21%), dojrzałe hojnie (30-60%)
            11 => [
                "ALTER TABLE stocks ADD COLUMN dividend_payout DECIMAL(4,2) NOT NULL DEFAULT 0",
                "ALTER TABLE financial_reports ADD COLUMN dividend DECIMAL(10,2) NOT NULL DEFAULT 0",
                "UPDATE stocks SET dividend_payout = CASE
                    WHEN growth_potential >= 0.025 THEN ROUND(0.05 + (id % 3) * 0.08, 2)
                    ELSE ROUND(0.30 + (id % 4) * 0.10, 2) END",
            ],
            // v12: powiadomienia w grze (dzwonek: dywidendy, SL/TP, raporty, realizacje zleceń)
            12 => [
                "CREATE TABLE notifications (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    type VARCHAR(20) NOT NULL DEFAULT 'system',
                    message VARCHAR(255) NOT NULL,
                    link VARCHAR(100) NULL,
                    created_at VARCHAR(19) NOT NULL,
                    read_at VARCHAR(19) NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_notif ON notifications (user_id, read_at)",
            ],
        ];
    }

    private static function metaTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (id INT PRIMARY KEY, version INT NOT NULL)");
    }

    /** Świeża instalacja: pełny schemat z Schema + stempel wersji. Wołane po DROP w migrate.php. */
    public static function install(PDO $pdo): void
    {
        foreach (Schema::tables() as $ddl) $pdo->exec($ddl);
        foreach (Schema::indexes() as $ix) $pdo->exec($ix);
        self::metaTable($pdo);
        $pdo->exec("DELETE FROM schema_meta");
        $pdo->prepare("INSERT INTO schema_meta (id, version) VALUES (1, ?)")->execute([Schema::VERSION]);
    }

    /**
     * Dla istniejących, zarządzanych baz: dołóż brakujące migracje BEZ utraty danych.
     * Bezpieczne do wołania na każdym żądaniu (gdy aktualne — jeden tani SELECT i wyjście).
     * Zwraca listę zastosowanych wersji.
     */
    public static function ensure(): array
    {
        $pdo = Db::pdo();

        // Baza zarządzana? (istnieje schema_meta). Stare/nieznane bazy zostawiamy w spokoju.
        try { $cur = $pdo->query("SELECT version FROM schema_meta WHERE id=1")->fetchColumn(); }
        catch (Throwable $e) { return []; }
        if ($cur === false) return [];
        $cur = (int) $cur;
        if ($cur >= Schema::VERSION) return [];   // aktualne — nic nie robimy

        // Blokada pliku: żeby dwa równoległe żądania nie migrowały naraz.
        $lockPath = __DIR__ . '/../data/migrate.lock';
        @mkdir(dirname($lockPath), 0777, true);
        $lock = @fopen($lockPath, 'c');
        if ($lock) flock($lock, LOCK_EX);

        try {
            $cur = (int) $pdo->query("SELECT version FROM schema_meta WHERE id=1")->fetchColumn(); // po blokadzie
            $applied = [];
            $migs = self::migrations();
            ksort($migs);
            foreach ($migs as $v => $stmts) {
                if ($v <= $cur || $v > Schema::VERSION) continue;
                foreach ($stmts as $sql) {
                    try {
                        $pdo->exec($sql);
                    } catch (Throwable $e) {
                        // idempotencja: jeśli zmiana już jest (kolumna/tabela istnieje), pomiń
                        $m = strtolower($e->getMessage());
                        if (strpos($m, 'exist') !== false || strpos($m, 'duplicate') !== false) continue;
                        throw $e;
                    }
                }
                $pdo->prepare("UPDATE schema_meta SET version=? WHERE id=1")->execute([$v]);
                $applied[] = $v;
                $cur = $v;
            }
            return $applied;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
