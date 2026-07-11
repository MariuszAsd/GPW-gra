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
            // v13: rozbudowane wydarzenia — modyfikatory czasowe + kolejka kaskad/plotek
            13 => [
                "CREATE TABLE active_effects (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    target_type VARCHAR(10) NOT NULL,
                    target_id INT NULL,
                    field VARCHAR(20) NOT NULL,
                    delta DECIMAL(8,3) NOT NULL,
                    expire_tick INT NOT NULL,
                    source VARCHAR(40) NOT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE scheduled_events (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    due_tick INT NOT NULL,
                    template_code VARCHAR(40) NOT NULL,
                    sector_id INT NULL,
                    stock_id INT NULL,
                    resolve_json TEXT NULL,
                    fired TINYINT NOT NULL DEFAULT 0
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_effects ON active_effects (expire_tick)",
                "CREATE INDEX ix_sched ON scheduled_events (fired, due_tick)",
            ],
            // v14: czat rynkowy + osiągnięcia (odznaki)
            14 => [
                "CREATE TABLE chat_messages (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    message VARCHAR(300) NOT NULL,
                    created_at VARCHAR(19) NOT NULL,
                    deleted TINYINT NOT NULL DEFAULT 0
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE achievements (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    code VARCHAR(40) NOT NULL,
                    earned_at VARCHAR(19) NOT NULL,
                    UNIQUE (user_id, code)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_chat ON chat_messages (deleted, id)",
                "CREATE INDEX ix_ach_user ON achievements (user_id)",
                "CREATE INDEX ix_tx_buyer ON transactions (buyer_id)",
                "CREATE INDEX ix_tx_seller ON transactions (seller_id)",
            ],
            15 => [
                "CREATE TABLE challenges (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    name VARCHAR(80) NOT NULL,
                    status VARCHAR(12) NOT NULL DEFAULT 'signup',
                    buyin  DECIMAL(15,2) NOT NULL,
                    fee_pct DECIMAL(6,3) NOT NULL DEFAULT 10,
                    pot    DECIMAL(15,2) NOT NULL DEFAULT 0,
                    min_players INT NOT NULL DEFAULT 3,
                    start_session INT NOT NULL,
                    end_session   INT NOT NULL,
                    created_at VARCHAR(19) NOT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE challenge_players (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    challenge_id INT NOT NULL,
                    user_id      INT NOT NULL,
                    shadow_user_id INT NULL,
                    buyin DECIMAL(15,2) NOT NULL,
                    fee   DECIMAL(15,2) NOT NULL,
                    final_equity DECIMAL(15,2) NULL,
                    final_rank INT NULL,
                    prize DECIMAL(15,2) NOT NULL DEFAULT 0,
                    joined_at VARCHAR(19) NOT NULL,
                    UNIQUE (challenge_id, user_id)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_chp_ch ON challenge_players (challenge_id)",
                "CREATE INDEX ix_chp_user ON challenge_players (user_id)",
                "CREATE INDEX ix_chp_shadow ON challenge_players (shadow_user_id)",
            ],
            16 => [
                "CREATE TABLE challenge_series (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    name VARCHAR(60) NOT NULL,
                    buyin DECIMAL(15,2) NOT NULL,
                    fee_pct DECIMAL(6,3) NOT NULL DEFAULT 10,
                    signup_sess INT NOT NULL DEFAULT 2,
                    duration INT NOT NULL DEFAULT 14,
                    min_players INT NOT NULL DEFAULT 3,
                    every_sessions INT NOT NULL,
                    editions INT NOT NULL DEFAULT 0,
                    next_session INT NOT NULL,
                    enabled TINYINT NOT NULL DEFAULT 1,
                    created_at VARCHAR(19) NOT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
            ],
            17 => [
                "CREATE TABLE player_journal (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    ts VARCHAR(19) NOT NULL,
                    tick INT NOT NULL DEFAULT 0,
                    type VARCHAR(24) NOT NULL,
                    message VARCHAR(300) NOT NULL,
                    link VARCHAR(120) NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_journal ON player_journal (user_id, id)",
            ],
            18 => [
                "CREATE TABLE candles_daily (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    stock_id INT NOT NULL,
                    session  INT NOT NULL,
                    o DECIMAL(15,2) NOT NULL, h DECIMAL(15,2) NOT NULL, l DECIMAL(15,2) NOT NULL, c DECIMAL(15,2) NOT NULL,
                    v BIGINT NOT NULL DEFAULT 0,
                    UNIQUE (stock_id, session)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_cd ON candles_daily (stock_id, session)",
            ],
            // v19: analiza techniczna — podatność spółki na sygnały AT (0=fundamentalna, 1=techniczna);
            //      backfill deterministyczny z id (spójny na SQLite i MySQL)
            19 => [
                "ALTER TABLE stocks ADD COLUMN tech_affinity DECIMAL(4,2) NOT NULL DEFAULT 0.5",
                "UPDATE stocks SET tech_affinity = 0.2 + (id * 37 % 61) / 100.0",
            ],
            // v20: monetyzacja — Żetony Maklera (+10 powitalnych dla graczy), pakiety premium,
            //      rekomendacje DM, cache sygnału AT na spółce (skaner na Rynku)
            20 => [
                "ALTER TABLE users ADD COLUMN tokens INT NOT NULL DEFAULT 0",
                "ALTER TABLE stocks ADD COLUMN ta_signal DECIMAL(5,3) NOT NULL DEFAULT 0",
                "UPDATE users SET tokens = 10 WHERE is_bot = 0 AND role = 'player'",
                "CREATE TABLE token_ledger (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    delta   INT NOT NULL,
                    balance INT NOT NULL,
                    reason  VARCHAR(40) NOT NULL,
                    note    VARCHAR(160) NULL,
                    created_at VARCHAR(19) NOT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE premium_passes (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    kind VARCHAR(24) NOT NULL,
                    until_session INT NOT NULL,
                    created_at VARCHAR(19) NOT NULL,
                    UNIQUE (user_id, kind)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE recommendations (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    stock_id INT NOT NULL,
                    session  INT NOT NULL,
                    verdict  VARCHAR(10) NOT NULL,
                    target_price DECIMAL(15,2) NOT NULL,
                    note VARCHAR(200) NULL,
                    created_at VARCHAR(19) NOT NULL,
                    UNIQUE (stock_id, session)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_ledger ON token_ledger (user_id, id)",
                "CREATE INDEX ix_reco ON recommendations (session)",
            ],
            // v21: monetyzacja II — płatności PayU/BLIK (payment_orders), obserwowane + alerty AT
            //      (watchlist), kosmetyka (user_items + założone przedmioty na users),
            //      sezon/karnet (season_progress + challenges.series_id)
            21 => [
                "ALTER TABLE users ADD COLUMN title VARCHAR(40) NOT NULL DEFAULT ''",
                "ALTER TABLE users ADD COLUMN chat_color VARCHAR(7) NOT NULL DEFAULT ''",
                "ALTER TABLE users ADD COLUMN frame VARCHAR(16) NOT NULL DEFAULT ''",
                "ALTER TABLE challenges ADD COLUMN series_id INT NULL",
                "CREATE TABLE payment_orders (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    package VARCHAR(20) NOT NULL,
                    tokens  INT NOT NULL,
                    amount_grosz INT NOT NULL,
                    status VARCHAR(12) NOT NULL DEFAULT 'new',
                    provider VARCHAR(12) NOT NULL DEFAULT 'payu',
                    ext_ref VARCHAR(64) NULL,
                    created_at VARCHAR(19) NOT NULL,
                    paid_at VARCHAR(19) NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE watchlist (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    stock_id INT NOT NULL,
                    alert_state VARCHAR(12) NOT NULL DEFAULT '',
                    alert_session INT NOT NULL DEFAULT 0,
                    created_at VARCHAR(19) NOT NULL,
                    UNIQUE (user_id, stock_id)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE user_items (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    item VARCHAR(40) NOT NULL,
                    created_at VARCHAR(19) NOT NULL,
                    UNIQUE (user_id, item)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE season_progress (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    series_id INT NOT NULL,
                    user_id INT NOT NULL,
                    points INT NOT NULL DEFAULT 0,
                    premium TINYINT NOT NULL DEFAULT 0,
                    granted_upto INT NOT NULL DEFAULT 0,
                    UNIQUE (series_id, user_id)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE INDEX ix_pay_user ON payment_orders (user_id, id)",
                "CREATE INDEX ix_season ON season_progress (series_id, points)",
            ],
            // v22: konta na serio (email + reset hasła) i codzienna pętla (seria + misje dnia)
            22 => [
                "ALTER TABLE users ADD COLUMN email VARCHAR(120) NULL",
                "CREATE TABLE password_resets (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    token_hash VARCHAR(64) NOT NULL,
                    expires_at VARCHAR(19) NOT NULL,
                    used_at VARCHAR(19) NULL,
                    created_at VARCHAR(19) NOT NULL
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE daily_state (
                    user_id INT PRIMARY KEY,
                    last_day VARCHAR(10) NOT NULL,
                    streak INT NOT NULL DEFAULT 0
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE TABLE daily_missions (
                    id " . (Db::driver() === 'mysql' ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ",
                    user_id INT NOT NULL,
                    day  VARCHAR(10) NOT NULL,
                    code VARCHAR(30) NOT NULL,
                    tokens INT NOT NULL,
                    created_at VARCHAR(19) NOT NULL,
                    UNIQUE (user_id, day, code)
                )" . (Db::driver() === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''),
                "CREATE UNIQUE INDEX ux_users_email ON users (email)",
                "CREATE INDEX ix_pwreset ON password_resets (token_hash)",
            ],
            // v23: osobisty cel gry (gracz może zmienić próg; NULL = domyślny GM)
            23 => [
                "ALTER TABLE users ADD COLUMN goal_target DECIMAL(15,2) NULL",
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
