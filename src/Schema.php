<?php
/**
 * JEDNO źródło prawdy dla schematu bazy. Dialekt-świadome (SQLite/MySQL).
 * Pełny model spółki i jej otoczenia (sektory, DNA spółki, raporty, newsy/ESPI,
 * DNA botów). Mechanika dokładana warstwami — najpierw raporty miesięczne.
 */
final class Schema
{
    public const VERSION = 3;   // podbijaj przy każdej zmianie schematu (+ dopisz migrację w Migrator)

    public static function tables(): array
    {
        $mysql = Db::driver() === 'mysql';
        $pk    = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $money = 'DECIMAL(15,2)';   // ceny, gotówka
        $big   = 'DECIMAL(18,2)';   // przychody / zyski
        $f     = 'DECIMAL(6,3)';    // współczynniki
        // jawny charset na MySQL — baza hostingowa może mieć domyślny latin1,
        // a bez tego polskie znaki ('ł' w "Przemysł") wywalają INSERT (błąd 1366)
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

        $tables = [
            "users" => "CREATE TABLE users (
                id $pk,
                username   VARCHAR(50)  NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_bot     TINYINT NOT NULL DEFAULT 0,
                role       VARCHAR(20) NOT NULL DEFAULT 'player',
                cash          $money NOT NULL DEFAULT 0,
                cash_reserved $money NOT NULL DEFAULT 0,
                joined_session INT NOT NULL DEFAULT 1,   -- sesja dołączenia (liczy się od niej limit celu)
                goal_session   INT NULL                  -- sesja, w której gracz osiągnął cel (NULL = jeszcze nie)
            )",

            // --- SEKTOR (branża) ---
            "sectors" => "CREATE TABLE sectors (
                id $pk,
                name   VARCHAR(60) NOT NULL UNIQUE,
                symbol VARCHAR(8)  NOT NULL UNIQUE,
                market_beta      $f NOT NULL DEFAULT 1,   -- reakcja na trend rynku
                volatility       $f NOT NULL DEFAULT 1,   -- własna zmienność branży
                growth           $f NOT NULL DEFAULT 0,   -- dryf długoterminowy (%/tick)
                news_sensitivity $f NOT NULL DEFAULT 1,   -- siła newsów sektorowych
                trend            $f NOT NULL DEFAULT 0,   -- bieżący trend branży (%/tick, sterowalny)
                profit_climate   $f NOT NULL DEFAULT 0    -- koniunktura wyników w branży (%/miesiąc)
            )",

