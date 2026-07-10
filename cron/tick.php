<?php
/**
 * Puls rynku. Na hostingu wpinasz w cron (np. co minutę), lokalnie: php cron/tick.php [ile]
 * Blokada pliku zapobiega nakładaniu się cykli.
 */
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Migrator.php';
require __DIR__ . '/../src/Engine.php';
require __DIR__ . '/../src/Log.php';

try { Migrator::ensure(); } catch (Throwable $e) { error_log('Migracja(cron): ' . $e->getMessage()); }

$lock = __DIR__ . '/tick.lock';
if (is_file($lock) && time() - filemtime($lock) < 120) { fwrite(STDERR, "Poprzedni cykl trwa.\n"); exit(1); }
touch($lock);
register_shutdown_function(fn() => @unlink($lock));

$count = (int) ($argv[1] ?? 1);
$t = 0;
for ($i = 0; $i < $count; $i++) {
    try {
        $t = Engine::runTick();
    } catch (Throwable $e) {
        Log::write('error', 'engine', 'tick.exception', $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
        throw $e;
    }
    if (php_sapi_name() === 'cli') echo "tick #$t ok\n";
}

// QA-bot co N ticków (tylko z CLI/crona — testuje grę przez HTTP jak prawdziwy gracz)
if (php_sapi_name() === 'cli' && $t > 0) {
    $every = max(1, (int) (Engine::one("SELECT v FROM game_state WHERE k='qa_every_ticks'") ?: 30));
    if ($t % $every === 0) {
        require __DIR__ . '/../src/Qa.php';
        $r = Qa::run();
        echo ($r['ok'] ? "QA OK ({$r['checks']} asercji)" : 'QA BŁĘDY: ' . count($r['fails'])) . "\n";
    }
}
