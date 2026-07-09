<?php
/**
 * Jedna klasa połączenia z bazą. Cała aplikacja używa Db::pdo().
 * Przenośna: ten sam kod działa na SQLite (lokalnie) i MySQL (hosting).
 */
final class Db
{
    private static ?PDO $pdo = null;
    private static string $driver = 'sqlite';

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) return self::$pdo;

        $cfg = require __DIR__ . '/../config.php';
        self::$driver = $cfg['db_driver'];

        if (self::$driver === 'mysql') {
            $m = $cfg['mysql'];
            $dsn = "mysql:host={$m['host']};dbname={$m['name']};charset=utf8mb4";
            $pdo = new PDO($dsn, $m['user'], $m['pass']);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } else {
            @mkdir(dirname($cfg['sqlite']['path']), 0777, true);
            $pdo = new PDO('sqlite:' . $cfg['sqlite']['path']);
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function driver(): string { self::pdo(); return self::$driver; }

    /** timestamp generowany po stronie PHP -> działa identycznie w SQLite i MySQL */
    public static function now(): string { return date('Y-m-d H:i:s'); }
}
