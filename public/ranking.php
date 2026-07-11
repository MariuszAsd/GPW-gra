<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$goalTarget = (float) (Engine::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
$goalSessions = (int) (Engine::one("SELECT v FROM game_state WHERE k='goal_sessions'") ?: 0);
[$sessionNo] = Engine::sessionInfo();

// gracze + wartość akcji jednym zapytaniem (podzapytanie zamiast GROUP BY — spójne SQLite/MySQL)
$players = Engine::all(
    "SELECT u.id, u.username, u.cash, u.cash_reserved, u.joined_session, u.goal_session, u.start_equity,
            (SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0)
             FROM wallets w JOIN stocks s ON s.id = w.stock_id WHERE w.user_id = u.id) AS stock_val
     FROM users u WHERE u.is_bot = 0 AND u.role = 'player'"
);
foreach ($players as &$p) {
    $p['equity'] = (float) $p['cash'] + (float) $p['cash_reserved'] + (float) $p['stock_val'];
    $p['ret'] = (float) $p['start_equity'] > 0 ? ($p['equity'] - $p['start_equity']) / $p['start_equity'] * 100 : null;
    $p['won'] = $p['goal_session'] !== null;
    $p['speed'] = $p['won'] ? max(1, (int) $p['goal_session'] - (int) $p['joined_session'] + 1) : null;
}
unset($p);
// kolejność rywalizacji: zwycięzcy wg tempa (najmniej sesji do celu), potem reszta wg kapitału
usort($players, function ($a, $b) {
    if ($a['won'] !== $b['won']) return $a['won'] ? -1 : 1;
    if ($a['won'] && $a['speed'] !== $b['speed']) return $a['speed'] <=> $b['speed'];
    return $b['equity'] <=> $a['equity'];
});

// strategie botów — która wygrywa (średni wynik % od startu)
$strats = Engine::all(
    "SELECT b.strategy, COUNT(*) AS n,
            AVG( (u.cash + u.cash_reserved +
                  (SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0)
                   FROM wallets w JOIN stocks s ON s.id = w.stock_id WHERE w.user_id = u.id)
                  - u.start_equity) / NULLIF(u.start_equity, 0) * 100 ) AS avg_ret
     FROM users u JOIN bots b ON b.user_id = u.id
     WHERE u.is_bot = 1 AND u.start_equity > 0
     GROUP BY b.strategy ORDER BY avg_ret DESC"
);
$stratName = ['mm' => 'Animator rynku', 'trend' => 'Podążający za trendem', 'rsi' => 'Kontrarianin (RSI)', 'fundamental' => 'Fundamentalny', 'news' => 'Gracz newsowy'];

layout_header('Ranking', $user, 'ranking');
$medals = ['🥇', '🥈', '🥉'];
?>
<?php explainer('ranking', 'O co gramy', [
    'cel: pierwszy milion', 'wynik = gotówka + akcje',
    'zbieraj odznaki', 'kliknij nick, aby zobaczyć profil gracza']); ?>
<div class="page-head"><h1>Ranking</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span>
  <?php if ($goalTarget > 0): ?><span class="muted">cel: <?= money($goalTarget) ?> PLN w <?= $goalSessions ?> sesji — zwycięzcy wg tempa, reszta wg kapitału</span><?php endif; ?>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <div class="tbl-scroll">
    <table>
      <thead><tr><th style="width:52px">#</th><th>Gracz</th><th class="num">Kapitał</th><th class="num">Wynik</th><th>Cel</th><th class="num">Dołączył</th></tr></thead>
      <tbody>
      <?php foreach ($players as $i => $p):
          $deadline = (int) $p['joined_session'] + $goalSessions - 1;
          $left = $deadline - $sessionNo; ?>
        <tr <?= (int) $p['id'] === (int) $user['id'] ? 'style="background:var(--info-bg)"' : '' ?>>
          <td class="mono" style="font-size:16px"><?= $medals[$i] ?? ($i + 1) ?></td>
          <td><a href="gracz.php?id=<?= (int) $p['id'] ?>" style="font-weight:700;color:var(--accent)"><?= h($p['username']) ?></a><?php $bn = (int) Engine::one("SELECT COUNT(*) FROM achievements WHERE user_id=?", [$p['id']]); ?><?= $bn > 0 ? " <span class='tag' title='zdobyte odznaki: $bn z " . count(Achievements::all()) . "'>🎖️$bn</span>" : '' ?><?= (int) $p['id'] === (int) $user['id'] ? ' <span class="tag" style="color:var(--accent);border-color:var(--accent)">Ty</span>' : '' ?></td>
          <td class="num mono"><?= money($p['equity']) ?></td>
          <td class="num"><?php if ($p['ret'] === null): ?><span class="muted">—</span>
            <?php else: ?><span class="chg <?= $p['ret'] >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $p['ret'] >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($p['ret']), 1, ',', ' ') ?>%</span><?php endif; ?></td>
          <td>
            <?php if ($p['won']): ?><span class="up">🏆 w <?= $p['speed'] ?> sesji</span>
            <?php elseif ($goalTarget <= 0): ?><span class="muted">—</span>
            <?php elseif ($left >= 0): ?><span class="soft">w grze · zostało sesji: <?= $left ?></span>
            <?php else: ?><span class="muted">czas minął</span><?php endif; ?>
          </td>
          <td class="num muted">#<?= (int) $p['joined_session'] ?></td>
        </tr>
      <?php endforeach; if (!$players) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Brak graczy.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($strats): ?>
<div class="panel" style="padding:0;overflow:hidden;margin-top:16px">
  <div style="padding:14px 16px 0"><h2>Boty — która strategia wygrywa</h2></div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th style="width:52px">#</th><th>Strategia</th><th class="num">Botów</th><th class="num">Śr. wynik od startu</th></tr></thead>
      <tbody>
      <?php foreach ($strats as $i => $s): $r = (float) $s['avg_ret']; ?>
        <tr>
          <td class="mono"><?= $i + 1 ?></td>
          <td><b><?= h($stratName[$s['strategy']] ?? $s['strategy']) ?></b> <span class="muted mono"><?= h($s['strategy']) ?></span></td>
          <td class="num"><?= (int) $s['n'] ?></td>
          <td class="num"><span class="chg <?= $r >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $r >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($r), 2, ',', ' ') ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="muted" style="padding:10px 16px 14px;margin:0;font-size:12px">Wynik = zmiana kapitału (gotówka + akcje) od startu świata. Zmierz się z najlepszą strategią!</p>
</div>
<?php endif; ?>
<?php layout_footer();
