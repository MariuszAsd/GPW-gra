<?php
/** Publiczny profil gracza: kapitał, wykres, odznaki, zamknięte pozycje, ostatnie ruchy. */
require __DIR__ . '/_boot.php';
$user = require_login();

$pid = (int) ($_GET['id'] ?? 0);
$p = Engine::row("SELECT id, username, is_bot, role, cash, cash_reserved, joined_session, goal_session, start_equity, title, frame FROM users WHERE id=?", [$pid]);
$isMe = $p && (int) $p['id'] === (int) $user['id'];
$viewerAdmin = ($user['role'] ?? '') === 'admin';
if (!$p || (int) $p['is_bot'] === 1 || ($p['role'] !== 'player' && !$isMe && !$viewerAdmin)) {
    flash('Nie ma takiego gracza.', 'err');
    redirect('ranking.php');
}

$stockVal = (float) (Engine::one("SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0) FROM wallets w JOIN stocks s ON s.id=w.stock_id WHERE w.user_id=?", [$pid]) ?: 0);
$equity = (float) $p['cash'] + (float) $p['cash_reserved'] + $stockVal;
$ret = (float) $p['start_equity'] > 0 ? ($equity / (float) $p['start_equity'] - 1) * 100 : 0;
$txCount = (int) Engine::one("SELECT COUNT(*) FROM transactions WHERE buyer_id=? OR seller_id=?", [$pid, $pid]);
$posCount = (int) Engine::one("SELECT COUNT(*) FROM wallets WHERE user_id=? AND (qty + qty_reserved) > 0", [$pid]);

// wykres kapitału
$eqSeries = array_reverse(array_map('floatval', Engine::col("SELECT equity FROM equity_history WHERE user_id=? ORDER BY t DESC LIMIT 150", [$pid])));
$eqSvg = equity_svg($eqSeries);

