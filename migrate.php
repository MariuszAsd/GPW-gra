<?php
/** Tworzy schemat od zera. Jedno źródło prawdy (Schema). Uruchom: php migrate.php */
require __DIR__ . '/src/Db.php';
require __DIR__ . '/src/Schema.php';

$pdo = Db::pdo();
$cli = php_sapi_name() === 'cli';
$log = fn($m) => print($cli ? "$m\n" : "<p>$m</p>");

$names = array_keys(Schema::tables());
foreach (array_reverse($names) as $t) $pdo->exec("DROP TABLE IF EXISTS $t");
foreach (Schema::tables() as $name => $ddl) { $pdo->exec($ddl); $log("✔ tabela $name"); }
foreach (Schema::indexes() as $ix) { $pdo->exec($ix); }
$log("✅ Schemat gotowy (" . Db::driver() . ").");
