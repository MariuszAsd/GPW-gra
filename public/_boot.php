<?php
/** Wspólny bootstrap: sesja, autoryzacja, layout. Dołączany na górze każdej strony. */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Migrator.php';
require_once __DIR__ . '/../src/Engine.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Challenges.php';
require_once __DIR__ . '/../src/Ipo.php';
require_once __DIR__ . '/../src/Technical.php';
require_once __DIR__ . '/../src/Tokens.php';
require_once __DIR__ . '/../src/Recommendations.php';
require_once __DIR__ . '/../src/Payments.php';
require_once __DIR__ . '/../src/Cosmetics.php';
require_once __DIR__ . '/../src/Seasons.php';

session_set_cookie_params(['samesite' => 'Lax', 'httponly' => true]);
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

/** Ikony interfejsu (inline SVG, dziedziczą kolor) — zamiast emoji w chrome aplikacji. */
function icon(string $name, string $cls = 'ico'): string {
    $paths = [
        'home'   => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V20a1 1 0 0 0 1 1h11a1 1 0 0 0 1-1V9.5"/>',
        'chart'  => '<path d="M3 3v17a1 1 0 0 0 1 1h17"/><path d="m7 14 4-5 3.5 3L20 6"/>',
        'trophy' => '<path d="M8 21h8m-4-4v4M7 4h10v5a5 5 0 0 1-10 0Z"/><path d="M7 6H4v1a3 3 0 0 0 3 3m10-4h3v1a3 3 0 0 1-3 3"/>',
        'flag'   => '<path d="M5 21V4"/><path d="M5 4h13l-2.5 4L18 12H5"/>',
        'case'   => '<rect x="3" y="7.5" width="18" height="13" rx="2"/><path d="M9 7.5V5.5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5.5v2M3 13h18"/>',
        'news'   => '<rect x="3" y="5" width="15" height="16" rx="2"/><path d="M18 9h2a1 1 0 0 1 1 1v9a2 2 0 0 1-2 2M7 9.5h7M7 13h7m-7 3.5h4"/>',
        'help'   => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.3a2.6 2.6 0 0 1 5.1.7c0 1.7-2.6 2.2-2.6 3.7"/><circle cx="12" cy="17" r=".4" fill="currentColor"/>',
        'gear'   => '<path d="M4 7h10m4 0h2M4 12h4m4 0h8M4 17h12m2 0h2"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="17" r="2"/>',
        'bell'   => '<path d="M18 9a6 6 0 1 0-12 0c0 6-2.5 7-2.5 7h17S18 15 18 9"/><path d="M10.3 20a2 2 0 0 0 3.4 0"/>',
        'theme'  => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17A8.5 8.5 0 0 0 12 3.5Z" fill="currentColor" stroke="none"/>',
        'exit'   => '<path d="M14 4h-8a1 1 0 0 0-1 1v14a1 1 0 0 0 1 1h8m-4-8h11m-3.5-3.5L21 12l-3.5 3.5"/>',
        'more'   => '<circle cx="5" cy="12" r="1.4" fill="currentColor"/><circle cx="12" cy="12" r="1.4" fill="currentColor"/><circle cx="19" cy="12" r="1.4" fill="currentColor"/>',
        'shop'   => '<path d="M6.3 8h11.4l-1 12.1a1 1 0 0 1-1 .9H8.3a1 1 0 0 1-1-.9Z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/>',
        'book'   => '<path d="M5 4.5A1.5 1.5 0 0 1 6.5 3H19v15.5H6.75A1.75 1.75 0 0 0 5 20.25Z"/><path d="M5 20.25V4.5M9 7.5h6"/>',
        'user'   => '<circle cx="12" cy="8" r="4"/><path d="M4.5 20.5c1.4-3.8 4.6-5.5 7.5-5.5s6.1 1.7 7.5 5.5"/>',
    ];
    return "<svg class='$cls' viewBox='0 0 24 24' aria-hidden='true'>" . ($paths[$name] ?? '') . '</svg>';
}
function redirect(string $u): void { header("Location: $u"); exit; }

/**
 * Podpowiedź nad działem: prosta infografika "o co tu chodzi" z opcją ukrycia.
 * ✕ pyta: "tylko teraz" (sessionStorage) czy "na zawsze" (localStorage).
 * Przywracanie wszystkich: przycisk w Samouczku. Kroki to zaufany HTML z kodu.
 */