// wyzwania: bilans występów w rozstrzygniętych edycjach
$chStats = Engine::row(
    "SELECT COUNT(*) n,
            COALESCE(SUM(CASE WHEN cp.final_rank = 1 THEN 1 ELSE 0 END), 0) wins,
            COALESCE(SUM(CASE WHEN cp.final_rank <= 3 THEN 1 ELSE 0 END), 0) podium,
            COALESCE(SUM(cp.prize), 0) prizes,
            MAX(CASE WHEN cp.buyin > 0 THEN cp.final_equity / cp.buyin - 1 END) best
     FROM challenge_players cp JOIN challenges c ON c.id = cp.challenge_id
     WHERE cp.user_id = ? AND c.status = 'finished'", [$pid]);
$chLast = ((int) $chStats['n']) > 0 ? Engine::all(
    "SELECT c.name, cp.final_rank, cp.final_equity, cp.buyin, cp.prize,
            (SELECT COUNT(*) FROM challenge_players x WHERE x.challenge_id = c.id) total
     FROM challenge_players cp JOIN challenges c ON c.id = cp.challenge_id
     WHERE cp.user_id = ? AND c.status = 'finished' ORDER BY c.id DESC LIMIT 6", [$pid]) : [];

// odznaki
$earned = [];
foreach (Engine::all("SELECT code, earned_at FROM achievements WHERE user_id=? ORDER BY id", [$pid]) as $a) $earned[$a['code']] = $a['earned_at'];
$catalog = Achievements::all();

// zamknięte pozycje (top wg wyniku, metoda średniego kosztu — jak w Portfelu)
$allTx = Engine::all("SELECT t.stock_id, t.qty, t.price, t.buyer_id, s.ticker FROM transactions t JOIN stocks s ON s.id=t.stock_id
                      WHERE t.buyer_id=? OR t.seller_id=? ORDER BY t.id", [$pid, $pid]);
$feeRate = Engine::feeRate();
$closed = []; $lots = [];
foreach ($allTx as $t) {
    $sid2 = (int) $t['stock_id']; $q2 = (int) $t['qty']; $p2 = (float) $t['price'];
    if (!isset($lots[$sid2])) $lots[$sid2] = ['qty' => 0, 'avg' => 0.0];
    $L = &$lots[$sid2];
    if ((int) $t['buyer_id'] === $pid) { $L['avg'] = $L['qty'] + $q2 > 0 ? ($L['qty'] * $L['avg'] + $q2 * $p2) / ($L['qty'] + $q2) : 0; $L['qty'] += $q2; }
    else {
        $sellQ = min($q2, $L['qty']); if ($sellQ <= 0) continue;
        $val = $sellQ * $p2; $net = $val - round($val * $feeRate, 2);
        if (!isset($closed[$sid2])) $closed[$sid2] = ['ticker' => $t['ticker'], 'pl' => 0.0];
        $closed[$sid2]['pl'] += $net - $sellQ * $L['avg'];
        $L['qty'] -= $sellQ;
    }
    unset($L);
}
uasort($closed, fn($a, $b) => $b['pl'] <=> $a['pl']);
$realized = array_sum(array_column($closed, 'pl'));

layout_header('Profil: ' . $p['username'], $user, 'ranking');
?>
<div class="page-head">
  <span class="avatar<?= in_array($p['frame'], ['gold', 'silver'], true) ? ' frame-' . h($p['frame']) : '' ?>"><?= mb_strtoupper(mb_substr($p['username'], 0, 1)) ?></span>
  <h1><?= h($p['username']) ?><?= $isMe ? ' <span class="muted" style="font-size:14px">(to Ty)</span>' : '' ?></h1>
  <?php if (trim((string) $p['title']) !== ''): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)"><?= h($p['title']) ?></span><?php endif; ?>
  <?php if ($p['goal_session'] !== null): ?><span class="chg p">🏆 cel osiągnięty (sesja #<?= (int) $p['goal_session'] ?>)</span><?php endif; ?>
  <span class="muted">w grze od sesji #<?= (int) $p['joined_session'] ?></span>
  <?php if ($isMe || $viewerAdmin): ?><a class="btn sm ghost" style="margin-left:auto" href="dziennik.php<?= $isMe ? '' : '?id=' . $pid ?>">Dziennik</a>
  <a class="btn sm ghost" href="ranking.php">← Ranking</a>
  <?php else: ?><a class="btn sm ghost" style="margin-left:auto" href="ranking.php">← Ranking</a><?php endif; ?>
</div>

<div class="stats">
  <div class="stat"><div class="k">Kapitał</div><div class="v"><?= money($equity) ?></div></div>
  <div class="stat"><div class="k">Wynik od startu<?= tip('Kapitał startowy to gotówka + pakiet akcji na start — wynik liczymy od ich łącznej wartości.', '') ?></div><div class="v <?= $ret >= 0 ? 'up' : 'down' ?>"><?= ($ret >= 0 ? '+' : '') . number_format($ret, 1, ',', ' ') ?>%</div></div>
  <div class="stat"><div class="k">Transakcje</div><div class="v"><?= $txCount ?></div></div>
  <div class="stat"><div class="k">Pozycje</div><div class="v"><?= $posCount ?></div></div>
</div>

<div class="panel" style="margin-bottom:16px;padding:14px 16px 10px">
  <h2 style="margin:0">Kapitał w czasie</h2>
  <?= $eqSvg ?: "<p class='muted' style='margin:10px 0'>Za mało danych — wykres buduje się z każdym tickiem.</p>" ?>
</div>

<?php if ((int) $chStats['n'] > 0): ?>
<div class="panel" style="margin-bottom:16px">
  <h2>Wyzwania</h2>
  <div class="ch-grid" style="margin:10px 0 12px">
    <div class="ch-stat"><small>UDZIAŁY</small><b><?= (int) $chStats['n'] ?></b></div>
    <div class="ch-stat"><small>WYGRANE</small><b><?= (int) $chStats['wins'] ?> 🥇</b></div>
    <div class="ch-stat"><small>PODIUM</small><b><?= (int) $chStats['podium'] ?></b></div>
    <div class="ch-stat"><small>ŁĄCZNE NAGRODY</small><b class="<?= (float) $chStats['prizes'] > 0 ? 'up' : '' ?>"><?= money($chStats['prizes']) ?> PLN</b></div>
    <div class="ch-stat"><small>NAJLEPSZY WYNIK</small><b class="<?= (float) ($chStats['best'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $chStats['best'] !== null ? (($chStats['best'] >= 0 ? '+' : '') . number_format((float) $chStats['best'] * 100, 2, ',', ' ') . '%') : '—' ?></b></div>
  </div>
  <table>
    <thead><tr><th>Wyzwanie</th><th class="num">Miejsce</th><th class="num">Wynik</th><th class="num">Nagroda</th></tr></thead>
    <tbody>
    <?php foreach ($chLast as $cl): $clr = (float) $cl['buyin'] > 0 ? ((float) $cl['final_equity'] / (float) $cl['buyin'] - 1) * 100 : 0; $rk = (int) $cl['final_rank']; ?>
      <tr>
        <td><?= h($cl['name']) ?></td>
        <td class="num"><?= $rk === 1 ? '🥇' : ($rk === 2 ? '🥈' : ($rk === 3 ? '🥉' : $rk)) ?> / <?= (int) $cl['total'] ?></td>
        <td class="num"><span class="chg <?= $clr >= 0 ? 'p' : 'n' ?>"><?= number_format($clr, 2, ',', ' ') ?>%</span></td>
        <td class="num mono"><?= (float) $cl['prize'] > 0 ? '<b class="up">+' . money($cl['prize']) . '</b>' : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel" style="margin-bottom:16px">
  <h2>Odznaki (<?= count($earned) ?>/<?= count($catalog) ?>)</h2>
  <div class="badges">
    <?php foreach ($catalog as $code => [$icon, $name, $desc]): $has = isset($earned[$code]); ?>
      <div class="badge<?= $has ? '' : ' locked' ?>" title="<?= $has ? 'zdobyta ' . h(substr($earned[$code], 0, 10)) : 'jeszcze niezdobyta' ?>">
        <span class="bi"><?= $icon ?></span>
        <span><span class="bn"><?= h($name) ?></span><br><span class="bd"><?= h($desc) ?></span></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($closed): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:14px 16px 0"><h2>Zamknięte pozycje <span style="float:right;text-transform:none;letter-spacing:0" class="mono <?= $realized >= 0 ? 'up' : 'down' ?>">Razem: <?= ($realized >= 0 ? '+' : '') . money($realized) ?> PLN</span></h2></div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>Instrument</th><th class="num">Zrealizowany wynik</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($closed, 0, 8, true) as $sid2 => $c): ?>
        <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $sid2 ?>'">
          <td class="tk"><?= h($c['ticker']) ?></td>
          <td class="num <?= $c['pl'] >= 0 ? 'up' : 'down' ?>"><b><?= ($c['pl'] >= 0 ? '+' : '') . money($c['pl']) ?> PLN</b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
<?php layout_footer();
