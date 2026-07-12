<?php
/**
 * Wyzwania — dashboard: banner CTA zapisów, "jak to działa" w krokach,
 * moje wyzwania (trwające + bilans), otwarte zapisy, tabele na żywo, archiwum.
 */
require __DIR__ . '/_boot.php';
$user = require_login();
[$session] = Engine::sessionInfo();

// przełącznik konta: ?ctx=<id wyzwania> -> portfel tego wyzwania, ?ctx=0 -> konto główne
if (isset($_GET['ctx'])) {
    $cid = (int) $_GET['ctx'];
    if ($cid > 0) {
        $_SESSION['ctx_challenge'] = $cid;
        if ((acting_user($user)['ctx'] ?? '') === 'challenge') { flash('Handlujesz teraz portfelem wyzwania. Powodzenia!'); redirect('portfolio.php'); }
        flash('Nie grasz w tym wyzwaniu albo jeszcze nie wystartowało.', 'err');
    } else {
        unset($_SESSION['ctx_challenge']);
        flash('Wróciłeś na konto główne.');
    }
    redirect('wyzwania.php');
}

// zapis do wyzwania
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join'])) {
    [$ok, $msg] = Challenges::join((int) $user['id'], (int) $_POST['join']);
    flash($msg, $ok ? 'ok' : 'err');
    redirect('wyzwania.php');
}

$all = Challenges::activeAll();
$signup  = array_values(array_filter($all, fn($c) => $c['status'] === 'signup'));
$running = array_values(array_filter($all, fn($c) => $c['status'] === 'running'));
$myIds = array_map(fn($e) => (int) $e['challenge_id'], Challenges::entriesFor((int) $user['id']));
$inCtx = (int) ($_SESSION['ctx_challenge'] ?? 0);
$isPlayer = ($user['role'] ?? '') === 'player';

// banner: pierwsza edycja z otwartymi zapisami, do której NIE jestem zapisany
$heroJoin   = null; $heroJoined = null;
foreach ($signup as $c) {
    if (!in_array((int) $c['id'], $myIds, true)) { $heroJoin = $c; break; }
    $heroJoined = $heroJoined ?? $c;
}

