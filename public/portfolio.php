<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$pos = Engine::all("SELECT w.stock_id, s.ticker, s.name, s.price, w.qty, w.qty_reserved, w.avg_price, w.sl_price, w.tp_price
                    FROM wallets w JOIN stocks s ON s.id=w.stock_id
                    WHERE w.user_id=? AND (w.qty>0 OR w.qty_reserved>0) ORDER BY s.ticker", [$user['id']]);
$orders = Engine::all("SELECT o.*, s.ticker FROM orders o JOIN stocks s ON s.id=o.stock_id
                       WHERE o.user_id=? AND o.status='active' ORDER BY o.id DESC", [$user['id']]);

$value = 0; $cost = 0;
foreach ($pos as $p) { $q = $p['qty'] + $p['qty_reserved']; $value += $q * $p['price']; $cost += $q * $p['avg_price']; }
$pl = $value - $cost;
$equity = $user['cash'] + $user['cash_reserved'] + $value;
$plPct = $cost > 0 ? $pl / $cost * 100 : 0;

layout_header('Portfel', $user, 'portfolio');
?>
<div class="page-head"><h1>Portfel</h1></div>

<div class="stats">
  <div class="stat"><div class="k">Kapitał</div><div class="v"><?= money($equity) ?></div></div>
  <div class="stat"><div class="k">Gotówka</div><div class="v"><?= money($user['cash']) ?></div></div>
  <div class="stat"><div class="k">Wartość akcji</div><div class="v"><?= money($value) ?></div></div>
  <div class="stat"><div class="k">Wynik</div><div class="v <?= $pl >= 0 ? 'up' : 'down' ?>"><?= ($pl >= 0 ? '+' : '') . money($pl) ?><span style="font-size:13px"> (<?= ($plPct >= 0 ? '+' : '') . number_format($plPct, 1, ',', ' ') ?>%)</span></div></div>
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
      <thead><tr><th>Instrument</th><th>Typ</th><th class="num">Ilość</th><th class="num">Cena</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): ?>
        <tr>
          <td class="tk"><?= h($o['ticker']) ?></td>
          <td><span class="chg <?= $o['side'] === 'buy' ? 'p' : 'n' ?>"><?= $o['side'] === 'buy' ? 'KUPNO' : 'SPRZEDAŻ' ?></span></td>
          <td class="num"><?= (int) $o['qty'] ?></td>
          <td class="num"><?= money($o['price']) ?></td>
          <td style="text-align:right"><form method="post" action="cancel_order.php"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn sm ghost">Anuluj</button></form></td>
        </tr>
      <?php endforeach; if (!$orders) echo "<tr><td class='muted' colspan=5 style='padding:20px'>Brak aktywnych zleceń.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_footer();
