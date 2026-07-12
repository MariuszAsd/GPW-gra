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
require_once __DIR__ . '/../src/Mailer.php';
require_once __DIR__ . '/../src/PasswordReset.php';
require_once __DIR__ . '/../src/Daily.php';
require_once __DIR__ . '/../src/Moderation.php';
require_once __DIR__ . '/../src/Bank.php';

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
        'medal'  => '<path d="m8 3 2.5 5M16 3l-2.5 5"/><circle cx="12" cy="14.5" r="5.5"/><path d="m12 12 .8 1.6 1.7.2-1.25 1.2.3 1.7-1.55-.85-1.55.85.3-1.7L9.5 13.8l1.7-.2Z" fill="currentColor" stroke="none"/>',
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

/**
 * Podzakładki modułu (funkcja główna -> podfunkcje): pasek linków pod tytułem.
 * $items: [klucz, href, etykieta]; $active: klucz aktywnej podzakładki.
 */
function subnav(array $items, string $active): void {
    echo "<nav class='subnav'>";
    foreach ($items as [$key, $href, $label]) {
        echo "<a class='" . ($key === $active ? 'on' : '') . "' href='" . h($href) . "'>$label</a>";
    }
    echo "</nav>";
    // na wąskim ekranie pasek się przewija — aktywna podzakładka ma być widoczna od razu
    echo "<script>document.querySelector('.subnav a.on')?.scrollIntoView({block:'nearest',inline:'center'});</script>";
}

/** Podzakładki modułu Rynek — jeden pasek na wszystkich stronach rynkowych. */
function market_subnav(string $active): void {
    subnav([
        ['not', 'market.php', 'Notowania'],
        ['bra', 'branze.php', 'Branże'],
        ['rek', 'rekomendacje.php', 'Rekomendacje'],
        ['ipo', 'ipo.php', 'IPO'],
        ['new', 'wiadomosci.php', 'Newsy i ESPI'],
    ], $active);
}

/** Podzakładki modułu Liga (rywalizacja): Wyzwania, Ranking, Sezon (gdy trwa). */
function liga_subnav(string $active): void {
    $items = [
        ['challenges', 'wyzwania.php', 'Wyzwania'],
        ['ranking', 'ranking.php', 'Ranking'],
    ];
    if (Seasons::active()) $items[] = ['season', 'sezon.php', 'Sezon'];
    subnav($items, $active);
}

/**
 * Wykres kapitału (linia). Oś Y obejmuje CO NAJMNIEJ ±4% wartości — bez tego
 * autoskala min-max rozciąga grosze szumu na całą wysokość i drobny koszt
 * spreadu po zakupie wygląda jak krach. Pod wykresem jawna podziałka.
 */
function equity_svg(array $series, int $height = 110): string {
    if (count($series) < 2) return '';
    $W = 940; $H = $height; $pad = 4;
    $mn = min($series); $mx = max($series);
    $minRange = max(1.0, 0.08 * max(abs($mx), 1));
    if ($mx - $mn < $minRange) { $mid = ($mx + $mn) / 2; $mn = $mid - $minRange / 2; $mx = $mid + $minRange / 2; }
    $rng = $mx - $mn;
    $n = count($series); $pts = [];
    foreach ($series as $i => $v) $pts[] = round($pad + $i / ($n - 1) * ($W - 2 * $pad), 1) . ',' . round($pad + (1 - ($v - $mn) / $rng) * ($H - 2 * $pad), 1);
    $line = implode(' ', $pts);
    $col = end($series) >= reset($series) ? 'var(--up)' : 'var(--down)';
    $delta = reset($series) != 0.0 ? (end($series) / reset($series) - 1) * 100 : 0;
    return "<svg class='idx-chart' style='height:{$H}px' viewBox='0 0 $W $H' preserveAspectRatio='none'>"
        . "<polygon points='$pad,$H $line " . ($W - $pad) . ",$H' fill='$col' opacity='0.10'/>"
        . "<polyline points='$line' fill='none' stroke='$col' stroke-width='1.6' stroke-linejoin='round'/></svg>"
        . "<div class='muted mono' style='display:flex;justify-content:space-between;gap:10px;font-size:10.5px;margin-top:2px'>"
        . "<span>skala: " . money_short($mn) . " – " . money_short($mx) . " PLN</span>"
        . "<span>zmiana w oknie: " . ($delta >= 0 ? '+' : '') . number_format($delta, 2, ',', ' ') . "%</span></div>";
}

