<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$s = Engine::row("SELECT * FROM stocks WHERE id=?", [$id]);
if (!$s) { flash('Nie ma takiej spółki.', 'err'); redirect('market.php'); }

$ref = Engine::one("SELECT c FROM candles WHERE stock_id=? AND t>=1 ORDER BY t ASC LIMIT 1", [$id]);
$ref = ($ref !== false && $ref !== null) ? (float) $ref : (float) $s['price'];
$chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0;

$candles = array_reverse(Engine::all("SELECT o,h,l,c FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT 80", [$id]));

$bids = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='buy'  AND status='active' GROUP BY price ORDER BY price DESC LIMIT 8", [$id]);
$asks = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='sell' AND status='active' GROUP BY price ORDER BY price ASC  LIMIT 8", [$id]);
$maxDepth = max(1, ...array_map(fn($r) => (int) $r['q'], array_merge($bids, $asks) ?: [['q' => 1]]));

$owned = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$user['id'], $id]) ?: 0);
$trades = Engine::all("SELECT qty, price, created_at FROM transactions WHERE stock_id=? ORDER BY id DESC LIMIT 14", [$id]);
$mcap = (float) $s['price'] * (float) $s['total_shares'];

// --- świece (inline SVG) ---
$chartSvg = "<div style='padding:40px;text-align:center;color:var(--faint)'>Zbieram dane do wykresu…</div>";
if (count($candles) > 1) {
    $W = 680; $H = 240; $pl = 4; $pr = 4; $pt = 10; $pb = 10;
    $lows = array_map(fn($c) => (float) $c['l'], $candles);
    $highs = array_map(fn($c) => (float) $c['h'], $candles);
    $mn = min($lows); $mx = max($highs); $rng = ($mx - $mn) ?: 1;
    $n = count($candles); $slot = ($W - $pl - $pr) / $n; $bw = max(1.4, $slot * 0.62);
    $yv = fn($v) => round($pt + (1 - ($v - $mn) / $rng) * ($H - $pt - $pb), 1);
    $svg = "<svg class='chart' viewBox='0 0 $W $H' preserveAspectRatio='none'>";
    for ($g = 0; $g <= 3; $g++) { $gy = round($pt + $g / 3 * ($H - $pt - $pb), 1); $svg .= "<line x1='0' x2='$W' y1='$gy' y2='$gy' stroke='var(--line)' stroke-width='1'/>"; }
    foreach ($candles as $i => $c) {
        $x = round($pl + $i * $slot + $slot / 2, 1);
        $o = (float) $c['o']; $cl = (float) $c['c']; $col = $cl >= $o ? 'var(--up)' : 'var(--down)';
        $top = $yv(max($o, $cl)); $bot = $yv(min($o, $cl)); $bh = max(1, round($bot - $top, 1));
        $bx = round($x - $bw / 2, 1);
        $svg .= "<line x1='$x' x2='$x' y1='" . $yv((float) $c['h']) . "' y2='" . $yv((float) $c['l']) . "' stroke='$col' stroke-width='1'/>";
        $svg .= "<rect x='$bx' y='$top' width='" . round($bw, 1) . "' height='$bh' fill='$col'/>";
    }
    $chartSvg = $svg . "</svg>";
}

layout_header($s['ticker'] . ' · ' . $s['name'], $user, 'market');
?>
<div class="shead">
  <div class="idn"><div class="tk"><?= h($s['ticker']) ?></div><div class="nm"><?= h($s['name']) ?> · <?= h($s['sector']) ?></div></div>
  <div class="price">
    <div class="p" data-px><?= money($s['price']) ?> <span style="font-size:15px;color:var(--faint)">PLN</span></div>
    <span class="chg <?= $chg >= 0 ? 'p' : 'n' ?>" data-chg><span class="ar"><?= $chg >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($chg), 2, ',', ' ') ?>%</span>
  </div>
</div>

