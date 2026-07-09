<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$stocks = Engine::all("SELECT s.id, s.ticker, s.name, sec.name AS sector, s.price, s.day_open_price FROM stocks s JOIN sectors sec ON sec.id=s.sector_id ORDER BY s.ticker");
[$sessionNo] = Engine::sessionInfo();
$bid = []; foreach (Engine::all("SELECT stock_id, MAX(price) p FROM orders WHERE side='buy'  AND status='active' GROUP BY stock_id") as $r) $bid[$r['stock_id']] = $r['p'];
$ask = []; foreach (Engine::all("SELECT stock_id, MIN(price) p FROM orders WHERE side='sell' AND status='active' GROUP BY stock_id") as $r) $ask[$r['stock_id']] = $r['p'];

layout_header('Rynek', $user, 'market');
?>
<div class="page-head"><h1>Rynek</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span><span class="muted">zmiana liczona od otwarcia sesji · kliknij, aby handlować</span></div>
<div class="panel" style="padding:0;overflow:hidden">
  <div class="tbl-scroll">
    <table class="mw">
      <thead><tr><th>Instrument</th><th class="num">Kurs</th><th class="num">Zmiana</th><th class="num">Bid</th><th class="num">Ask</th></tr></thead>
      <tbody>
      <?php foreach ($stocks as $s): $id = (int) $s['id'];
          $ref = (float) $s['day_open_price'] > 0 ? (float) $s['day_open_price'] : (float) $s['price'];
          $chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0; ?>
        <tr onclick="location='stock.php?id=<?= $id ?>'">
          <td><div class="sym"><span class="tk"><?= h($s['ticker']) ?></span><span class="nm"><?= h($s['name']) ?></span><span class="tag"><?= h($s['sector']) ?></span></div></td>
          <td class="num px" data-px="<?= $id ?>"><?= money($s['price']) ?></td>
          <td class="num"><span class="chg <?= $chg >= 0 ? 'p' : 'n' ?>" data-chg="<?= $id ?>"><span class="ar"><?= $chg >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($chg), 2, ',', ' ') ?>%</span></td>
          <td class="num bid"><?= isset($bid[$id]) ? money($bid[$id]) : '—' ?></td>
          <td class="num ask"><?= isset($ask[$id]) ? money($ask[$id]) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<script>
setInterval(async () => {
  try {
    const j = await (await fetch('api_market.php')).json(); if (!j.ok) return;
    for (const [id, d] of Object.entries(j.data)) {
      const px = document.querySelector(`[data-px="${id}"]`), cg = document.querySelector(`[data-chg="${id}"]`);
      if (px) px.textContent = Number(d.price).toLocaleString('pl-PL', {minimumFractionDigits:2, maximumFractionDigits:2});
      if (cg) { const up = d.chg >= 0; cg.className = 'chg ' + (up ? 'p' : 'n');
        cg.innerHTML = '<span class="ar">' + (up ? '▲' : '▼') + '</span>' + Math.abs(d.chg).toFixed(2).replace('.', ',') + '%'; }
    }
  } catch (e) {}
}, 5000);
</script>
<?php layout_footer();