/** Kapitał konta = gotówka + zamrożone + akcje po kursie + lokaty i zapisy IPO (to co widzi ranking). */
function user_equity(int $uid): float {
    $u = Engine::row("SELECT cash, cash_reserved FROM users WHERE id=?", [$uid]);
    if (!$u) return 0.0;
    $sv = (float) (Engine::one("SELECT SUM((w.qty + w.qty_reserved) * s.price) FROM wallets w JOIN stocks s ON s.id=w.stock_id WHERE w.user_id=?", [$uid]) ?: 0);
    return round((float) $u['cash'] + (float) $u['cash_reserved'] + $sv + Engine::lockedFunds($uid), 2);
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $u = Engine::row("SELECT id, username, role, cash, cash_reserved FROM users WHERE id=?", [$_SESSION['uid']]);
    // codzienna pętla: pierwszy wjazd dnia podbija serię (strażnik w sesji = zero kosztu potem)
    if ($u && $u['role'] === 'player' && ($_SESSION['daily_day'] ?? '') !== Daily::today()) {
        $_SESSION['daily_day'] = Daily::today();
        Daily::touch((int) $u['id']);
    }
    return $u;
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
            echo "<a href='sklep.php' class='tokens' title='Tokeny Maklera — Sklep'>🪙 <b>$tk</b></a>";
        }
        // główna liczba = KAPITAŁ (gotówka + zamrożone + akcje po kursie) — jak w rankingu;
        // sama gotówka („za ile mogę kupić") schodzi do drugiej linijki
        if ($actg) {
            echo "<span class='bal'><b>" . money(user_equity((int) $actg['id'])) . " PLN</b><small>portfel wyzwania · gotówka " . money($actg['cash']) . "</small></span>";
            echo "<a class='hide-sm' href='logout.php' style='color:var(--faint)'>" . h($actg['owner_name']) . " ⏻</a>";
        } else {
            $balSub = 'gotówka ' . money($user['cash']) . ((float) $user['cash_reserved'] > 0 ? ' · zamrożone ' . money($user['cash_reserved']) : '');
            echo "<span class='bal' title='Kapitał: gotówka + zamrożone + akcje po bieżącym kursie'><b>" . money(user_equity((int) $user['id'])) . " PLN</b><small>" . $balSub . "</small></span>";
            echo "<a class='hide-sm' href='logout.php' style='color:var(--faint)'>" . h($user['username']) . " ⏻</a>";
        }
    }
    echo "</nav></header>";
    if ($user) {
        // rail desktopowy: wszystkie działy GRYWALNE bezpośrednio, konto/premium na dole.
        // Rynek grupuje Notowania/Branże/Rekomendacje/IPO/Newsy (podzakładki), Liga = Wyzwania/Ranking/Sezon.
        $seasonOnNav = Seasons::active();
        echo "<aside class='rail'>";
        echo "<a class='" . trim($act('home')) . "' href='pulpit.php'>" . icon('home') . "<span>Pulpit</span></a>";
        echo "<a class='" . trim($act('market')) . "' href='market.php'>" . icon('chart') . "<span>Rynek</span></a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'>" . icon('case') . "<span>Portfel</span></a>";
        echo "<a class='" . trim($act('challenges')) . "' href='wyzwania.php'>" . icon('flag') . "<span>Wyzwania</span></a>";
        echo "<a class='" . trim($act('ranking')) . "' href='ranking.php'>" . icon('trophy') . "<span>Ranking</span></a>";
        if ($seasonOnNav) echo "<a class='" . trim($act('season')) . "' href='sezon.php'>" . icon('medal') . "<span>Sezon</span></a>";
        if ($user['role'] === 'player') echo "<a class='" . trim($act('shop')) . "' href='sklep.php'>" . icon('shop') . "<span>Sklep</span></a>";
        echo "<a class='" . trim($act('help')) . "' href='pomoc.php'>" . icon('help') . "<span>Pomoc</span></a>";
        echo "<a class='" . trim($act('more')) . "' href='menu.php'>" . icon('user') . "<span>Konto</span></a>";
        if ($isAdmin) echo "<a class='gm" . $act('gm') . "' href='gm.php'>" . icon('gear') . "<span>GM</span></a>";
        echo "</aside>";
    }
    if ($user) {
        echo "<script>setInterval(async()=>{try{const j=await(await fetch('api_notifications.php')).json();" .
             "const b=document.querySelector('[data-bell]');if(b&&j.ok){b.textContent=j.unread;b.classList.toggle('off',j.unread===0);}}catch(e){}},15000);</script>";
    }
    if ($user) {
        // mobilna nawigacja: 5 zakładek. CAŁA grywalność w zasięgu kciuka —
        // Rynek i Liga to moduły z podzakładkami; Konto = profil, premium, ustawienia.
        $ligaActive  = in_array($active, ['challenges', 'ranking', 'season'], true) ? ' active' : '';
        $kontoActive = in_array($active, ['more', 'notif', 'help', 'shop', 'gm', 'account'], true) ? ' active' : '';
        echo "<nav class='bottomnav'>";
        echo "<a class='" . trim($act('home')) . "' href='pulpit.php'>" . icon('home') . "<span>Pulpit</span></a>";
        echo "<a class='" . trim($act('market')) . "' href='market.php'>" . icon('chart') . "<span>Rynek</span></a>";
        echo "<a class='" . trim($act('portfolio')) . "' href='portfolio.php'>" . icon('case') . "<span>Portfel</span></a>";
        echo "<a class='" . trim($ligaActive) . "' href='wyzwania.php'>" . icon('trophy') . "<span>Liga</span></a>";
        echo "<a class='" . trim($kontoActive) . "' href='menu.php'>" . icon('user') . "<span>Konto</span></a>";
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
    // komunikaty jako toast (popup): widoczne też po powrocie z formularza na mobile,
    // znikają same po 5 s albo po tapnięciu
    if ($flash) {
        $fi = ['ok' => '✓', 'err' => '✕', 'info' => 'ℹ'][$flash['t']] ?? 'ℹ';
        echo "<div class='flash toast " . h($flash['t']) . "' onclick='this.remove()' role='status'><b class='fi'>$fi</b><span>" . h($flash['m']) . "</span></div>";
        echo "<script>setTimeout(()=>{var t=document.querySelector('.flash.toast');if(t){t.classList.add('bye');setTimeout(()=>t.remove(),350)}},5000);</script>";
    }
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