<div class="stock-grid">
  <div>
    <div class="panel" style="padding:8px"><?= $chartSvg ?></div>

    <div class="panel" style="margin-top:16px">
      <div class="subtabs">
        <button class="on" data-tab="book">Arkusz zleceń</button>
        <button data-tab="trades">Transakcje</button>
        <button data-tab="info">Info</button>
      </div>

      <div class="tabpane on" id="tab-book">
        <div class="book">
          <div class="col b"><h3>Kupno (bid)</h3>
            <?php foreach ($bids as $r): ?>
              <div class="lvl"><span class="depth" style="width:<?= (int) round($r['q'] / $maxDepth * 100) ?>%"></span><span class="p"><?= money($r['price']) ?></span><span class="q"><?= (int) $r['q'] ?></span></div>
            <?php endforeach; if (!$bids) echo "<div class='muted' style='padding:6px'>brak</div>"; ?>
          </div>
          <div class="col s"><h3>Sprzedaż (ask)</h3>
            <?php foreach ($asks as $r): ?>
              <div class="lvl"><span class="depth" style="width:<?= (int) round($r['q'] / $maxDepth * 100) ?>%"></span><span class="p"><?= money($r['price']) ?></span><span class="q"><?= (int) $r['q'] ?></span></div>
            <?php endforeach; if (!$asks) echo "<div class='muted' style='padding:6px'>brak</div>"; ?>
          </div>
        </div>
      </div>

      <div class="tabpane" id="tab-trades">
        <table><thead><tr><th>Czas</th><th class="num">Ilość</th><th class="num">Kurs</th></tr></thead><tbody>
          <?php foreach ($trades as $t): ?>
            <tr><td class="muted mono"><?= h(substr($t['created_at'], 11, 8)) ?></td><td class="num"><?= (int) $t['qty'] ?></td><td class="num"><?= money($t['price']) ?></td></tr>
          <?php endforeach; if (!$trades) echo "<tr><td class='muted' colspan=3>brak transakcji</td></tr>"; ?>
        </tbody></table>
      </div>

      <div class="tabpane" id="tab-info">
        <table><tbody>
          <tr><td class="muted">Sektor</td><td class="num"><?= h($s['sector']) ?></td></tr>
          <tr><td class="muted">Kapitalizacja</td><td class="num"><?= money($mcap) ?> PLN</td></tr>
          <tr><td class="muted">Liczba akcji</td><td class="num"><?= number_format($s['total_shares'], 0, ',', ' ') ?></td></tr>
          <tr><td class="muted">Wartość fundamentalna</td><td class="num"><?= money($s['fundamental']) ?> PLN</td></tr>
        </tbody></table>
      </div>
    </div>
  </div>

  <aside class="panel orderpanel">
    <h2>Zlecenie</h2>
    <form method="post" action="place_order.php">
      <input type="hidden" name="stock_id" value="<?= $id ?>">
      <input type="hidden" name="side" id="side" value="buy">
      <div class="seg">
        <button type="button" class="buy on" id="tb-buy">KUP</button>
        <button type="button" class="sell" id="tb-sell">SPRZEDAJ</button>
      </div>
      <label>Ilość <span class="muted">(masz: <?= $owned ?> szt.)</span></label>
      <input type="number" name="qty" id="qty" min="1" value="10" required>
      <label>Cena limit (PLN)</label>
      <input type="number" step="0.01" name="price" id="price" value="<?= number_format($s['price'], 2, '.', '') ?>" required>
      <div class="summary"><span>Wartość zlecenia</span><b id="val">—</b></div>
      <div class="adv">
        <div><label>Stop-Loss</label><input type="number" step="0.01" name="sl_price" placeholder="—"></div>
        <div><label>Take-Profit</label><input type="number" step="0.01" name="tp_price" placeholder="—"></div>
      </div>
      <button class="btn buy" id="submit" style="margin-top:14px">Kup <?= h($s['ticker']) ?></button>
    </form>
    <p class="muted" style="margin-top:10px;font-size:12px">SL/TP są egzekwowane przez silnik: co tick sprawdza kurs i zamyka pozycję.</p>
  </aside>
</div>

<script>
// zakładki
document.querySelectorAll('.subtabs button').forEach(b => b.onclick = () => {
  document.querySelectorAll('.subtabs button').forEach(x => x.classList.remove('on'));
  document.querySelectorAll('.tabpane').forEach(x => x.classList.remove('on'));
  b.classList.add('on'); document.getElementById('tab-' + b.dataset.tab).classList.add('on');
});
// panel zleceń
const side=document.getElementById('side'), qty=document.getElementById('qty'), price=document.getElementById('price');
const val=document.getElementById('val'), sub=document.getElementById('submit');
const tk=<?= json_encode($s['ticker']) ?>;
function upd(){ const v=(parseFloat(qty.value)||0)*(parseFloat(price.value)||0);
  val.textContent=v.toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2})+' PLN'; }
function setSide(s){ side.value=s;
  document.getElementById('tb-buy').classList.toggle('on',s==='buy');
  document.getElementById('tb-sell').classList.toggle('on',s==='sell');
  sub.className='btn '+s; sub.textContent=(s==='buy'?'Kup ':'Sprzedaj ')+tk; }
document.getElementById('tb-buy').onclick=()=>setSide('buy');
document.getElementById('tb-sell').onclick=()=>setSide('sell');
qty.oninput=upd; price.oninput=upd; upd();
// live kurs w nagłówku
setInterval(async()=>{ try{
  const j=await (await fetch('api_market.php')).json(); if(!j.ok) return; const d=j.data[<?= $id ?>]; if(!d) return;
  const p=document.querySelector('[data-px]'); const c=document.querySelector('[data-chg]'); const up=d.chg>=0;
  if(p) p.innerHTML=Number(d.price).toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2})+' <span style="font-size:15px;color:var(--faint)">PLN</span>';
  if(c){ c.className='chg '+(up?'p':'n'); c.innerHTML='<span class="ar">'+(up?'▲':'▼')+'</span>'+Math.abs(d.chg).toFixed(2).replace('.',',')+'%'; }
}catch(e){} },5000);
</script>
<?php layout_footer();
