<?php
/**
 * JEDNO źródło prawdy dla schematu bazy. Dialekt-świadome (SQLite/MySQL).
 * Pełny model spółki i jej otoczenia (sektory, DNA spółki, raporty, newsy/ESPI,
 * DNA botów). Mechanika dokładana warstwami — najpierw raporty miesięczne.
 */
final class Schema
{
    public const VERSION = 24;  // podbijaj przy każdej zmianie schematu (+ dopisz migrację w Migrator)

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
                goal_session   INT NULL,                 -- sesja, w której gracz osiągnął cel (NULL = jeszcze nie)
                start_equity   $money NOT NULL DEFAULT 0, -- kapitał startowy (baza do wyniku % w rankingu)
                tokens INT NOT NULL DEFAULT 0,             -- Żetony Maklera (waluta premium; księga w token_ledger)
                email VARCHAR(120) NULL,                   -- do odzyskiwania hasła (opcjonalny; unikalny gdy podany)
                goal_target DECIMAL(15,2) NULL,            -- osobisty cel gry (NULL = domyślny z panelu GM)
                -- kosmetyka (założone przedmioty; katalog w src/Cosmetics.php):
                title      VARCHAR(40) NOT NULL DEFAULT '',  -- tytuł przy nicku (ranking/profil)
                chat_color VARCHAR(7)  NOT NULL DEFAULT '',  -- kolor nicka na czacie (#rrggbb)
                frame      VARCHAR(16) NOT NULL DEFAULT ''   -- ramka awatara na profilu (gold/silver)
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
                tech_affinity        DECIMAL(4,2) NOT NULL DEFAULT 0.5,  -- podatność na analizę techniczną (0=fundamentalna, 1=techniczna)
                ta_signal            DECIMAL(5,3) NOT NULL DEFAULT 0,    -- zbiorczy sygnał AT (cache per tick; skaner na Rynku)
                -- sterowanie GM:
                bias $f NOT NULL DEFAULT 0,
                profit_trend $f NOT NULL DEFAULT 0,   -- ręczny miernik trendu zysków (%/miesiąc, edytowalny w GM)
                dividend_payout DECIMAL(4,2) NOT NULL DEFAULT 0,  -- jaki % zysku spółka wypłaca akcjonariuszom (0-0.8)
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
                qty          INT NOT NULL,            -- POZOSTAŁO do realizacji (maleje przy fill'ach)
                qty_init     INT NOT NULL DEFAULT 0,  -- pierwotna wielkość zlecenia (archiwum: zrealizowano X/Y)
                price    $money NOT NULL,
                status   VARCHAR(10) NOT NULL DEFAULT 'active',  -- active|pending|filled|cancelled|expired|triggered
                expires_session INT NULL,             -- ważność: NULL = bezterminowe, N = do końca sesji N
                sl_price $money NULL,                 -- zlecenie obronne (status 'pending'): próg Stop-Loss
                tp_price $money NULL,                 -- zlecenie obronne: próg Take-Profit
                created_at VARCHAR(19) NOT NULL
            )",

            "transactions" => "CREATE TABLE transactions (
                id $pk,
                stock_id INT NOT NULL,
                buyer_id INT NOT NULL,
                seller_id INT NOT NULL,
                buy_order_id  INT NULL,   -- powiązanie ze zleceniem (szczegóły/oś czasu zlecenia)
                sell_order_id INT NULL,
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
                surprise_pct DECIMAL(7,2) NOT NULL,  -- niespodzianka vs oczekiwania
                dividend DECIMAL(10,2) NOT NULL DEFAULT 0   -- wypłacona dywidenda na akcję (0 = brak)
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
                kind VARCHAR(12) NOT NULL DEFAULT 'fundamental',  -- klasa informacji: fundamental | sentiment | technical (inne boty słuchają innych klas)
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

            // --- INDEKS GIEŁDOWY (ważony kapitalizacją, baza 1000 pkt; historia per tick) ---
            "index_history" => "CREATE TABLE index_history (
                id $pk,
                t INT NOT NULL,
                value DECIMAL(12,2) NOT NULL
            )",

