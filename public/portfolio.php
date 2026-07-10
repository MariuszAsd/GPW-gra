<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$pos = Engine::all("SELECT w.stock_id, s.ticker, s.name, s.price, w.qty, w.qty_reserved, w.avg_price, w.sl_price, w.tp_price
                    FROM wallets w JOIN stocks s ON s.id=w.stock_id
                    WHERE w.user_id=? AND (w.qty>0 OR w.qty_reserved>0) ORDER BY s.ticker", [$user['id']]);
$orders = Engine::all("SELECT o.*, s.ticker FROM orders o JOIN stocks s ON s.id=o.stock_id
                       WHERE o.user_id=? AND o.status='active' ORDER BY o.id DESC", [$user['id']]);
$history = Engine::all("SELECT t.*, s.ticker FROM transactions t JOIN stocks s ON s.id=t.stock_id
                        WHERE t.buyer_id=? OR t.seller_id=? ORDER BY t.id DESC LIMIT 30", [$user['id'], $user['id']]);
$archive = Engine::all("SELECT o.*, s.ticker FROM orders o JOIN stocks s ON s.id=o.stock_id
                        WHERE o.user_id=? AND o.status<>'active' ORDER BY o.id DESC LIMIT 30", [$user['id']]);

// --- wykres kapitału (equity_history pisane co tick przez silnik) ---
$eqSeries = array_reverse(array_map('floatval', Engine::col("SELECT equity FROM equity_history WHERE user_id=? ORDER BY t DESC LIMIT 150", [$user['id']])));
$eqSvg = '';
if (count($eqSeries) > 1) {
    $W = 940; $H = 110; $pad = 4;
    $mn = min($eqSeries); $mx = max($eqSeries); $rng = ($mx - $mn) ?: 1;
    $n = count($eqSeries); $pts = [];
    foreach ($eqSeries as $i => $v) {
        $pts[] = round($pad + $i / ($n - 1) * ($W - 2 * $pad), 1) . ',' . round($pad + (1 - ($v - $mn) / $rng) * ($H - 2 * $pad), 1);
    }
    $line = implode(' ', $pts);
    $col = end($eqSeries) >= reset($eqSeries) ? 'var(--up)' : 'var(--down)';
    $eqSvg = "<svg class='idx-chart' style='height:110px' viewBox='0 0 $W $H' preserveAspectRatio='none'>"
        . "<polygon points='$pad,$H $line " . ($W - $pad) . ",$H' fill='$col' opacity='0.10'/>"
        . "<polyline points='$line' fill='none' stroke='$col' stroke-width='1.6' stroke-linejoin='round'/></svg>";
}

$value = 0; $cost = 0;
foreach ($pos as $p) { $q = $p['qty'] + $p['qty_reserved']; $value += $q * $p['price']; $cost += $q * $p['avg_price']; }
$pl = $value - $cost;
$equity = $user['cash'] + $user['cash_reserved'] + $value;
$plPct = $cost > 0 ? $pl / $cost * 100 : 0;

// --- cel gry ---
$goalTarget = (float) (Engine::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
$goalSessions = (int) (Engine::one("SELECT v FROM game_state WHERE k='goal_sessions'") ?: 0);
[$sessionNo] = Engine::sessionInfo();
$me = Engine::row("SELECT joined_session, goal_session FROM users WHERE id=?", [$user['id']]);
$deadline = (int) ($me['joined_session'] ?? 1) + $goalSessions - 1;
$sessionsLeft = $deadline - $sessionNo;
$progress = $goalTarget > 0 ? min(100, $equity / $goalTarget * 100) : 0;

layout_header('Portfel', $user, 'portfolio');
?>
<div class="page-head"><h1>Portfel</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span></div>

<?php if ($goalTarget > 0): ?>
<div class="panel goal <?= $me['goal_session'] !== null ? 'won' : ($sessionsLeft < 0 ? 'lost' : '') ?>">
  <div class="goal-row">
    <div>
      <h2>Cel gry</h2>
      <div class="goal-title">Zbuduj kapitał <b><?= money($goalTarget) ?> PLN</b> w <?= $goalSessions ?> sesji</div>
    </div>
    <div class="goal-status">
      <?php if ($me['goal_session'] !== null): ?>
        <span class="chg p">🏆 Osiągnięty w sesji #<?= (int) $me['goal_session'] ?></span>
      <?php elseif ($sessionsLeft >= 0): ?>
        <span class="mono soft">pozostało sesji: <b class="up"><?= $sessionsLeft ?></b></span>
      <?php else: ?>
        <span class="chg n">czas minął — grasz dalej w trybie wolnym</span>
      <?php endif; ?>
    </div>
  </div>
  <div class="goalbar"><i style="width:<?= round($progress, 1) ?>%"></i></div>
  <div class="goal-nums mono"><span><?= money($equity) ?> PLN</span><span><?= number_format($progress, 1, ',', ' ') ?>%</span><span><?= money($goalTarget) ?> PLN</span></div>
</div>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="k">Kapitał</div><div class="v"><?= money($equity) ?></div></div>
  <div class="stat"><div class="k">Gotówka</div><div class="v"><?= money($user['cash']) ?></div></div>
  <div class="stat"><div class="k">Wartość akcji</div><div class="v"><?= money($value) ?></div></div>
  <div class="stat"><div class="k">Wynik</div><div class="v <?= $pl >= 0 ? 'up' : 'down' ?>"><?= ($pl >= 0 ? '+' : '') . money($pl) ?><span style="font-size:13px"> (<?= ($plPct >= 0 ? '+' : '') . number_format($plPct, 1, ',', ' ') ?>%)</span></div></div>
</div>

<div class="panel" style="margin-bottom:16px;padding:14px 16px 10px">
  <h2 style="margin:0">Wartość portfela w czasie</h2>
  <?= $eqSvg ?: "<p class='muted' style='margin:10px 0'>Wykres buduje się z każdym tickiem rynku…</p>" ?>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:14px 16px 0"><h2>Pozycje</h2></div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>Instrument</th><th class="num">Ilość</th><th class="num">Śr. cena</th><th class="num">Kurs</th><th class="num">Wynik</th><th>SL / TP</th></tr></thead>
      <tbody>
      <?php foreach ($pos as $p): $q = $p['qty'] + $p['qty_reserved']; $ppl = $q * ($p['price'] - $p['avg_price']); ?>
        <tr>
          <td><div class="sym"><a class="tk" href="stock.php?id=<?= (int) $p['stock_id'] ?>"><?= h($p['ticker']) ?></a><span class="nm"><?= h($p['name']) ?></span></div>
            <?php if ($p['qty_reserved'] > 0): ?><span class="muted" style="font-size:11px"><?= (int) $p['qty_reserved'] ?> w zleceniach</span><?php endif; ?></td>
          <td class="num"><?= (int) $q ?></td>
          <td class="num"><?= money($p['avg_price']) ?></td>
          <td class="num"><?= money($p['price']) ?></td>
          <td class="num <?= $ppl >= 0 ? 'up' : 'down' ?>"><?= ($ppl >= 0 ? '+' : '') . money($ppl) ?></td>
          <td>
            <form method="post" action="set_sltp.php" style="display:flex;gap:6px;align-items:center">
              <input type="hidden" name="stock_id" value="<?= (int) $p['stock_id'] ?>">
              <input type="number" step="0.01" name="sl_price" placeholder="SL" value="<?= $p['sl_price'] !== null ? number_format($p['sl_price'],2,'.','') : '' ?>" style="width:78px;padding:6px 8px">
              <input type="number" step="0.01" name="tp_price" placeholder="TP" value="<?= $p['tp_price'] !== null ? number_format($p['tp_price'],2,'.','') : '' ?>" style="width:78px;padding:6px 8px">
              <button class="btn sm ghost">OK</button>
            </form>
          </td>
        </tr>
      <?php endforeach; if (!$pos) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Brak pozycji — kup coś na Rynku.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" style="padding:0;overflow:hidden;margin-top:16px">
  <div style="padding:14px 16px 0"><h2>Aktywne zlecenia</h2></div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>Instrument</th><th>Typ</th><th class="num">Ilość</th><th class="num">Cena</th><th>Ważność</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td class="tk"><?= h($o['ticker']) ?></td>
          <td><span class="chg <?= $o['side'] === 'buy' ? 'p' : 'n' ?>"><?= $o['side'] === 'buy' ? 'KUPNO' : 'SPRZEDAŻ' ?></span></td>
          <td class="num"><?= (int) $o['qty'] ?></td>
          <td class="num"><?= money($o['price']) ?></td>
          <td class="muted"><?= $o['expires_session'] !== null ? 'sesja #' . (int) $o['expires_session'] : 'bezterm.' ?></td>
          <td style="text-align:right"><form method="post" action="cancel_order.php"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn sm ghost">Anuluj</button></form></td>
        </tr>
      <?php endforeach; if (!$orders) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Brak aktywnych zleceń.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" style="padding:0;overflow:hidden;margin-top:16px">
  <div style="padding:14px 16px 0"><h2>Historia transakcji</h2></div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>Czas</th><th>Instrument</th><th>Strona</th><th class="num">Ilość</th><th class="num">Kurs</th><th class="num">Wartość</th></tr></thead>
      <tbody>
      <?php foreach ($history as $t): $isBuy = (int) $t['buyer_id'] === (int) $user['id']; $v = $t['qty'] * $t['price']; ?>
        <tr>
          <td class="muted mono"><?= h(substr($t['created_at'], 5, 11)) ?></td>
          <td class="tk"><?= h($t['ticker']) ?></td>
          <td><span class="chg <?= $isBuy ? 'p' : 'n' ?>"><?= $isBuy ? 'KUPNO' : 'SPRZEDAŻ' ?></span></td>
          <td class="num"><?= (int) $t['qty'] ?></td>
          <td class="num"><?= money($t['price']) ?></td>
          <td class="num"><?= money($v) ?></td>
        </tr>
      <?php endforeach; if (!$history) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Jeszcze nic nie kupiłeś ani nie sprzedałeś.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel" style="padding:0;overflow:hidden;margin-top:16px">
  <div style="padding:14px 16px 0"><h2>Archiwum zleceń</h2></div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>Czas</th><th>Instrument</th><th>Strona</th><th class="num">Zrealizowano</th><th class="num">Cena limit</th><th>Status</th></tr></thead>
      <tbody>
      <?php
      $stLabel = ['filled' => ['zrealizowane', 'up'], 'cancelled' => ['anulowane', 'muted'], 'expired' => ['wygasłe', 'muted']];
      foreach ($archive as $o):
          [$lbl, $cls] = $stLabel[$o['status']] ?? [$o['status'], 'muted'];
          $init = (int) $o['qty_init']; $done = $init > 0 ? $init - (int) $o['qty'] : null;
          if ($done !== null && $done > 0 && $o['status'] !== 'filled') { $lbl .= ' (część zrealizowana)'; $cls = 'soft'; }
      ?>
        <tr>
          <td class="muted mono"><?= h(substr($o['created_at'], 5, 11)) ?></td>
          <td class="tk"><?= h($o['ticker']) ?></td>
          <td><span class="chg <?= $o['side'] === 'buy' ? 'p' : 'n' ?>"><?= $o['side'] === 'buy' ? 'KUPNO' : 'SPRZEDAŻ' ?></span></td>
          <td class="num"><?= $done !== null ? "$done / $init szt." : ($o['status'] === 'filled' ? 'w całości' : '—') ?></td>
          <td class="num"><?= money($o['price']) ?></td>
          <td class="<?= $cls ?>"><?= h($lbl) ?></td>
        </tr>
      <?php endforeach; if (!$archive) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Brak zakończonych zleceń.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_footer();
