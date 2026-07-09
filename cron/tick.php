<?php
/**
 * Puls rynku. Na hostingu wpinasz w cron (np. co minutę), lokalnie: php cron/tick.php [ile]
 * Blokada pliku zapobiega nakładaniu się cykli.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Migrator.php';
require __DIR__ . '/../src/Engine.php';

try { Migrator::ensure(); } catch (Throwable $e) { error_log('Migracja(cron): ' . $e->getMessage()); }

$lock = __DIR__ . '/tick.lock';
if (is_file($lock) && time() - filemtime($lock) < 120) { fwrite(STDERR, "Poprzedni cykl trwa.\n"); exit(1); }
touch($lock);
register_shutdown_function(fn() => @unlink($lock));

$count = (int) ($argv[1] ?? 1);
for ($i = 0; $i < $count; $i++) {
    $t = Engine::runTick();
    if (php_sapi_name() === 'cli') echo "tick #$t ok\n";
}