            // --- HISTORIA KAPITAŁU GRACZY (wykres wartości portfela; tylko ludzie) ---
            "equity_history" => "CREATE TABLE equity_history (
                id $pk,
                user_id INT NOT NULL,
                t INT NOT NULL,
                equity $money NOT NULL
            )",

            // --- WYDARZENIA: modyfikatory czasowe (nakładki na bazowe wartości, SAME wygasają) ---
            "active_effects" => "CREATE TABLE active_effects (
                id $pk,
                target_type VARCHAR(10) NOT NULL,   -- market | sector | stock
                target_id INT NULL,                 -- id sektora/spółki (NULL dla market)
                field VARCHAR(20) NOT NULL,         -- sentiment|trend|profit_climate|volatility|profit_trend|dividend_pause
                delta DECIMAL(8,3) NOT NULL,
                expire_tick INT NOT NULL,
                source VARCHAR(40) NOT NULL         -- kod wydarzenia (diagnostyka)
            )",

            // --- WYDARZENIA: kolejka kaskad i rozstrzygnięć plotek ---
            "scheduled_events" => "CREATE TABLE scheduled_events (
                id $pk,
                due_tick INT NOT NULL,
                template_code VARCHAR(40) NOT NULL,
                sector_id INT NULL,
                stock_id INT NULL,
                resolve_json TEXT NULL,             -- wykluczające alternatywy [[szansa, code], ...]
                fired TINYINT NOT NULL DEFAULT 0
            )",

            // --- POWIADOMIENIA (dzwonek gracza: dywidendy, SL/TP, raporty, realizacje zleceń) ---
            "notifications" => "CREATE TABLE notifications (
                id $pk,
                user_id INT NOT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'system',  -- dividend|stop|report|order|goal|system
                message VARCHAR(255) NOT NULL,
                link VARCHAR(100) NULL,                      -- dokąd prowadzi kliknięcie
                created_at VARCHAR(19) NOT NULL,
                read_at VARCHAR(19) NULL                     -- NULL = nieprzeczytane (licznik na dzwonku)
            )",

            // --- CZAT RYNKOWY (rozmowy graczy; GM może ukrywać wpisy) ---
            "chat_messages" => "CREATE TABLE chat_messages (
                id $pk,
                user_id INT NOT NULL,
                message VARCHAR(300) NOT NULL,
                created_at VARCHAR(19) NOT NULL,
                deleted TINYINT NOT NULL DEFAULT 0
            )",

            // --- OSIĄGNIĘCIA (odznaki graczy; katalog w src/Achievements.php) ---
            "achievements" => "CREATE TABLE achievements (
                id $pk,
                user_id INT NOT NULL,
                code VARCHAR(40) NOT NULL,
                earned_at VARCHAR(19) NOT NULL,
                UNIQUE (user_id, code)
            )",

            // --- WYZWANIA (konkursy inwestycyjne; logika w src/Challenges.php) ---
            "challenges" => "CREATE TABLE challenges (
                id $pk,
                name VARCHAR(80) NOT NULL,
                series_id INT NULL,                            -- edycja serii (sezon: punkty i karnet w src/Seasons.php)
                status VARCHAR(12) NOT NULL DEFAULT 'signup',  -- signup | running | finished | cancelled
                buyin  $money NOT NULL,                        -- kapitał wyzwania (zablokowany z konta głównego)
                fee_pct $f NOT NULL DEFAULT 10,                -- wpisowe (% buy-inu) -> pula nagród
                pot    $money NOT NULL DEFAULT 0,              -- pula nagród (suma wpisowego)
                min_players INT NOT NULL DEFAULT 3,
                start_session INT NOT NULL,                    -- pierwsza sesja handlu (do niej trwają zapisy)
                end_session   INT NOT NULL,                    -- ostatnia sesja handlu
                created_at VARCHAR(19) NOT NULL
            )",
            "challenge_players" => "CREATE TABLE challenge_players (
                id $pk,
                challenge_id INT NOT NULL,
                user_id      INT NOT NULL,                     -- właściciel (konto główne)
                shadow_user_id INT NULL,                       -- subkonto wyzwania (tworzone na starcie)
                buyin $money NOT NULL,
                fee   $money NOT NULL,
                final_equity $money NULL,
                final_rank INT NULL,
                prize $money NOT NULL DEFAULT 0,
                joined_at VARCHAR(19) NOT NULL,
                UNIQUE (challenge_id, user_id)
            )",

            // --- SERIE WYZWAŃ (cykliczne edycje, np. liga co N sesji; logika w Challenges::onRoll) ---
            "challenge_series" => "CREATE TABLE challenge_series (
                id $pk,
                name VARCHAR(60) NOT NULL,
                buyin $money NOT NULL,
                fee_pct $f NOT NULL DEFAULT 10,
                signup_sess INT NOT NULL DEFAULT 2,
                duration INT NOT NULL DEFAULT 14,
                min_players INT NOT NULL DEFAULT 3,
                every_sessions INT NOT NULL,             -- co ile sesji otwierają się zapisy nowej edycji
                editions INT NOT NULL DEFAULT 0,         -- licznik wydanych edycji
                next_session INT NOT NULL,               -- sesja, w której otworzyć następną edycję
                enabled TINYINT NOT NULL DEFAULT 1,
                created_at VARCHAR(19) NOT NULL
            )",

            // --- ŚWIECE DZIENNE (D1: jedna świeca na sesję/dzień giełdowy — wykresy tydzień/miesiąc/rok) ---
            "candles_daily" => "CREATE TABLE candles_daily (
                id $pk,
                stock_id INT NOT NULL,
                session  INT NOT NULL,
                o $money NOT NULL, h $money NOT NULL, l $money NOT NULL, c $money NOT NULL,
                v BIGINT NOT NULL DEFAULT 0,
                UNIQUE (stock_id, session)
            )",

            // --- MONETYZACJA: Żetony Maklera (księga), pakiety premium, rekomendacje DM ---
            "token_ledger" => "CREATE TABLE token_ledger (
                id $pk,
                user_id INT NOT NULL,
                delta   INT NOT NULL,                 -- +zdobyte / -wydane
                balance INT NOT NULL,                 -- saldo PO operacji
                reason  VARCHAR(40) NOT NULL,         -- welcome | challenge | achievement | pass | gm | purchase
                note    VARCHAR(160) NULL,
                created_at VARCHAR(19) NOT NULL
            )",
            "premium_passes" => "CREATE TABLE premium_passes (
                id $pk,
                user_id INT NOT NULL,
                kind VARCHAR(24) NOT NULL,            -- analityk (skaner AT + rekomendacje dzień wcześniej)
                until_session INT NOT NULL,           -- pakiet aktywny DO tej sesji włącznie
                created_at VARCHAR(19) NOT NULL,
                UNIQUE (user_id, kind)
            )",
            "recommendations" => "CREATE TABLE recommendations (
                id $pk,
                stock_id INT NOT NULL,
                session  INT NOT NULL,                -- sesja publikacji (premium widzi od razu, reszta od następnej)
                verdict  VARCHAR(10) NOT NULL,        -- kupuj | trzymaj | sprzedaj
                target_price DECIMAL(15,2) NOT NULL,
                note VARCHAR(200) NULL,
                created_at VARCHAR(19) NOT NULL,
                UNIQUE (stock_id, session)
            )",

            // --- PŁATNOŚCI: zamówienia doładowań żetonów za prawdziwe pieniądze (PayU/BLIK) ---
            "payment_orders" => "CREATE TABLE payment_orders (
                id $pk,
                user_id INT NOT NULL,
                package VARCHAR(20) NOT NULL,          -- start | inwestor | rekin (katalog w src/Payments.php)
                tokens  INT NOT NULL,                  -- żetony do przyznania po opłaceniu (z bonusem)
                amount_grosz INT NOT NULL,             -- kwota w groszach (PLN)
                status VARCHAR(12) NOT NULL DEFAULT 'new',  -- new | pending | completed | cancelled
                provider VARCHAR(12) NOT NULL DEFAULT 'payu',
                ext_ref VARCHAR(64) NULL,              -- id zamówienia u operatora płatności
                created_at VARCHAR(19) NOT NULL,
                paid_at VARCHAR(19) NULL
            )",

            // --- OBSERWOWANE (gwiazdki) + alerty sygnałów AT dla Pakietu Analityka ---
            "watchlist" => "CREATE TABLE watchlist (
                id $pk,
                user_id INT NOT NULL,
                stock_id INT NOT NULL,
                alert_state VARCHAR(12) NOT NULL DEFAULT '',  -- ostatni mocny werdykt (anty-dubel alertu)
                alert_session INT NOT NULL DEFAULT 0,         -- sesja ostatniego alertu (max 1/spółkę/sesję)
                created_at VARCHAR(19) NOT NULL,
                UNIQUE (user_id, stock_id)
            )",

            // --- KOSMETYKA: posiadane przedmioty (tytuły, kolory nicka, ramki; katalog w src/Cosmetics.php) ---
            "user_items" => "CREATE TABLE user_items (
                id $pk,
                user_id INT NOT NULL,
                item VARCHAR(40) NOT NULL,
                created_at VARCHAR(19) NOT NULL,
                UNIQUE (user_id, item)
            )",

            // --- SEZON: punkty za wyzwania serii + karnet sezonowy (ścieżki nagród; src/Seasons.php) ---
            "season_progress" => "CREATE TABLE season_progress (
                id $pk,
                series_id INT NOT NULL,
                user_id INT NOT NULL,
                points INT NOT NULL DEFAULT 0,
                premium TINYINT NOT NULL DEFAULT 0,    -- 1 = kupiony karnet premium (druga ścieżka nagród)
                granted_upto INT NOT NULL DEFAULT 0,   -- ile progów nagród już wypłacono
                UNIQUE (series_id, user_id)
            )",

            // --- RESET HASŁA (tokeny jednorazowe; wysyłka przez src/Mailer.php) ---
            "password_resets" => "CREATE TABLE password_resets (
                id $pk,
                user_id INT NOT NULL,
                token_hash VARCHAR(64) NOT NULL,       -- sha256 tokenu (token jawny tylko w mailu)
                expires_at VARCHAR(19) NOT NULL,
                used_at VARCHAR(19) NULL,
                created_at VARCHAR(19) NOT NULL
            )",

            // --- CODZIENNA PĘTLA: seria logowań + misje dnia (logika w src/Daily.php) ---
            "daily_state" => "CREATE TABLE daily_state (
                user_id INT PRIMARY KEY,
                last_day VARCHAR(10) NOT NULL,         -- ostatni dzień z aktywnością (YYYY-MM-DD)
                streak INT NOT NULL DEFAULT 0          -- długość bieżącej serii dni
            )",
            "daily_missions" => "CREATE TABLE daily_missions (
                id $pk,
                user_id INT NOT NULL,
                day  VARCHAR(10) NOT NULL,
                code VARCHAR(30) NOT NULL,
                tokens INT NOT NULL,
                created_at VARCHAR(19) NOT NULL,       -- wiersz = misja zaliczona i wypłacona
                UNIQUE (user_id, day, code)
            )",

            // --- DZIENNIK GRACZA (oś czasu konta: zlecenia, SL/TP, dywidendy, wyzwania, odznaki) ---
            "player_journal" => "CREATE TABLE player_journal (
                id $pk,
                user_id INT NOT NULL,
                ts VARCHAR(19) NOT NULL,
                tick INT NOT NULL DEFAULT 0,
                type VARCHAR(24) NOT NULL,      -- order | trade | stop | dividend | challenge | achievement | ipo | goal | event | report | system
                message VARCHAR(300) NOT NULL,
                link VARCHAR(120) NULL
            )",

            // --- DZIENNIK LOGÓW (dane do analizy błędów; pisany przez QA, silnik i akcje graczy) ---
            "logs" => "CREATE TABLE logs (
                id $pk,
                ts VARCHAR(19) NOT NULL,
                tick INT NOT NULL DEFAULT 0,
                level VARCHAR(10) NOT NULL DEFAULT 'info',   -- info | warn | error
                source VARCHAR(20) NOT NULL,                 -- qa | engine | player | auth | gm
                event VARCHAR(50) NOT NULL,
                message TEXT NULL,
                context TEXT NULL                            -- JSON ze szczegółami
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
            "CREATE INDEX ix_logs ON logs (level, id)",
            "CREATE INDEX ix_index_t ON index_history (t)",
            "CREATE INDEX ix_equity ON equity_history (user_id, t)",
            "CREATE INDEX ix_tx_buyorder ON transactions (buy_order_id)",
            "CREATE INDEX ix_tx_sellorder ON transactions (sell_order_id)",
            "CREATE INDEX ix_notif ON notifications (user_id, read_at)",
            "CREATE INDEX ix_effects ON active_effects (expire_tick)",
            "CREATE INDEX ix_sched ON scheduled_events (fired, due_tick)",
            "CREATE INDEX ix_chat ON chat_messages (deleted, id)",
            "CREATE INDEX ix_ach_user ON achievements (user_id)",
            "CREATE INDEX ix_tx_buyer ON transactions (buyer_id)",
            "CREATE INDEX ix_tx_seller ON transactions (seller_id)",
            "CREATE INDEX ix_chp_ch ON challenge_players (challenge_id)",
            "CREATE INDEX ix_chp_user ON challenge_players (user_id)",
            "CREATE INDEX ix_chp_shadow ON challenge_players (shadow_user_id)",
            "CREATE INDEX ix_journal ON player_journal (user_id, id)",
            "CREATE INDEX ix_cd ON candles_daily (stock_id, session)",
            "CREATE INDEX ix_ledger ON token_ledger (user_id, id)",
            "CREATE INDEX ix_reco ON recommendations (session)",
            "CREATE INDEX ix_pay_user ON payment_orders (user_id, id)",
            "CREATE INDEX ix_season ON season_progress (series_id, points)",
            "CREATE UNIQUE INDEX ux_users_email ON users (email)",
            "CREATE INDEX ix_pwreset ON password_resets (token_hash)",
        ];
    }
}
