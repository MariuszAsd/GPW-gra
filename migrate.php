<?php
/** Tworzy schemat od zera. Jedno źródło prawdy (Schema). Uruchom: php migrate.php */
// Ochrona: nie wolno uruchamiać przez przeglądarkę (tylko CLI albo z instalatora).
if (php_sapi_name() !== 'cli' && !defined('GPW_ALLOW_SETUP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Schema.php';
require_once __DIR__ . '/src/Migrator.php';

$pdo = Db::pdo();
$cli = php_sapi_name() === 'cli';
$log = fn($m) => print($cli ? "$m\n" : "<p>$m</p>");

$names = array_keys(Schema::tables());
foreach (array_reverse($names) as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
$pdo->exec("DROP TABLE IF EXISTS schema_meta");
Migrator::install($pdo);   // pełny schemat + stempel wersji
$log("✅ Schemat gotowy (wersja " . Schema::VERSION . ", " . Db::driver() . ").");
