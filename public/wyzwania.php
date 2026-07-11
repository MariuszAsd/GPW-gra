<?php
require __DIR__ . '/_boot.php';
$user = require_login();
[$session] = Engine::sessionInfo();

// przełącznik konta: ?ctx=<id wyzwania> -> portfel tego wyzwania, ?ctx=0 -> konto główne
if (isset($_GET['ctx'])) {
    $cid = (int) $_GET['ctx'];
    if ($cid > 0) {
        $_SESSION['ctx_challenge'] = $cid;
        if ((acting_user($user)['ctx'] ?? '') === 'challenge') { flash('Handlujesz teraz portfelem wyzwania. Powodzenia! ⚔️'); redirect('portfolio.php'); }
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

// archiwum: filtr (wszystkie / tylko moje) + stronicowanie po 5
$archMine = ($_GET['arch'] ?? '') === 'moje';
$archPage = max(0, (int) ($_GET['p'] ?? 0));
$per = 5;
$archWhere = "status='finished'" . ($archMine ? " AND id IN (SELECT challenge_id FROM challenge_players WHERE user_id=" . (int) $user['id'] . ")" : '');
$archTotal = (int) Engine::one("SELECT COUNT(*) FROM challenges WHERE $archWhere");
$archPages = max(1, (int) ceil($archTotal / $per));
$archPage = min($archPage, $archPages - 1);
$finished = Engine::all("SELECT * FROM challenges WHERE $archWhere ORDER BY id DESC LIMIT $per OFFSET " . ($archPage * $per));
$archUrl = fn(int $pg, bool $mine) => 'wyzwania.php?' . http_build_query(array_filter(['arch' => $mine ? 'moje' : null, 'p' => $pg ?: null])) . '#archiwum';

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
<h1 style="display:flex;align-items:center;gap:10px">Wyzwania
  <?= tip('Konkurs inwestycyjny na kilkanaście sesji. Wpłacasz buy-in + wpisowe; buy-in trafia na ODDZIELNY portfel wyzwania, którym handlujesz jak zwykłym kontem. Wpisowe wszystkich graczy tworzy pulę nagród — dzieli ją top ~20% uczestników (im wyższe miejsce, tym większy udział). Może być kilka wyzwań naraz o różnych stawkach — zapisujesz się tam, gdzie chcesz. Twoje konto główne przez ten czas gra normalnie dalej.', 'wyzwania') ?>
</h1>

<?php if (!$signup && !$running): ?>
  <section class="panel" style="margin-bottom:16px">
    <h2>Brak otwartych wyzwań</h2>
    <p class="muted">Nowa edycja wystartuje automatycznie — dostaniesz powiadomienie 🔔, gdy ruszą zapisy.</p>
  </section>
<?php endif; ?>

<?php foreach ($signup as $active): ?>
  <?php
    $fee = round((float) $active['buyin'] * (float) $active['fee_pct'] / 100, 2);
    $entrants = Engine::all("SELECT u.username, u.id AS uid FROM challenge_players cp JOIN users u ON u.id=cp.user_id WHERE cp.challenge_id=? ORDER BY cp.id", [$active['id']]);
    $iAmIn = in_array((int) $active['id'], $myIds, true);
  ?>
  <section class="panel" style="margin-bottom:16px">
    <h2>⚔️ <?= h($active['name']) ?> — zapisy trwają!</h2>
    <div class="ch-grid">
      <div class="ch-stat"><small>BUY-IN (portfel wyzwania)</small><b><?= money($active['buyin']) ?> PLN</b></div>
      <div class="ch-stat"><small>WPISOWE (do puli nagród)</small><b><?= money($fee) ?> PLN</b></div>
      <div class="ch-stat"><small>PULA NAGRÓD</small><b class="up"><?= money($active['pot']) ?> PLN</b></div>
      <div class="ch-stat"><small>START / KONIEC</small><b>sesja #<?= (int) $active['start_session'] ?> → #<?= (int) $active['end_session'] ?></b></div>
      <div class="ch-stat"><small>ZAPISANI (min <?= (int) $active['min_players'] ?>)</small><b><?= count($entrants) ?></b></div>
    </div>
    <p class="muted" style="margin:10px 0">
      Jak to działa: z Twojego konta schodzi <b><?= money((float) $active['buyin'] + $fee) ?> PLN</b>.
      Buy-in wraca po wyzwaniu w takiej formie, w jakiej go doprowadzisz (gotówka + akcje po kursie).
      Wpisowe zbiera się w puli — dzieli ją czołówka: <b><?= split_label(max(count($entrants), (int) $active['min_players'])) ?></b>
      (podział przelicza się z liczbą zapisanych). Wygrywa najwyższy kapitał końcowy portfela wyzwania.
    </p>
    <?php if ($iAmIn): ?>
      <p class="flash ok" style="margin:0">✅ Jesteś zapisany. Start: sesja #<?= (int) $active['start_session'] ?> — dostaniesz powiadomienie.</p>
    <?php elseif (($user['role'] ?? '') === 'player'): ?>
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

<?php foreach ($running as $active): ?>
  <?php
    $board = Challenges::leaderboard((int) $active['id']);
    $iAmIn = in_array((int) $active['id'], $myIds, true);
  ?>
  <section class="panel" style="margin-bottom:16px">
    <h2>⚔️ <?= h($active['name']) ?> — trwa (do końca sesji #<?= (int) $active['end_session'] ?>, teraz #<?= $session ?>)</h2>
    <p class="muted" style="margin:6px 0 12px">Pula nagród: <b class="up"><?= money($active['pot']) ?> PLN</b> ·
      <?= split_label(count($board)) ?> ·
      wynik = kapitał portfela wyzwania (gotówka + akcje po bieżącym kursie).</p>
    <?php if ($iAmIn && $inCtx !== (int) $active['id']): ?>
      <p><a class="btn" href="wyzwania.php?ctx=<?= (int) $active['id'] ?>">⚔️ Przełącz na portfel tego wyzwania</a></p>
    <?php elseif ($iAmIn && $inCtx === (int) $active['id']): ?>
      <p><a class="btn ghost" href="wyzwania.php?ctx=0">Wróć na konto główne</a></p>
    <?php endif; ?>
    <table>
      <thead><tr><th>#</th><th>Gracz</th><th class="num">Kapitał wyzwania</th><th class="num">Wynik</th></tr></thead>
      <tbody>
      <?php foreach ($board as $i => $b): $ret = (float) $b['buyin'] > 0 ? ((float) $b['equity'] / (float) $b['buyin'] - 1) * 100 : 0; ?>
        <tr <?= (int) $b['user_id'] === (int) $user['id'] ? 'style="background:var(--info-bg)"' : '' ?>>
          <td><?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : $i + 1)) ?></td>
          <td><a href="gracz.php?id=<?= (int) $b['user_id'] ?>"><?= h($b['username']) ?></a><?= (int) $b['user_id'] === (int) $user['id'] ? " <span class=\"tag\" style=\"color:var(--accent);border-color:var(--accent)\">Ty</span>" : '' ?></td>
          <td class="num mono"><?= money($b['equity']) ?></td>
          <td class="num"><span class="chg <?= $ret >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $ret >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($ret), 2, ',', ' ') ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </section>
<?php endforeach; ?>

<?php if ($finished || $archMine || $archTotal > 0): ?>
  <section class="panel" id="archiwum">
    <h2 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">🏆 Rozstrzygnięte wyzwania
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
      <table>
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
      </table>
    <?php endforeach; ?>
    <?php if ($archPages > 1): ?>
      <p class="muted" style="margin:12px 0 0">
        <?php if ($archPage > 0): ?><a href="<?= h($archUrl($archPage - 1, $archMine)) ?>">← nowsze</a><?php endif; ?>
        strona <?= $archPage + 1 ?> z <?= $archPages ?>
        <?php if ($archPage < $archPages - 1): ?><a href="<?= h($archUrl($archPage + 1, $archMine)) ?>">starsze →</a><?php endif; ?>
      </p>
    <?php endif; ?>
  </section>
<?php endif; ?>
<?php layout_footer();