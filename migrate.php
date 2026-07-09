<?php
/** Tworzy schemat od zera. Jedno źródło prawdy (Schema). Uruchom: php migrate.php */
// Ochrona: nie wolno uruchamiać przez przeglądarkę (tylko CLI albo z instalatora).
if (php_sapi_name() !== 'cli' && !defined('GPW_ALLOW_SETUP')) { http_response_code(403); exit('Forbidden'); }
require_once __DIR__ . '/src/Db.php';
require_once __DIR__ . '/src/Schema.php';

$pdo = Db::pdo();
$cli = php_sapi_name() === 'cli';
$log = fn($m) => print($cli ? "$m\n" : "<p>$m</p>");

$names = array_keys(Schema::tables());
foreach (array_reverse($names) as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
foreach (Schema::tables() as $name => $ddl) { $pdo->exec($ddl); $log("✔ tabela $name"); }
foreach (Schema::indexes() as $ix) { $pdo->exec($ix); }
$log("✅ Schemat gotowy (" . Db::driver() . ").");
