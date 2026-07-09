<?php
/**
 * Jednorazowy instalator webowy (bez potrzeby SSH).
 * Zakłada tabele + wypełnia świat. BEZPIECZNY:
 *   - odmawia działania, jeśli baza jest już założona (nie skasuje danych),
 *   - wymaga potwierdzenia (?run=1).
 * Po udanej instalacji USUŃ ten plik.
 */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Engine.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!doctype html><meta charset=utf-8><title>Instalator GPW-gra</title>";
echo "<body style='font-family:system-ui,Arial,sans-serif;max-width:640px;margin:48px auto;padding:0 18px;color:#1a1d24;line-height:1.6'>";
echo "<h1 style='letter-spacing:-.02em'>Instalator GPW-gra</h1>";

// 1) połączenie z bazą
try {
    Db::pdo();
} catch (Throwable $e) {
    echo "<p style='color:#b02a24'><b>Brak połączenia z bazą.</b> Dodaj sekrety <code>DB_HOST/DB_NAME/DB_USER/DB_PASS</code> "
       . "w GitHub → Settings → Secrets, zrób ponowny deploy (zbuduje <code>config.local.php</code>), potem odśwież tę stronę.</p>";
    echo "<pre style='background:#f4f4f6;padding:12px;border-radius:8px;overflow:auto'>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}

// 2) już zainstalowane? -> nie ruszaj danych
$installed = false;
try { $installed = ((int) Engine::one("SELECT COUNT(*) FROM stocks")) > 0; } catch (Throwable $e) { $installed = false; }
if ($installed) {
    echo "<p style='color:#1c7a4e'><b>✔ Baza jest już założona.</b> Instalator nic nie robi (nie skasuje danych).</p>";
    echo "<p>Dla bezpieczeństwa usuń plik <code>public/install.php</code>. &nbsp; <a href='market.php'>Wejdź do gry →</a></p>";
    exit;
}

// 3) potwierdzenie
if (($_GET['run'] ?? '') !== '1') {
    $driver = Db::driver();
    echo "<p>Baza docelowa: <b>" . htmlspecialchars($driver) . "</b>.</p>";
    if ($driver !== 'mysql') {
        echo "<p style='color:#b5641a'><b>Uwaga:</b> brak konfiguracji MySQL (pliku <code>config.local.php</code>). "
           . "Zainstaluję na lokalnym SQLite. Jeśli chcesz MySQL — dodaj sekrety <code>DB_*</code> i zrób ponowny deploy, potem odśwież.</p>";
    }
    echo "<p>Ta operacja utworzy tabele i wypełni świat: 4 spółki, 30 botów i konto demo.</p>";
    echo "<p><a href='install.php?run=1' style='display:inline-block;padding:11px 18px;background:#1c7a4e;color:#fff;border-radius:8px;text-decoration:none;font-weight:600'>Zainstaluj teraz</a></p>";
    exit;
}

// 4) instalacja
define('GPW_ALLOW_SETUP', true);       // odblokuj migrate/seed (chronione przed HTTP)
echo "<div style='background:#f4f4f6;padding:14px 16px;border-radius:8px'>";
require __DIR__ . '/../migrate.php';   // DROP+CREATE (baza jest pusta, sprawdzone wyżej)
require __DIR__ . '/../seed.php';      // spółki + boty + gracz demo
echo "</div>";

echo "<p style='color:#1c7a4e;font-size:1.1em'><b>✅ Gotowe!</b> Tabele założone, świat wypełniony.</p>";
echo "<ol>";
echo "<li><b>USUŃ plik <code>public/install.php</code></b> (bezpieczeństwo).</li>";
echo "<li>Ustaw <b>cron</b> co minutę: <code>php /ścieżka/do/aplikacji/cron/tick.php 1</code> — to animuje rynek.</li>";
echo "</ol>";
echo "<p><a href='market.php' style='font-weight:600'>Wejdź do gry →</a> &nbsp; login: <code>gracz</code> / <code>haslo123</code></p>";
