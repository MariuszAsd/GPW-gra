<?php
/**
 * Publiczny status techniczny dla health checka (GitHub Actions) — bez danych graczy i bez sekretów.
 * Odpowiada JSON-em: wersja schematu vs oczekiwana przez kod, obecność tabel nowych funkcji, dostępność
 * push na tym serwerze, faza rynku. Dzięki temu po deployu widać, czy migracje naprawdę się wykonały.
 */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Migrator.php';
require_once __DIR__ . '/../src/Engine.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Push.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, max-age=0');
$out = ['ok' => false, 'schema' => null, 'expected' => Schema::VERSION, 'tables' => [], 'push_available' => false, 'php' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, 'db' => null, 'phase' => null, 'time_pl' => null];
try {
    try { Migrator::ensure(); } catch (Throwable $e) { $out['migrate_error'] = 'migracja nie przeszła (szczegóły w dzienniku)'; }
    $out['db'] = Db::driver();
    $out['schema'] = (int) Engine::one("SELECT version FROM schema_meta WHERE id=1");
    foreach (['equity_snapshots', 'league_results', 'fund_positions', 'push_subscriptions'] as $t) {
        try { Engine::one("SELECT 1 FROM $t LIMIT 1"); $out['tables'][$t] = true; } catch (Throwable $e) { $out['tables'][$t] = false; }
    }
    foreach (['bench_tick', 'share_token', 'weekly_mail'] as $c) {
        try { Engine::one("SELECT $c FROM users LIMIT 1"); $out['tables']["users.$c"] = true; } catch (Throwable $e) { $out['tables']["users.$c"] = false; }
    }
    $out['push_available'] = Push::available();
    $out['phase'] = Engine::marketPhase();
    $out['time_pl'] = Engine::nowWarsaw()->format('Y-m-d H:i');
    $out['tick'] = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
    $out['ok'] = $out['schema'] === Schema::VERSION && !in_array(false, $out['tables'], true);
} catch (Throwable $e) {
    $out['error'] = 'błąd bazy';
}
http_response_code($out['ok'] ? 200 : 503);
echo json_encode($out, JSON_UNESCAPED_UNICODE);