            // --- SPÓŁKA (centrum) ---
            "stocks" => "CREATE TABLE stocks (
                id $pk,
                ticker   VARCHAR(8)  NOT NULL UNIQUE,
                name     VARCHAR(80) NOT NULL,
                sector_id INT NOT NULL,
                description  TEXT NULL,
                logo         VARCHAR(255) NULL,
                total_shares BIGINT NOT NULL DEFAULT 1000000,
                free_float   DECIMAL(5,2) NOT NULL DEFAULT 100,
                price          $money NOT NULL,
                fundamental    $money NOT NULL,
                day_open_price $money NOT NULL DEFAULT 0,
                -- DNA reakcji na świat:
                beta                 $f NOT NULL DEFAULT 1,
                volatility           $f NOT NULL DEFAULT 1,
                liquidity            $f NOT NULL DEFAULT 1,
                news_impact          $f NOT NULL DEFAULT 1,
                news_frequency       $f NOT NULL DEFAULT 1,
                financial_resilience $f NOT NULL DEFAULT 1,
                growth_potential     $f NOT NULL DEFAULT 0,
                aggressiveness       $f NOT NULL DEFAULT 1,
                -- sterowanie GM:
                bias $f NOT NULL DEFAULT 0,
                profit_trend $f NOT NULL DEFAULT 0,   -- ręczny miernik trendu zysków (%/miesiąc, edytowalny w GM)
                -- fundamenty / raporty miesięczne:
                base_profit  $big NOT NULL DEFAULT 0,   -- bazowy miesięczny zysk netto
                last_profit  $big NOT NULL DEFAULT 0,   -- ostatni raportowany zysk (baza oczekiwań)
                last_eps     DECIMAL(10,4) NOT NULL DEFAULT 0,
                pe_target    DECIMAL(7,2) NOT NULL DEFAULT 15,  -- docelowe C/Z (mnożnik wyceny)
                report_period INT NOT NULL DEFAULT 20,  -- co ile ticków raport (miesiąc)
                next_report_tick INT NOT NULL DEFAULT 20
            )",

            "wallets" => "CREATE TABLE wallets (
                id $pk,
                user_id  INT NOT NULL,
                stock_id INT NOT NULL,
                qty          INT NOT NULL DEFAULT 0,
                qty_reserved INT NOT NULL DEFAULT 0,
                avg_price    $money NOT NULL DEFAULT 0,
                sl_price     $money NULL,
                tp_price     $money NULL,
                UNIQUE (user_id, stock_id)
            )",

            "orders" => "CREATE TABLE orders (
                id $pk,
                user_id  INT NOT NULL,
                stock_id INT NOT NULL,
                side     VARCHAR(4) NOT NULL,
                qty          INT NOT NULL,
                price    $money NOT NULL,
                status   VARCHAR(10) NOT NULL DEFAULT 'active',
                created_at VARCHAR(19) NOT NULL
            )",

            "transactions" => "CREATE TABLE transactions (
                id $pk,
                stock_id INT NOT NULL,
                buyer_id INT NOT NULL,
                seller_id INT NOT NULL,
                qty   INT NOT NULL,
                price $money NOT NULL,
                created_at VARCHAR(19) NOT NULL
            )",

            "candles" => "CREATE TABLE candles (
                id $pk,
                stock_id INT NOT NULL,
                t INT NOT NULL,
                o $money NOT NULL, h $money NOT NULL, l $money NOT NULL, c $money NOT NULL,
                v INT NOT NULL DEFAULT 0
            )",

            // --- RAPORTY FINANSOWE (miesięczne) ---
            "financial_reports" => "CREATE TABLE financial_reports (
                id $pk,
                stock_id INT NOT NULL,
                tick INT NOT NULL,
                period VARCHAR(20) NOT NULL,      -- np. 'Miesiąc 3'
                report_date VARCHAR(19) NOT NULL,
                revenue $big NOT NULL,
                costs   $big NOT NULL,
                net_profit $big NOT NULL,
                eps DECIMAL(10,4) NOT NULL,
                expected_eps DECIMAL(10,4) NOT NULL,
                surprise_pct DECIMAL(7,2) NOT NULL   -- niespodzianka vs oczekiwania
            )",

            // --- NEWSY / ESPI ---
            "news_templates" => "CREATE TABLE news_templates (
                id $pk,
                headline_template VARCHAR(255) NOT NULL,
                body_template TEXT NULL,
                type VARCHAR(10) NOT NULL DEFAULT 'NEU',    -- POS | NEG | NEU
                scope VARCHAR(10) NOT NULL DEFAULT 'COMPANY', -- COMPANY | SECTOR | MARKET
                is_espi TINYINT NOT NULL DEFAULT 0,
                base_impact $f NOT NULL DEFAULT 0,          -- bazowy wpływ (%/tick w szczycie)
                frequency_weight INT NOT NULL DEFAULT 10,
                duration_ticks INT NOT NULL DEFAULT 10
            )",

            "news" => "CREATE TABLE news (
                id $pk,
                template_id INT NULL,
                headline VARCHAR(255) NOT NULL,
                body TEXT NULL,
                type VARCHAR(10) NOT NULL DEFAULT 'NEU',
                scope VARCHAR(10) NOT NULL DEFAULT 'COMPANY',
                target_id INT NULL,                -- id spółki lub sektora
                is_espi TINYINT NOT NULL DEFAULT 0,
                impact_strength $f NOT NULL DEFAULT 0,
                publish_tick INT NOT NULL,
                expire_tick INT NOT NULL,
                published_at VARCHAR(19) NOT NULL
            )",

            // --- DNA BOTÓW (mechanika botów w kolejnym kroku) ---
            "bots" => "CREATE TABLE bots (
                user_id INT PRIMARY KEY,
                strategy VARCHAR(20) NOT NULL,           -- mm | trend | rsi | fundamental | news
                news_reactivity      $f NOT NULL DEFAULT 1,
                technical_sensitivity $f NOT NULL DEFAULT 1,
                risk_appetite        $f NOT NULL DEFAULT 1,
                horizon INT NOT NULL DEFAULT 10
            )",

            "game_state" => "CREATE TABLE game_state (
                k VARCHAR(50) PRIMARY KEY,
                v VARCHAR(255) NOT NULL
            )",
        ];

        return array_map(fn($ddl) => $ddl . $suffix, $tables);
    }

    public static function indexes(): array
    {
        return [
            "CREATE INDEX ix_orders_book ON orders (stock_id, side, status, price)",
            "CREATE INDEX ix_candles ON candles (stock_id, t)",
            "CREATE INDEX ix_wallets_user ON wallets (user_id)",
            "CREATE INDEX ix_reports_stock ON financial_reports (stock_id, id)",
            "CREATE INDEX ix_news_live ON news (scope, target_id, expire_tick)",
        ];
    }
}
