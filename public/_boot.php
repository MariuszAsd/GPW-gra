<?php
/** Wspólny bootstrap: sesja, autoryzacja, layout. Dołączany na górze każdej strony. */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Migrator.php';
require_once __DIR__ . '/../src/Engine.php';

session_start();

// Auto-migracja: po deployu baza sama dołoży nowe kolumny/tabele (bez utraty danych).
try { Migrator::ensure(); } catch (Throwable $e) { error_log('Migracja: ' . $e->getMessage()); }

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float) $v, 2, ',', ' '); }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = ['m' => $msg, 't' => $type]; }
function redirect(string $u): void { header("Location: $u"); exit; }

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    return Engine::row("SELECT id, username, role, cash, cash_reserved FROM users WHERE id=?", [$_SESSION['uid']]);
}
function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}

function layout_header(string $title, ?array $user, string $active = ''): void {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    $isAdmin = $user && ($user['role'] ?? '') === 'admin';
    $act = fn($k) => $active === $k ? ' active' : '';
    echo "<!doctype html><html lang='pl'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . h($title) . " · GPW-gra</title><link rel='stylesheet' href='assets/app.css'></head><body>";
    echo "<header class='topbar'><a class='brand' href='market.php'><span class='mk'>G</span>GPW<span>-gra</span></a><nav>";
    if ($user) {
        echo "<a class='" . trim($act('market')) . "' href='market.php'>Rynek</a>";
        echo "<a class='" . trim($act('ranking')) . "' href='ranking.php'>Ranking</a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'>Portfel</a>";
        if ($isAdmin) echo "<a class='gm" . $act('gm') . "' href='gm.php'>GM</a>";
        echo "<span class='bal'><b>" . money($user['cash']) . " PLN</b><small>zamrożone +" . money($user['cash_reserved']) . "</small></span>";
        echo "<a class='hide-sm' href='logout.php' style='color:var(--faint)'>" . h($user['username']) . " ⏻</a>";
    }
    echo "</nav></header>";
    if ($user) {
        echo "<nav class='bottomnav'>";
        echo "<a class='" . trim($act('market')) . "' href='market.php'><span class='ic'>▤</span>Rynek</a>";
        echo "<a class='" . trim($act('ranking')) . "' href='ranking.php'><span class='ic'>🏆</span>Ranking</a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'><span class='ic'>◈</span>Portfel</a>";
        if ($isAdmin) echo "<a class='" . trim($act('gm')) . "' href='gm.php'><span class='ic'>⚙</span>GM</a>";
        echo "<a href='logout.php'><span class='ic'>⏻</span>Wyjście</a>";
        echo "</nav>";
    }
    echo "<main class='wrap'>";
    if ($flash) echo "<div class='flash " . h($flash['t']) . "'>" . h($flash['m']) . "</div>";
}
function layout_footer(): void {
    echo "<div class='foot'>GPW-gra · symulacja giełdy · kursy fikcyjne</div>";
    echo "</main></body></html>";
}