// mój bilans występów + ostatnie wyniki (dashboard "wyzwania w których byłem")
$uid = (int) $user['id'];
$myStats = Engine::row(
    "SELECT COUNT(*) n,
            COALESCE(SUM(CASE WHEN cp.final_rank = 1 THEN 1 ELSE 0 END), 0) wins,
            COALESCE(SUM(CASE WHEN cp.final_rank <= 3 THEN 1 ELSE 0 END), 0) podium,
            COALESCE(SUM(cp.prize), 0) prizes
     FROM challenge_players cp JOIN challenges c ON c.id = cp.challenge_id
     WHERE cp.user_id = ? AND c.status = 'finished'", [$uid]);
$myLast = Engine::all(
    "SELECT cp.final_rank, cp.final_equity, cp.buyin, cp.prize, c.name, c.id AS cid,
            (SELECT COUNT(*) FROM challenge_players x WHERE x.challenge_id = c.id) AS players
     FROM challenge_players cp JOIN challenges c ON c.id = cp.challenge_id
     WHERE cp.user_id = ? AND c.status = 'finished' ORDER BY cp.id DESC LIMIT 5", [$uid]);

// archiwum: filtr (wszystkie / tylko moje) + stronicowanie po 5
$archMine = ($_GET['arch'] ?? '') === 'moje';
$archPage = max(0, (int) ($_GET['p'] ?? 0));
$per = 5;
$archWhere = "status='finished'" . ($archMine ? " AND id IN (SELECT challenge_id FROM challenge_players WHERE user_id=" . $uid . ")" : '');
$archTotal = (int) Engine::one("SELECT COUNT(*) FROM challenges WHERE $archWhere");
$archPages = max(1, (int) ceil($archTotal / $per));
$archPage = min($archPage, $archPages - 1);
$finished = Engine::all("SELECT * FROM challenges WHERE $archWhere ORDER BY id DESC LIMIT $per OFFSET " . ($archPage * $per));
$archUrl = fn(int $pg, bool $mine) => 'wyzwania.php?' . http_build_query(array_filter(['tab' => 'roz', 'arch' => $mine ? 'moje' : null, 'p' => $pg ?: null])) . '#archiwum';

/** Tabela na żywo trwającego wyzwania (renderowana w Moich i w Dostępnych). */
function render_running(array $active, int $uid, int $session): void {
    $board = Challenges::leaderboard((int) $active['id']);
    ?>
  <section class="panel" style="margin-bottom:16px" id="tabela-<?= (int) $active['id'] ?>">
    <h2><?= h($active['name']) ?> — tabela na żywo <small class="muted" style="font-weight:400">(do końca sesji #<?= (int) $active['end_session'] ?>, teraz #<?= $session ?>)</small></h2>
    <p class="muted" style="margin:6px 0 12px">Pula nagród: <b class="up"><?= money($active['pot']) ?> PLN</b> ·
      <?= split_label(count($board)) ?> ·
      wynik = kapitał portfela wyzwania (gotówka + akcje po bieżącym kursie).</p>
    <div style="overflow-x:auto"><table>
      <thead><tr><th>#</th><th>Gracz</th><th class="num">Kapitał wyzwania</th><th class="num">Wynik</th></tr></thead>
      <tbody>
      <?php foreach ($board as $i => $b): $ret = (float) $b['buyin'] > 0 ? ((float) $b['equity'] / (float) $b['buyin'] - 1) * 100 : 0; ?>
        <tr <?= (int) $b['user_id'] === $uid ? 'style="background:var(--info-bg)"' : '' ?>>
          <td><?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : $i + 1)) ?></td>
          <td><a href="gracz.php?id=<?= (int) $b['user_id'] ?>"><?= h($b['username']) ?></a><?= (int) $b['user_id'] === $uid ? " <span class=\"tag\" style=\"color:var(--accent);border-color:var(--accent)\">Ty</span>" : '' ?></td>
          <td class="num mono"><?= money($b['equity']) ?></td>
          <td class="num"><span class="chg <?= $ret >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $ret >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($ret), 2, ',', ' ') ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
    <?php
}

/** krótki opis podziału puli dla N graczy: płatne miejsca + pierwsze udziały */
function split_label(int $n): string {
    $split = Challenges::payoutSplit($n);
    $p = count($split);
    $top = array_slice($split, 0, 3);
    $s = implode(' / ', array_map(fn($x) => number_format($x, $x >= 10 ? 0 : 1, ',', ' ') . '%', $top));
    return "płatne miejsca: $p (top ~20% graczy) · udziały: $s" . ($p > 3 ? ' / …' : '');
}

layout_header('Wyzwania', $user, 'challenges');
?>
<div class="page-head"><h1>Liga</h1></div>
<?php liga_subnav('challenges'); ?>

<?php /* ---------- BANNER CTA: dołącz / jesteś zapisany / co się dzieje ---------- */ ?>
<?php if ($heroJoin && $isPlayer): $fee = round((float) $heroJoin['buyin'] * (float) $heroJoin['fee_pct'] / 100, 2);
      $cnt = (int) Engine::one("SELECT COUNT(*) FROM challenge_players WHERE challenge_id=?", [$heroJoin['id']]); ?>
  <section class="ch-hero">
    <span class="ch-hero-k">Zapisy otwarte · start za <?= max(1, (int) $heroJoin['start_session'] - $session) ?> sesj<?= ((int) $heroJoin['start_session'] - $session) === 1 ? 'ę' : 'e' ?></span>
    <h2>⚔️ <?= h($heroJoin['name']) ?></h2>
    <p>Pula nagród: <b><?= money($heroJoin['pot']) ?> PLN</b> — rośnie z każdym zapisem ·
       zapisanych: <b><?= $cnt ?></b> (min <?= (int) $heroJoin['min_players'] ?>) ·
       handel przez <?= (int) $heroJoin['end_session'] - (int) $heroJoin['start_session'] + 1 ?> sesji</p>
    <form method="post" onsubmit="return confirm('Zapis do wyzwania: z konta zejdzie <?= money((float) $heroJoin['buyin'] + $fee) ?> PLN (buy-in + wpisowe). Buy-in wróci po wyzwaniu. Kontynuować?')">
      <button class="btn" name="join" value="<?= (int) $heroJoin['id'] ?>">Dołączam — <?= money((float) $heroJoin['buyin'] + $fee) ?> PLN</button>
      <small>buy-in <?= money($heroJoin['buyin']) ?> (wraca po wyzwaniu) + wpisowe <?= money($fee) ?> (zasila pulę)</small>
    </form>
  </section>
<?php elseif ($heroJoined): ?>
  <section class="ch-hero joined">
    <span class="ch-hero-k">Jesteś zapisany</span>
    <h2>✅ <?= h($heroJoined['name']) ?></h2>
    <p>Start: sesja #<?= (int) $heroJoined['start_session'] ?> (teraz #<?= $session ?>) — dostaniesz powiadomienie 🔔
       i portfel wyzwania z kwotą <?= money($heroJoined['buyin']) ?> PLN.</p>
  </section>
<?php elseif ($running): ?>
  <section class="ch-hero joined">
    <span class="ch-hero-k">Wyzwanie w toku</span>
    <h2>⚔️ <?= h($running[0]['name']) ?></h2>
    <p>Trwa do końca sesji #<?= (int) $running[0]['end_session'] ?> (teraz #<?= $session ?>).
       Zapisy na kolejną edycję ruszą automatycznie — dostaniesz powiadomienie 🔔.</p>
  </section>
<?php else: ?>
  <section class="ch-hero joined">
    <span class="ch-hero-k">Chwila przerwy</span>
    <h2>⚔️ Kolejna edycja w drodze</h2>
    <p>Nowe wyzwanie wystartuje automatycznie — dostaniesz powiadomienie 🔔, gdy ruszą zapisy.</p>
  </section>
<?php endif; ?>

<?php /* ---------- PODZAKŁADKI: Dostępne / Moje / Rozstrzygnięte ---------- */ ?>
<?php
  $mineActive = [];
  foreach ($all as $c) if (in_array((int) $c['id'], $myIds, true)) $mineActive[] = $c;
  $defTab = $mineActive ? 'moje' : 'dos';   // gram w czymś -> od razu Moje
?>
<div class="subtabs">
  <button class="<?= $defTab === 'dos' ? 'on' : '' ?>" data-tab="dos">Dostępne<?= $signup ? ' (' . count($signup) . ')' : '' ?></button>
  <button class="<?= $defTab === 'moje' ? 'on' : '' ?>" data-tab="moje">Moje<?= $mineActive ? ' (' . count($mineActive) . ')' : '' ?></button>
  <button data-tab="roz">Rozstrzygnięte</button>
</div>

<?php /* ================= MOJE ================= */ ?>
<div class="tabpane <?= $defTab === 'moje' ? 'on' : '' ?>" id="tab-moje">
<section class="panel" style="margin-bottom:16px">
  <h2>Moje wyzwania</h2>
  <?php if (!$mineActive && (int) $myStats['n'] === 0): ?>
    <p class="muted">Jeszcze w żadnym nie grasz. Zajrzyj do zakładki <b>Dostępne</b> — pierwsza edycja uczy najwięcej.</p>
  <?php endif; ?>
  <?php foreach ($mineActive as $c): ?>
    <?php if ($c['status'] === 'signup'): ?>
      <div class="ch-mine">
        <div><b><?= h($c['name']) ?></b><span class="tag" style="margin-left:8px">zapisany</span></div>
        <div class="muted">start: sesja #<?= (int) $c['start_session'] ?> (teraz #<?= $session ?>) · buy-in <?= money($c['buyin']) ?> PLN już zablokowany</div>
      </div>
    <?php else:
        $board = Challenges::leaderboard((int) $c['id']);
        $myRow = null; $myPos = 0;
        foreach ($board as $i => $b) if ((int) $b['user_id'] === $uid) { $myRow = $b; $myPos = $i + 1; break; }
        $ret = $myRow && (float) $myRow['buyin'] > 0 ? ((float) $myRow['equity'] / (float) $myRow['buyin'] - 1) * 100 : 0; ?>
      <div class="ch-mine">
        <div><b><?= h($c['name']) ?></b><span class="tag" style="margin-left:8px;color:var(--accent);border-color:var(--accent)">trwa do sesji #<?= (int) $c['end_session'] ?></span></div>
        <?php if ($myRow): ?>
          <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:baseline;margin-top:4px">
            <span>Miejsce: <b><?= $myPos ?>/<?= count($board) ?></b></span>
            <span>Kapitał wyzwania: <b class="mono"><?= money($myRow['equity']) ?> PLN</b></span>
            <span class="chg <?= $ret >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $ret >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($ret), 2, ',', ' ') ?>%</span>
          </div>
        <?php endif; ?>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
          <?php if ($inCtx !== (int) $c['id']): ?>
            <a class="btn sm" style="width:auto" href="wyzwania.php?ctx=<?= (int) $c['id'] ?>">Handluj portfelem wyzwania</a>
          <?php else: ?>
            <a class="btn sm ghost" style="width:auto" href="wyzwania.php?ctx=0">Wróć na konto główne</a>
          <?php endif; ?>
          <a class="btn sm ghost" style="width:auto" href="#tabela-<?= (int) $c['id'] ?>">Tabela wyników ↓</a>
        </div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
  <?php if ((int) $myStats['n'] > 0): ?>
    <div class="ch-grid" style="margin-top:12px">
      <div class="ch-stat"><small>Starty</small><b><?= (int) $myStats['n'] ?></b></div>
      <div class="ch-stat"><small>Wygrane</small><b><?= (int) $myStats['wins'] ?> 🥇</b></div>
      <div class="ch-stat"><small>Podium</small><b><?= (int) $myStats['podium'] ?></b></div>
      <div class="ch-stat"><small>Nagrody łącznie</small><b class="up">+<?= money($myStats['prizes']) ?> PLN</b></div>
    </div>
    <?php if ($myLast): ?>
      <div style="overflow-x:auto"><table>
        <thead><tr><th>Wyzwanie</th><th class="num">Miejsce</th><th class="num">Wynik</th><th class="num">Nagroda</th></tr></thead>
        <tbody>
        <?php foreach ($myLast as $m): $r = (float) $m['buyin'] > 0 ? ((float) $m['final_equity'] / (float) $m['buyin'] - 1) * 100 : 0; $rk = (int) $m['final_rank']; ?>
          <tr>
            <td><?= h($m['name']) ?></td>
            <td class="num"><?= $rk === 1 ? '🥇' : ($rk === 2 ? '🥈' : ($rk === 3 ? '🥉' : $rk)) ?>/<?= (int) $m['players'] ?></td>
            <td class="num"><span class="chg <?= $r >= 0 ? 'p' : 'n' ?>"><?= number_format($r, 1, ',', ' ') ?>%</span></td>
            <td class="num mono"><?= (float) $m['prize'] > 0 ? '<b class="up">+' . money($m['prize']) . '</b>' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <p class="muted" style="margin:8px 0 0;font-size:12px">Pełne tabele — zakładka <b>Rozstrzygnięte</b>.</p>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php foreach ($running as $active) if (in_array((int) $active['id'], $myIds, true)) render_running($active, $uid, $session); ?>
</div><!-- /tab-moje -->

<?php /* ================= DOSTĘPNE ================= */ ?>
<div class="tabpane <?= $defTab === 'dos' ? 'on' : '' ?>" id="tab-dos">
<?php if (!$signup): ?>
  <section class="panel" style="margin-bottom:16px">
    <h2>Brak otwartych zapisów</h2>
    <p class="muted">Nowa edycja wystartuje automatycznie — dostaniesz powiadomienie 🔔, gdy ruszą zapisy.</p>
  </section>
<?php endif; ?>
<?php /* ---------- OTWARTE ZAPISY (wszystkie edycje — może być kilka o różnych stawkach) ---------- */ ?>
<?php foreach ($signup as $active): ?>
  <?php
    $fee = round((float) $active['buyin'] * (float) $active['fee_pct'] / 100, 2);
    $entrants = Engine::all("SELECT u.username, u.id AS uid FROM challenge_players cp JOIN users u ON u.id=cp.user_id WHERE cp.challenge_id=? ORDER BY cp.id", [$active['id']]);
    $iAmIn = in_array((int) $active['id'], $myIds, true);
  ?>
  <section class="panel" style="margin-bottom:16px">
    <h2><?= h($active['name']) ?> — zapisy trwają</h2>
    <div class="ch-grid">
      <div class="ch-stat"><small>Buy-in (portfel wyzwania)</small><b><?= money($active['buyin']) ?> PLN</b></div>
      <div class="ch-stat"><small>Wpisowe (do puli nagród)</small><b><?= money($fee) ?> PLN</b></div>
      <div class="ch-stat"><small>Pula nagród</small><b class="up"><?= money($active['pot']) ?> PLN</b></div>
      <div class="ch-stat"><small>Start / koniec</small><b>sesja #<?= (int) $active['start_session'] ?> → #<?= (int) $active['end_session'] ?></b></div>
      <div class="ch-stat"><small>Zapisani (min <?= (int) $active['min_players'] ?>)</small><b><?= count($entrants) ?></b></div>
    </div>
    <p class="muted" style="margin:10px 0">
      Z konta schodzi <b><?= money((float) $active['buyin'] + $fee) ?> PLN</b>. Buy-in wraca po wyzwaniu w takiej formie,
      w jakiej go doprowadzisz (gotówka + akcje po kursie). Pulę dzieli czołówka:
      <b><?= split_label(max(count($entrants), (int) $active['min_players'])) ?></b> (podział przelicza się z liczbą zapisanych).
      Wygrywa najwyższy kapitał końcowy portfela wyzwania.
    </p>
    <?php if ($iAmIn): ?>
      <p class="flash ok" style="margin:0">Jesteś zapisany. Start: sesja #<?= (int) $active['start_session'] ?> — dostaniesz powiadomienie.</p>
    <?php elseif ($isPlayer): ?>
      <form method="post" onsubmit="return confirm('Zapis do wyzwania: z konta zejdzie <?= money((float) $active['buyin'] + $fee) ?> PLN (buy-in + wpisowe). Kontynuować?')">
        <button class="btn" name="join" value="<?= (int) $active['id'] ?>">Zapisz się — <?= money((float) $active['buyin'] + $fee) ?> PLN</button>
      </form>
    <?php endif; ?>
    <?php if ($entrants): ?>
      <p class="muted" style="margin:10px 0 0">Na liście:
        <?php foreach ($entrants as $i => $e): ?><?= $i ? ' · ' : '' ?><a href="gracz.php?id=<?= (int) $e['uid'] ?>"><?= h($e['username']) ?></a><?php endforeach; ?>
      </p>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php /* trwające, w których NIE gram — do obserwowania */ ?>
<?php foreach ($running as $active) if (!in_array((int) $active['id'], $myIds, true)) render_running($active, $uid, $session); ?>

<?php /* ---------- JAK TO DZIAŁA: kroki + rozstrzygnięcie + link do pełnych zasad ---------- */ ?>
<section class="panel" style="margin-bottom:16px">
  <h2 style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">Jak działa wyzwanie?
    <a class="btn sm ghost" style="margin-left:auto" href="pomoc.php#wyzwania">Pełne zasady krok po kroku →</a>
  </h2>
  <div class="ch-steps">
    <div class="ch-step"><i>1</i><b>📝 Zgłaszasz się</b><span>z konta schodzi buy-in + wpisowe (znasz kwotę z góry)</span></div>
    <div class="ch-step"><i>2</i><b>📈 Handlujesz</b><span>osobnym portfelem wyzwania — konto główne gra dalej normalnie</span></div>
    <div class="ch-step"><i>3</i><b>🏁 Wracasz po finał</b><span>po ostatniej sesji liczy się kapitał portfela wyzwania</span></div>
    <div class="ch-step"><i>4</i><b>🏆 Rozliczenie</b><span>buy-in wraca każdemu; top ~20% graczy dzieli dodatkowo pulę wpisowych</span></div>
  </div>
  <p class="muted" style="margin:8px 0 0;font-size:12.5px">
    <b class="up">Wygrywasz</b> → nagroda z puli + Tokeny inwestora + punkty sezonu i odznaka.
    <b class="down">Przegrywasz</b> → tracisz najwyżej wpisowe (i ewentualną stratę z handlu) — buy-in w formie, do jakiej go doprowadziłeś (gotówka + akcje), wraca na konto.
  </p>
</section>
</div><!-- /tab-dos -->

<?php /* ================= ROZSTRZYGNIĘTE ================= */ ?>
<div class="tabpane" id="tab-roz">
<?php /* ---------- ARCHIWUM: rozstrzygnięte edycje (wszystkie / tylko moje) ---------- */ ?>
<?php if ($finished || $archMine || $archTotal > 0): ?>
  <section class="panel" id="archiwum">
    <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">Rozstrzygnięte wyzwania
      <span style="font-size:12px;font-weight:400;text-transform:none;letter-spacing:0;margin-left:auto">
        <a href="<?= h($archUrl(0, false)) ?>" <?= !$archMine ? 'style="font-weight:700"' : '' ?>>Wszystkie</a> ·
        <a href="<?= h($archUrl(0, true)) ?>" <?= $archMine ? 'style="font-weight:700"' : '' ?>>Tylko moje</a>
        <span class="muted">(<?= $archTotal ?>)</span>
      </span>
    </h2>
    <?php if (!$finished): ?><p class="muted"><?= $archMine ? 'Nie brałeś jeszcze udziału w żadnym rozstrzygniętym wyzwaniu.' : 'Jeszcze żadne wyzwanie się nie rozstrzygnęło.' ?></p><?php endif; ?>
    <?php foreach ($finished as $f): ?>
      <?php $res = Engine::all("SELECT cp.*, u.username FROM challenge_players cp JOIN users u ON u.id=cp.user_id WHERE cp.challenge_id=? ORDER BY cp.final_rank", [$f['id']]); ?>
      <h3 style="margin:14px 0 6px"><?= h($f['name']) ?> <small class="muted">(sesje #<?= (int) $f['start_session'] ?>–<?= (int) $f['end_session'] ?>, buy-in <?= money($f['buyin']) ?> PLN)</small></h3>
      <div style="overflow-x:auto"><table>
        <thead><tr><th>#</th><th>Gracz</th><th class="num">Kapitał końcowy</th><th class="num">Wynik</th><th class="num">Nagroda</th></tr></thead>
        <tbody>
        <?php foreach ($res as $r): $ret = (float) $r['buyin'] > 0 ? ((float) $r['final_equity'] / (float) $r['buyin'] - 1) * 100 : 0; $rk = (int) $r['final_rank']; ?>
          <tr>
            <td><?= $rk === 1 ? '🥇' : ($rk === 2 ? '🥈' : ($rk === 3 ? '🥉' : $rk)) ?></td>
            <td><a href="gracz.php?id=<?= (int) $r['user_id'] ?>"><?= h($r['username']) ?></a></td>
            <td class="num mono"><?= money($r['final_equity']) ?></td>
            <td class="num"><span class="chg <?= $ret >= 0 ? 'p' : 'n' ?>"><?= number_format($ret, 2, ',', ' ') ?>%</span></td>
            <td class="num mono"><?= (float) $r['prize'] > 0 ? '<b class="up">+' . money($r['prize']) . '</b>' : '—' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endforeach; ?>
    <?php if ($archPages > 1): ?>
      <p class="muted" style="margin:12px 0 0">
        <?php if ($archPage > 0): ?><a href="<?= h($archUrl($archPage - 1, $archMine)) ?>">← nowsze</a><?php endif; ?>
        strona <?= $archPage + 1 ?> z <?= $archPages ?>
        <?php if ($archPage < $archPages - 1): ?><a href="<?= h($archUrl($archPage + 1, $archMine)) ?>">starsze →</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="panel"><h2>Rozstrzygnięte wyzwania</h2>
    <p class="muted">Jeszcze żadna edycja się nie rozstrzygnęła — pierwsze wyniki pojawią się tu po finale.</p></section>
<?php endif; ?>
</div><!-- /tab-roz -->

<script>
document.querySelectorAll('.subtabs button').forEach(b => b.onclick = () => {
  document.querySelectorAll('.subtabs button').forEach(x => x.classList.remove('on'));
  document.querySelectorAll('.tabpane').forEach(x => x.classList.remove('on'));
  b.classList.add('on');
  document.getElementById('tab-' + b.dataset.tab)?.classList.add('on');
});
// głęboki link: ?tab=roz (archiwum/stronicowanie) albo #tabela-N / #archiwum
const wtab = new URLSearchParams(location.search).get('tab');
if (wtab) document.querySelector(`.subtabs button[data-tab="${wtab}"]`)?.click();
else if (location.hash.startsWith('#archiwum')) document.querySelector('.subtabs button[data-tab="roz"]')?.click();
</script>
<?php layout_footer();