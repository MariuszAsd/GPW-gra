<?php
/**
 * JEDNO źródło prawdy dla schematu bazy. Dialekt-świadome (SQLite/MySQL),
 * ale identyczne kolumny i nazwy w obu — koniec z "dwa sprzeczne schematy".
 * KLUCZOWE decyzje wprost naprawiające błędy z audytu:
 *   - users.is_bot i users.role są tu naprawdę (silnik może na nich polegać),
 *   - wallets ma UNIQUE(user_id, stock_id) (poprawny upsert / brak duplikatów),
 *   - orders: side i status trzymamy MAŁYMI literami w całym kodzie,
 *   - kwoty pieniężne jako DECIMAL(15,2).
 */
final class Schema
{
    public static function tables(): array
    {
        $mysql = Db::driver() === 'mysql';
        $pk    = $mysql ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $money = 'DECIMAL(15,2)';

        return [
            "users" => "CREATE TABLE users (
                id $pk,
                username   VARCHAR(50)  NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_bot     TINYINT NOT NULL DEFAULT 0,
                role       VARCHAR(20) NOT NULL DEFAULT 'player',   -- player | admin | bot strategy
                cash          $money NOT NULL DEFAULT 0,
                cash_reserved $money NOT NULL DEFAULT 0
            )",

            "stocks" => "CREATE TABLE stocks (
                id $pk,
                ticker   VARCHAR(8)  NOT NULL UNIQUE,
                name     VARCHAR(80) NOT NULL,
                sector   VARCHAR(40) NOT NULL DEFAULT 'Ogólny',
                price        $money NOT NULL,
                fundamental  $money NOT NULL,
                total_shares BIGINT NOT NULL DEFAULT 1000000,
                bias DECIMAL(6,3) NOT NULL DEFAULT 0,   -- sterowanie: dryf trendu w %/tick
                vol  DECIMAL(6,3) NOT NULL DEFAULT 1    -- sterowanie: mnożnik zmienności
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
                side     VARCHAR(4) NOT NULL,            -- 'buy' | 'sell'
                qty          INT NOT NULL,
                price    $money NOT NULL,
                status   VARCHAR(10) NOT NULL DEFAULT 'active',  -- active|filled|cancelled
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
                t INT NOT NULL,                          -- numer ticku
                o $money NOT NULL, h $money NOT NULL, l $money NOT NULL, c $money NOT NULL,
                v INT NOT NULL DEFAULT 0
            )",

            "game_state" => "CREATE TABLE game_state (
                k VARCHAR(50) PRIMARY KEY,
                v VARCHAR(255) NOT NULL
            )",
        ];
    }

    public static function indexes(): array
    {
        return [
            "CREATE INDEX ix_orders_book ON orders (stock_id, side, status, price)",
            "CREATE INDEX ix_candles ON candles (stock_id, t)",
            "CREATE INDEX ix_wallets_user ON wallets (user_id)",
        ];
    }
}
