<?php
/** Wspólny bootstrap: sesja, autoryzacja, layout. Dołączany na górze każdej strony. */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Engine.php';

session_start();

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float) $v, 2, ',', ' '); }
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = ['m' => $msg, 't' => $type]; }
function redirect(string $u): void { header("Location: $u"); exit; }

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    return Engine::row("SELECT id, username, cash, cash_reserved FROM users WHERE id=?", [$_SESSION['uid']]);
}
function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login.php');
    return $u;
}

function layout_header(string $title, ?array $user): void {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    echo "<!doctype html><html lang='pl'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width,initial-scale=1'>";
    echo "<title>" . h($title) . " · Tycoon.pl</title><link rel='stylesheet' href='assets/app.css'></head><body>";
    echo "<header class='topbar'><a class='brand' href='market.php'>◪ Tycoon<span>.pl</span></a><nav>";
    if ($user) {
        echo "<a href='market.php'>Rynek</a><a href='portfolio.php'>Portfel</a>";
        echo "<span class='cash'>" . money($user['cash']) . " PLN</span>";
        echo "<span class='rsv' title='zamrożone w zleceniach'>+" . money($user['cash_reserved']) . "</span>";
        echo "<a class='who' href='logout.php'>" . h($user['username']) . " · wyloguj</a>";
    }
    echo "</nav></header><main class='wrap'>";
    if ($flash) echo "<div class='flash " . h($flash['t']) . "'>" . h($flash['m']) . "</div>";
}
function layout_footer(): void {
    echo "<footer class='foot'>Tycoon.pl — prototyp MVP · symulacja giełdy · kursy fikcyjne</footer>";
    echo "</main></body></html>";
}
