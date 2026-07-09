<?php
require __DIR__ . '/_boot.php';
$user = require_login();

function change_pct(int $stockId, float $price): float {
    $ref = Engine::one("SELECT c FROM candles WHERE stock_id=? AND t>=1 ORDER BY t ASC LIMIT 1", [$stockId]);
    $ref = ($ref !== false && $ref !== null) ? (float) $ref : $price;
    return $ref > 0 ? ($price - $ref) / $ref * 100 : 0;
}
$stocks = Engine::all("SELECT id, ticker, name, sector, price FROM stocks ORDER BY ticker");

layout_header('Rynek', $user);
?>
<h1>Rynek giełdowy</h1>
<div class="panel">
  <table>
    <thead><tr><th>Ticker</th><th>Spółka</th><th>Sektor</th><th class="num">Kurs</th><th class="num">Zmiana</th></tr></thead>
    <tbody>
    <?php foreach ($stocks as $s):
        $chg = change_pct((int) $s['id'], (float) $s['price']); $cls = $chg >= 0 ? 'pos' : 'neg'; ?>
      <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $s['id'] ?>'">
        <td class="tick"><?= h($s['ticker']) ?></td>
        <td><?= h($s['name']) ?></td>
        <td class="muted"><?= h($s['sector']) ?></td>
        <td class="num" data-px="<?= (int) $s['id'] ?>"><?= money($s['price']) ?> PLN</td>
        <td class="num <?= $cls ?>" data-chg="<?= (int) $s['id'] ?>"><?= ($chg >= 0 ? '+' : '') . number_format($chg, 2, ',', ' ') ?>%</td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<p class="muted" style="margin-top:12px">Kursy odświeżają się automatycznie co 5&nbsp;s. Kliknij spółkę, aby handlować.</p>
<script>
setInterval(async () => {
  try {
    const r = await fetch('api_market.php'); const j = await r.json(); if (!j.ok) return;
    for (const [id, d] of Object.entries(j.data)) {
      const px = document.querySelector(`[data-px="${id}"]`);
      const cg = document.querySelector(`[data-chg="${id}"]`);
      if (px) px.textContent = Number(d.price).toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' PLN';
      if (cg) { cg.textContent = (d.chg>=0?'+':'') + d.chg.toFixed(2).replace('.',',') + '%';
        cg.className = 'num ' + (d.chg>=0?'pos':'neg'); }
    }
  } catch(e){}
}, 5000);
</script>
<?php layout_footer();
