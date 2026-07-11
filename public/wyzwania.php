<?php
require __DIR__ . '/_boot.php';
$user = require_login();
[$session] = Engine::sessionInfo();

// przełącznik konta: ?ctx=1 -> portfel wyzwania, ?ctx=0 -> konto główne
if (isset($_GET['ctx'])) {
    if ($_GET['ctx'] === '1') { $_SESSION['ctx_challenge'] = 1; flash('Handlujesz teraz portfelem wyzwania. Powodzenia! ⚔️'); redirect('portfolio.php'); }
    unset($_SESSION['ctx_challenge']);
    flash('Wróciłeś na konto główne.');
    redirect('wyzwania.php');
}

// zapis do wyzwania
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['join'])) {
    [$ok, $msg] = Challenges::join((int) $user['id'], (int) $_POST['join']);
    flash($msg, $ok ? 'ok' : 'err');
    redirect('wyzwania.php');
}

$active = Challenges::active();
$myEntry = Challenges::entryFor((int) $user['id']);
$finished = Engine::all("SELECT * FROM challenges WHERE status='finished' ORDER BY id DESC LIMIT 5");

layout_header('Wyzwania', $user, 'challenges');
?>
<h1 style="display:flex;align-items:center;gap:10px">Wyzwania
  <?= tip('Konkurs inwestycyjny na kilkanaście sesji. Wpłacasz buy-in + wpisowe; buy-in trafia na ODDZIELNY portfel wyzwania, którym handlujesz jak zwykłym kontem. Wpisowe wszystkich graczy tworzy pulę nagród — po ostatniej sesji dzielą ją najlepsi. Twoje konto główne przez ten czas gra normalnie dalej.', 'wyzwania') ?>
</h1>

<?php if ($active && $active['status'] === 'signup'): ?>
  <?php
    $fee = round((float) $active['buyin'] * (float) $active['fee_pct'] / 100, 2);
    $entrants = Engine::all("SELECT cp.joined_at, u.username, u.id AS uid FROM challenge_players cp JOIN users u ON u.id=cp.user_id WHERE cp.challenge_id=? ORDER BY cp.id", [$active['id']]);
    $iAmIn = $myEntry && (int) $myEntry['challenge_id'] === (int) $active['id'];
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
      Wpisowe zbiera się w puli — dzielą ją najlepsi (do 5 graczy: zwycięzca bierze wszystko,
      6–11: podział 60/40, od 12: 50/30/20). Wygrywa najwyższy kapitał końcowy portfela wyzwania.
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
<?php elseif ($active && $active['status'] === 'running'): ?>
  <?php
    $board = Challenges::leaderboard((int) $active['id']);
    $iAmIn = $myEntry && (int) $myEntry['challenge_id'] === (int) $active['id'];
    $inCtx = !empty($_SESSION['ctx_challenge']);
  ?>
  <section class="panel" style="margin-bottom:16px">
    <h2>⚔️ <?= h($active['name']) ?> — trwa (do końca sesji #<?= (int) $active['end_session'] ?>, teraz #<?= $session ?>)</h2>
    <p class="muted" style="margin:6px 0 12px">Pula nagród: <b class="up"><?= money($active['pot']) ?> PLN</b> ·
      podział: <?= implode('/', Challenges::payoutSplit(count($board))) ?><?= count($board) <= 5 ? ' (zwycięzca bierze wszystko)' : '' ?> ·
      wynik = kapitał portfela wyzwania (gotówka + akcje po bieżącym kursie).</p>
    <?php if ($iAmIn && !$inCtx): ?>
      <p><a class="btn" href="wyzwania.php?ctx=1">⚔️ Przełącz na portfel wyzwania</a></p>
    <?php elseif ($iAmIn && $inCtx): ?>
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
<?php else: ?>
  <section class="panel" style="margin-bottom:16px">
    <h2>Brak aktywnego wyzwania</h2>
    <p class="muted">Nowa edycja wystartuje automatycznie — dostaniesz powiadomienie 🔔, gdy ruszą zapisy.</p>
  </section>
<?php endif; ?>

<?php if ($finished): ?>
  <section class="panel">
    <h2>🏆 Rozstrzygnięte wyzwania</h2>
    <?php foreach ($finished as $f): ?>
      <?php $res = Engine::all("SELECT cp.*, u.username FROM challenge_players cp JOIN users u ON u.id=cp.user_id WHERE cp.challenge_id=? ORDER BY cp.final_rank", [$f['id']]); ?>
      <h3 style="margin:14px 0 6px"><?= h($f['name']) ?> <small class="muted">(sesje #<?= (int) $f['start_session'] ?>–<?= (int) $f['end_session'] ?>)</small></h3>
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
  </section>
<?php endif; ?>
<?php layout_footer();