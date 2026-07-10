<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$stocks = Engine::all("SELECT s.id, s.ticker, s.name, sec.name AS sector, s.price, s.day_open_price FROM stocks s JOIN sectors sec ON sec.id=s.sector_id ORDER BY s.ticker");
[$sessionNo, , $tps] = Engine::sessionInfo();

// --- indeks giełdowy ---
$idxSeries = array_reverse(array_map('floatval', Engine::col("SELECT value FROM index_history ORDER BY t DESC LIMIT 120")));
$idxNow = $idxSeries ? end($idxSeries) : Engine::indexValue();
$idxOpen = (float) (Engine::one("SELECT value FROM index_history WHERE t >= ? ORDER BY t ASC LIMIT 1", [($sessionNo - 1) * $tps]) ?: $idxNow);
$idxChg = $idxOpen > 0 ? ($idxNow - $idxOpen) / $idxOpen * 100 : 0;
$idxSvg = '';
if (count($idxSeries) > 1) {
    $W = 940; $H = 120; $pad = 4;
    $mn = min($idxSeries); $mx = max($idxSeries); $rng = ($mx - $mn) ?: 1;
    $n = count($idxSeries); $pts = [];
    foreach ($idxSeries as $i => $v) {
        $pts[] = round($pad + $i / ($n - 1) * ($W - 2 * $pad), 1) . ',' . round($pad + (1 - ($v - $mn) / $rng) * ($H - 2 * $pad), 1);
    }
    $line = implode(' ', $pts);
    $col = end($idxSeries) >= reset($idxSeries) ? 'var(--up)' : 'var(--down)';
    $idxSvg = "<svg class='idx-chart' viewBox='0 0 $W $H' preserveAspectRatio='none'>"
        . "<polygon points='$pad,$H $line " . ($W - $pad) . ",$H' fill='$col' opacity='0.10'/>"
        . "<polyline points='$line' fill='none' stroke='$col' stroke-width='1.6' stroke-linejoin='round'/></svg>";
}
// sektory: dzienna zmiana ważona kapitalizacją
$sectorRows = Engine::all("SELECT sec.name, SUM(s.price*s.total_shares) cur, SUM(s.day_open_price*s.total_shares) op
                           FROM stocks s JOIN sectors sec ON sec.id=s.sector_id GROUP BY sec.id, sec.name ORDER BY sec.name");
$bid = []; foreach (Engine::all("SELECT stock_id, MAX(price) p FROM orders WHERE side='buy'  AND status='active' GROUP BY stock_id") as $r) $bid[$r['stock_id']] = $r['p'];
$ask = []; foreach (Engine::all("SELECT stock_id, MIN(price) p FROM orders WHERE side='sell' AND status='active' GROUP BY stock_id") as $r) $ask[$r['stock_id']] = $r['p'];

layout_header('Rynek', $user, 'market');
?>
<div class="page-head"><h1>Rynek</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span><span class="muted">zmiana liczona od otwarcia sesji · kliknij, aby handlować</span></div>

<div class="panel" style="margin-bottom:16px;padding:14px 16px 10px">
  <div style="display:flex;align-items:baseline;gap:14px;flex-wrap:wrap">
    <h2 style="margin:0">Indeks GPW-gra</h2>
    <span class="mono" style="font-size:26px;font-weight:800;letter-spacing:-.02em" data-idx-val><?= number_format($idxNow, 2, ',', ' ') ?> <span style="font-size:13px;color:var(--faint)">pkt</span></span>
    <span class="chg <?= $idxChg >= 0 ? 'p' : 'n' ?>" data-idx-chg><span class="ar"><?= $idxChg >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($idxChg), 2, ',', ' ') ?>%</span>
    <span class="muted" style="font-size:12px">ważony kapitalizacją · baza 1000 pkt</span>
  </div>
  <div id="idxbox"><?= $idxSvg ?: "<p class='muted' style='margin:10px 0'>Historia indeksu buduje się z każdym tickiem rynku…</p>" ?></div>
  <div class="chips" style="display:flex;gap:8px;flex-wrap:wrap;margin:8px 0 4px">
    <?php foreach ($sectorRows as $sr): $sc = (float) $sr['op'] > 0 ? ((float) $sr['cur'] - (float) $sr['op']) / (float) $sr['op'] * 100 : 0; ?>
      <span class="tag" style="padding:4px 9px"><?= h($sr['name']) ?> <b class="<?= $sc >= 0 ? 'up' : 'down' ?> mono"><?= ($sc >= 0 ? '+' : '') . number_format($sc, 1, ',', ' ') ?>%</b></span>
    <?php endforeach; ?>
  </div>
</div>
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
    if (j.index) {
      const iv = document.querySelector('[data-idx-val]'), ic = document.querySelector('[data-idx-chg]');
      if (iv) iv.innerHTML = Number(j.index.value).toLocaleString('pl-PL', {minimumFractionDigits:2, maximumFractionDigits:2})
        + ' <span style="font-size:13px;color:var(--faint)">pkt</span>';
      if (ic) { const up = j.index.chg >= 0; ic.className = 'chg ' + (up ? 'p' : 'n');
        ic.innerHTML = '<span class="ar">' + (up ? '▲' : '▼') + '</span>' + Math.abs(j.index.chg).toFixed(2).replace('.', ',') + '%'; }
      const sr = j.index.series || [];
      if (sr.length > 1) {   // przerysuj wykres indeksu
        const W = 940, H = 120, p = 4, mn = Math.min(...sr), mx = Math.max(...sr), rng = (mx - mn) || 1, n = sr.length;
        const pts = sr.map((v, i) => (p + i / (n - 1) * (W - 2 * p)).toFixed(1) + ',' + (p + (1 - (v - mn) / rng) * (H - 2 * p)).toFixed(1)).join(' ');
        const col = sr[n - 1] >= sr[0] ? 'var(--up)' : 'var(--down)';
        document.getElementById('idxbox').innerHTML =
          '<svg class="idx-chart" viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none">'
          + '<polygon points="' + p + ',' + H + ' ' + pts + ' ' + (W - p) + ',' + H + '" fill="' + col + '" opacity="0.10"/>'
          + '<polyline points="' + pts + '" fill="none" stroke="' + col + '" stroke-width="1.6" stroke-linejoin="round"/></svg>';
      }
    }
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
