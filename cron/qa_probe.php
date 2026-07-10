<?php
/**
 * QA-bot: ręczne/cronowe uruchomienie testu gry przez HTTP.
 *   php cron/qa_probe.php            (adres z config: app_url)
 *   APP_URL=http://127.0.0.1:8080 php cron/qa_probe.php
 * Wyniki trafiają do dziennika logów (panel GM -> Dziennik).
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Forbidden'); }
require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Migrator.php';
require __DIR__ . '/../src/Engine.php';
require __DIR__ . '/../src/Log.php';
require __DIR__ . '/../src/Qa.php';

try { Migrator::ensure(); } catch (Throwable $e) {}

$r = Qa::run();
echo ($r['ok'] ? "✅ QA OK" : "❌ QA BŁĘDY") . " — asercji: {$r['checks']}\n";
foreach ($r['fails'] as $f) echo "  - $f\n";
exit($r['ok'] ? 0 : 1);