function explainer(string $key, string $title, array $steps): void {
    echo "<div class='expl' data-exp='" . h($key) . "' hidden>";
    echo "<button class='expl-x' title='Ukryj podpowiedź' onclick='explAsk(this)'>✕</button>";
    echo "<span class='expl-menu' hidden>Ukryć tę podpowiedź? ";
    echo "<button class='btn sm ghost' onclick='explOnce(this)'>Tylko teraz</button> ";
    echo "<button class='btn sm ghost' onclick='explForever(this)'>Nie pokazuj więcej</button></span>";
    echo "<b class='expl-t'>" . h($title) . "</b><span class='expl-steps'>";
    foreach ($steps as $i => $s) {
        if ($i > 0) echo "<span class='expl-arr'>→</span>";
        echo "<span class='expl-step'>$s</span>";
    }
    echo "</span></div>";
}

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

/**
 * Konto, którym gracz AKTUALNIE handluje: główne albo subkonto trwającego
 * wyzwania (przełącznik na stronie Wyzwania). Strony transakcyjne
 * (zlecenia, portfel) używają tego zamiast require_login().
 */
function acting_user(array $u): array {
    if (($u['role'] ?? '') !== 'player' || empty($_SESSION['ctx_challenge'])) return $u;
    $row = Engine::row(
        "SELECT su.id, su.username, su.role, su.cash, su.cash_reserved, c.name AS ch_name, c.id AS ch_id, c.end_session AS ch_end
         FROM challenge_players cp
         JOIN challenges c ON c.id = cp.challenge_id AND c.status = 'running'
         JOIN users su ON su.id = cp.shadow_user_id
         WHERE cp.user_id = ? AND cp.challenge_id = ?", [$u['id'], (int) $_SESSION['ctx_challenge']]);
    if (!$row) { unset($_SESSION['ctx_challenge']); return $u; }
    $row['ctx'] = 'challenge';
    $row['owner_id'] = (int) $u['id'];
    $row['owner_name'] = $u['username'];
    return $row;
}

function layout_header(string $title, ?array $user, string $active = ''): void {
    $flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
    $isAdmin = $user && ($user['role'] ?? '') === 'admin';
    $act = fn($k) => $active === $k ? ' active' : '';
    // kontekst wyzwania: strona mogła podać już konto-cień (acting_user) albo zwykłe konto gracza
    $actg = null;
    if ($user && ($user['ctx'] ?? '') === 'challenge') $actg = $user;
    elseif ($user && ($user['role'] ?? '') === 'player' && !empty($_SESSION['ctx_challenge'])) {
        $tmp = acting_user($user);
        if (($tmp['ctx'] ?? '') === 'challenge') $actg = $tmp;
    }
    echo "<!doctype html><html lang='pl'><head><meta charset='utf-8'>";
    echo "<meta name='viewport' content='width=device-width,initial-scale=1'>";
    // motyw PRZED stylami (bez mignięcia): zapamiętany wybór gracza, domyślnie jasny
    echo "<script>(function(){var t=null;try{t=localStorage.getItem('theme')}catch(e){}"
       . "if(t!=='dark'&&t!=='light')t='light';document.documentElement.setAttribute('data-theme',t);})();"
       . "function themeToggle(){var r=document.documentElement,t=r.getAttribute('data-theme')==='dark'?'light':'dark';"
       . "r.setAttribute('data-theme',t);try{localStorage.setItem('theme',t)}catch(e){}return false}</script>";
    echo "<title>" . h($title) . " · GPW-gra</title><link rel='stylesheet' href='assets/app.css'></head><body>";
    echo "<header class='topbar'><a class='brand' href='pulpit.php'><span class='mk'>G</span>GPW<span>-gra</span></a><nav>";
    if ($user) {
        $bellId = $actg ? (int) $actg['owner_id'] : (int) $user['id'];
        $unread = (int) Engine::one("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$bellId]);
        echo "<a class='bell" . $act('notif') . "' href='powiadomienia.php' title='Powiadomienia'>" . icon('bell') . "<b class='bell-n" . ($unread > 0 ? '' : ' off') . "' data-bell>" . $unread . "</b></a>";
        echo "<a class='thm' href='#' onclick='return themeToggle()' title='Przełącz motyw jasny/ciemny'>" . icon('theme') . "</a>";
        if ($user['role'] === 'player') {
            $tk = (int) Engine::one("SELECT tokens FROM users WHERE id=?", [$actg ? $actg['owner_id'] : $user['id']]);
            echo "<a href='sklep.php' class='tokens' title='Żetony Maklera — Sklep'>🪙 <b>$tk</b></a>";
        }
        if ($actg) {
            echo "<span class='bal'><b>" . money($actg['cash']) . " PLN</b><small>portfel wyzwania</small></span>";
            echo "<a class='hide-sm' href='logout.php' style='color:var(--faint)'>" . h($actg['owner_name']) . " ⏻</a>";
        } else {
            echo "<span class='bal'><b>" . money($user['cash']) . " PLN</b><small>zamrożone +" . money($user['cash_reserved']) . "</small></span>";
            echo "<a class='hide-sm' href='logout.php' style='color:var(--faint)'>" . h($user['username']) . " ⏻</a>";
        }
    }
    echo "</nav></header>";
    if ($user) {
        echo "<aside class='rail'>";
        echo "<a class='" . trim($act('home')) . "' href='pulpit.php'>" . icon('home') . "<span>Pulpit</span></a>";
        echo "<a class='" . trim($act('market')) . "' href='market.php'>" . icon('chart') . "<span>Rynek</span></a>";
        echo "<a class='" . trim($act('ranking')) . "' href='ranking.php'>" . icon('trophy') . "<span>Ranking</span></a>";
        echo "<a class='" . trim($act('challenges')) . "' href='wyzwania.php'>" . icon('flag') . "<span>Wyzwania</span></a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'>" . icon('case') . "<span>Portfel</span></a>";
        echo "<a class='" . trim($act('news')) . "' href='wiadomosci.php'>" . icon('news') . "<span>Newsy</span></a>";
        echo "<a class='" . trim($act('help')) . "' href='pomoc.php'>" . icon('help') . "<span>Pomoc</span></a>";
        if ($isAdmin) echo "<a class='gm" . $act('gm') . "' href='gm.php'>" . icon('gear') . "<span>GM</span></a>";
        echo "</aside>";
    }
    if ($user) {
        echo "<script>setInterval(async()=>{try{const j=await(await fetch('api_notifications.php')).json();" .
             "const b=document.querySelector('[data-bell]');if(b&&j.ok){b.textContent=j.unread;b.classList.toggle('off',j.unread===0);}}catch(e){}},15000);</script>";
    }
    if ($user) {
        // mobilna nawigacja: 5 zakładek (reszta w "Więcej") — wzór aplikacji tradingowych
        $moreActive = in_array($active, ['ranking', 'news', 'help', 'notif', 'gm', 'more'], true) ? ' active' : '';
        echo "<nav class='bottomnav'>";
        echo "<a class='" . trim($act('home')) . "' href='pulpit.php'>" . icon('home') . "<span>Pulpit</span></a>";
        echo "<a class='" . trim($act('market')) . "' href='market.php'>" . icon('chart') . "<span>Rynek</span></a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'>" . icon('case') . "<span>Portfel</span></a>";
        echo "<a class='" . trim($act('challenges')) . "' href='wyzwania.php'>" . icon('flag') . "<span>Wyzwania</span></a>";
        echo "<a class='" . trim($moreActive) . "' href='menu.php'>" . icon('more') . "<span>Więcej</span></a>";
        echo "</nav>";
    }
    // baner kontekstu: gracz handluje teraz portfelem wyzwania (widoczny na każdej stronie)
    if ($actg) {
        echo "<div class='ctxbar'>Handlujesz portfelem wyzwania <b>" . h($actg['ch_name']) . "</b>"
           . " · gotówka: <b>" . money($actg['cash']) . " PLN</b>"
           . " · do końca sesji #" . (int) $actg['ch_end']
           . " · <a href='wyzwania.php?ctx=0'>wróć na konto główne</a></div>";
    }
    echo "<main class='wrap'>";
    if ($flash) echo "<div class='flash " . h($flash['t']) . "'>" . h($flash['m']) . "</div>";
}
function layout_footer(): void {
    echo "<div class='foot'>GPW-gra · symulacja giełdy · kursy fikcyjne</div>";
    // podpowiedzi nad działami: pokaż tylko nieukryte; ✕ pyta "raz czy na zawsze"
    echo "<script>document.querySelectorAll('.expl').forEach(function(e){var k='exp_'+e.dataset.exp;"
       . "try{if(!localStorage.getItem(k)&&!sessionStorage.getItem(k))e.hidden=false}catch(_){e.hidden=false}});"
       . "function explAsk(b){b.hidden=true;b.closest('.expl').querySelector('.expl-menu').hidden=false}"
       . "function explOnce(b){var e=b.closest('.expl');try{sessionStorage.setItem('exp_'+e.dataset.exp,'1')}catch(_){}e.remove()}"
       . "function explForever(b){var e=b.closest('.expl');try{localStorage.setItem('exp_'+e.dataset.exp,'1')}catch(_){}e.remove()}</script>";
    echo "</main></body></html>";
}
