<?php
/** Wspólny bootstrap: sesja, autoryzacja, layout. Dołączany na górze każdej strony. */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Migrator.php';
require_once __DIR__ . '/../src/Engine.php';
require_once __DIR__ . '/../src/Log.php';

session_start();

// Auto-migracja: po deployu baza sama dołoży nowe kolumny/tabele (bez utraty danych).
try { Migrator::ensure(); } catch (Throwable $e) { error_log('Migracja: ' . $e->getMessage()); }

function h($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function money($v): string { return number_format((float) $v, 2, ',', ' '); }
/** Krótki zapis kwot: 1,2 mln · 84 tys. · 950 */
function money_short($v): string {
    $v = (float) $v;
    if ($v >= 1e6) return number_format($v / 1e6, 1, ',', ' ') . ' mln';
    if ($v >= 1e4) return number_format($v / 1e3, 0, ',', ' ') . ' tys.';
    if ($v >= 1e3) return number_format($v / 1e3, 1, ',', ' ') . ' tys.';
    return number_format($v, 0, ',', ' ');
}
/** Etykieta płynności spółki z DNA (liquidity ~0.7-1.5): [klasa css, tekst]. */
function liq_label($liquidity): array {
    $l = (float) $liquidity;
    if ($l >= 1.2)  return ['hi', 'wysoka płynność'];
    if ($l >= 0.95) return ['mid', 'średnia płynność'];
    return ['lo', 'niska płynność'];
}
function flash(string $msg, string $type = 'ok'): void { $_SESSION['flash'] = ['m' => $msg, 't' => $type]; }
function redirect(string $u): void { header("Location: $u"); exit; }

/** Dymek pomocy: znak zapytania z wyjaśnieniem po najechaniu/tapnięciu + link do Pomocy. */
function tip(string $text, string $anchor = ''): string {
    $more = $anchor !== '' ? " <a href='pomoc.php#" . h($anchor) . "'>Dowiedz się więcej →</a>" : '';
    return "<span class='tip' tabindex='0'>?<span class='tipbox'>" . h($text) . $more . "</span></span>";
}

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
    // motyw PRZED stylami (bez mignięcia): zapamiętany wybór gracza, domyślnie jasny
    echo "<script>(function(){var t=null;try{t=localStorage.getItem('theme')}catch(e){}"
       . "if(t!=='dark'&&t!=='light')t='light';document.documentElement.setAttribute('data-theme',t);})();"
       . "function themeToggle(){var r=document.documentElement,t=r.getAttribute('data-theme')==='dark'?'light':'dark';"
       . "r.setAttribute('data-theme',t);try{localStorage.setItem('theme',t)}catch(e){}return false}</script>";
    echo "<title>" . h($title) . " · GPW-gra</title><link rel='stylesheet' href='assets/app.css'></head><body>";
    echo "<header class='topbar'><a class='brand' href='market.php'><span class='mk'>G</span>GPW<span>-gra</span></a><nav>";
    if ($user) {
        $unread = (int) Engine::one("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$user['id']]);
        echo "<a class='" . trim($act('market')) . "' href='market.php'>Rynek</a>";
        echo "<a class='" . trim($act('ranking')) . "' href='ranking.php'>Ranking</a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'>Portfel</a>";
        echo "<a class='" . trim($act('news')) . "' href='wiadomosci.php'>Newsy</a>";
        echo "<a class='" . trim($act('help')) . "' href='pomoc.php'>Pomoc</a>";
        if ($isAdmin) echo "<a class='gm" . $act('gm') . "' href='gm.php'>GM</a>";
        echo "<a class='bell" . $act('notif') . "' href='powiadomienia.php' title='Powiadomienia'>🔔<b class='bell-n" . ($unread > 0 ? '' : ' off') . "' data-bell>" . $unread . "</b></a>";
        echo "<a class='thm' href='#' onclick='return themeToggle()' title='Przełącz motyw jasny/ciemny'>◐</a>";
        echo "<span class='bal'><b>" . money($user['cash']) . " PLN</b><small>zamrożone +" . money($user['cash_reserved']) . "</small></span>";
        echo "<a class='hide-sm' href='logout.php' style='color:var(--faint)'>" . h($user['username']) . " ⏻</a>";
    }
    echo "</nav></header>";
    if ($user) {
        echo "<script>setInterval(async()=>{try{const j=await(await fetch('api_notifications.php')).json();" .
             "const b=document.querySelector('[data-bell]');if(b&&j.ok){b.textContent=j.unread;b.classList.toggle('off',j.unread===0);}}catch(e){}},15000);</script>";
    }
    if ($user) {
        echo "<nav class='bottomnav'>";
        echo "<a class='" . trim($act('market')) . "' href='market.php'><span class='ic'>▤</span>Rynek</a>";
        echo "<a class='" . trim($act('ranking')) . "' href='ranking.php'><span class='ic'>🏆</span>Ranking</a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'><span class='ic'>◈</span>Portfel</a>";
        echo "<a class='" . trim($act('news')) . "' href='wiadomosci.php'><span class='ic'>📰</span>Newsy</a>";
        echo "<a class='" . trim($act('help')) . "' href='pomoc.php'><span class='ic'>❓</span>Pomoc</a>";
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
