<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$s = Engine::row("SELECT * FROM stocks WHERE id=?", [$id]);
if (!$s) { flash('Nie ma takiej spółki.', 'err'); redirect('market.php'); }

$ref = Engine::one("SELECT c FROM candles WHERE stock_id=? AND t>=1 ORDER BY t ASC LIMIT 1", [$id]);
$ref = ($ref !== false && $ref !== null) ? (float) $ref : (float) $s['price'];
$chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0;

$closes = Engine::col("SELECT c FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT 60", [$id]);
$closes = array_reverse(array_map('floatval', $closes));

$bids = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='buy'  AND status='active' GROUP BY price ORDER BY price DESC LIMIT 8", [$id]);
$asks = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='sell' AND status='active' GROUP BY price ORDER BY price ASC  LIMIT 8", [$id]);
$maxDepth = max(1, ...array_map(fn($r) => (int) $r['q'], array_merge($bids, $asks) ?: [['q' => 1]]));

$mine = Engine::row("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$user['id'], $id]);
$owned = (int) ($mine['qty'] ?? 0);

$trades = Engine::all("SELECT qty, price, created_at FROM transactions WHERE stock_id=? ORDER BY id DESC LIMIT 12", [$id]);

// inline SVG line+area chart (bez zewnętrznej biblioteki — brak problemu z ładowaniem)
$chartSvg = '';
if (count($closes) > 1) {
    $W = 560; $H = 180; $pad = 6; $mn = min($closes); $mx = max($closes); $rng = ($mx - $mn) ?: 1;
    $n = count($closes); $pts = [];
    foreach ($closes as $i => $c) {
        $x = $pad + $i / ($n - 1) * ($W - 2 * $pad);
        $y = $pad + (1 - ($c - $mn) / $rng) * ($H - 2 * $pad);
        $pts[] = round($x, 1) . ',' . round($y, 1);
    }
    $line = implode(' ', $pts);
    $area = "$pad,$H " . $line . " " . ($W - $pad) . ",$H";
    $col = end($closes) >= reset($closes) ? 'var(--up)' : 'var(--down)';
    $chartSvg = "<svg class='chart' viewBox='0 0 $W $H' preserveAspectRatio='none'>"
        . "<polygon points='$area' fill='$col' opacity='0.12'/>"
        . "<polyline points='$line' fill='none' stroke='$col' stroke-width='2' stroke-linejoin='round'/></svg>";
}

layout_header($s['ticker'] . ' · ' . $s['name'], $user);
?>
<div class="shead">
  <span class="tick" style="font-size:20px"><?= h($s['ticker']) ?></span>
  <span class="muted"><?= h($s['name']) ?> · <?= h($s['sector']) ?></span>
  <span class="px" data-px><?= money($s['price']) ?> PLN</span>
  <span class="chg <?= $chg >= 0 ? 'pos' : 'neg' ?>" data-chg><?= ($chg >= 0 ? '+' : '') . number_format($chg, 2, ',', ' ') ?>%</span>
</div>

<div class="grid2">
  <div>
    <div class="panel" style="padding:10px 12px 4px"><?= $chartSvg ?: "<p class='muted'>Zbieram dane do wykresu…</p>" ?></div>
    <div class="panel" style="margin-top:18px">
      <h2>Arkusz zleceń</h2>
      <div class="book">
        <div class="col b"><h2>Kupno (bid)</h2><table><tbody>
          <?php foreach ($bids as $r): ?>
            <tr><td class="depth" style="text-align:right"><?= (int) $r['q'] ?><i style="width:<?= (int) round($r['q'] / $maxDepth * 100) ?>%"></i></td><td class="pos"><?= money($r['price']) ?></td></tr>
          <?php endforeach; if (!$bids) echo "<tr><td class='muted'>brak</td></tr>"; ?>
        </tbody></table></div>
        <div class="col s"><h2>Sprzedaż (ask)</h2><table><tbody>
          <?php foreach ($asks as $r): ?>
            <tr><td class="neg"><?= money($r['price']) ?></td><td class="depth"><?= (int) $r['q'] ?><i style="left:0;width:<?= (int) round($r['q'] / $maxDepth * 100) ?>%"></i></td></tr>
          <?php endforeach; if (!$asks) echo "<tr><td class='muted'>brak</td></tr>"; ?>
        </tbody></table></div>
      </div>
    </div>
    <div class="panel" style="margin-top:18px">
      <h2>Ostatnie transakcje</h2>
      <table><thead><tr><th>Czas</th><th class="num">Ilość</th><th class="num">Kurs</th></tr></thead><tbody>
        <?php foreach ($trades as $t): ?>
          <tr><td class="muted"><?= h(substr($t['created_at'], 11, 8)) ?></td><td class="num"><?= (int) $t['qty'] ?></td><td class="num"><?= money($t['price']) ?></td></tr>
        <?php endforeach; if (!$trades) echo "<tr><td class='muted' colspan=3>brak</td></tr>"; ?>
      </tbody></table>
    </div>
  </div>

  <aside>
    <div class="panel">
      <h2>Złóż zlecenie</h2>
      <form method="post" action="place_order.php">
        <input type="hidden" name="stock_id" value="<?= $id ?>">
        <input type="hidden" name="side" id="side" value="buy">
        <div class="tradebtns">
          <button type="button" class="buy on" id="tb-buy">KUP</button>
          <button type="button" class="sell" id="tb-sell">SPRZEDAJ</button>
        </div>
        <label>Ilość (masz: <?= $owned ?> szt.)</label>
        <input type="number" name="qty" id="qty" min="1" value="10" required>
        <label>Cena limit (PLN)</label>
        <input type="number" step="0.01" name="price" id="price" value="<?= number_format($s['price'], 2, '.', '') ?>" required>
        <div class="chips">
          <span class="chip">Wartość: <b id="val">—</b></span>
        </div>
        <label style="margin-top:14px">Stop-Loss (opcjonalnie)</label>
        <input type="number" step="0.01" name="sl_price" placeholder="np. <?= number_format($s['price']*0.9,2,'.','') ?>">
        <label>Take-Profit (opcjonalnie)</label>
        <input type="number" step="0.01" name="tp_price" placeholder="np. <?= number_format($s['price']*1.1,2,'.','') ?>">
        <button class="btn buy" id="submit">Złóż zlecenie KUPNA</button>
      </form>
    </div>
    <p class="muted" style="margin-top:10px">SL/TP działają: co tick silnik sprawdza kurs i automatycznie zamyka pozycję.</p>
  </aside>
</div>

<script>
const side=document.getElementById('side'), qty=document.getElementById('qty'), price=document.getElementById('price');
const val=document.getElementById('val'), sub=document.getElementById('submit');
const bBuy=document.getElementById('tb-buy'), bSell=document.getElementById('tb-sell');
function upd(){ const v=(parseFloat(qty.value)||0)*(parseFloat(price.value)||0);
  val.textContent = v.toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2})+' PLN'; }
function setSide(s){ side.value=s;
  bBuy.classList.toggle('on',s==='buy'); bSell.classList.toggle('on',s==='sell');
  sub.className='btn '+s; sub.textContent = s==='buy'?'Złóż zlecenie KUPNA':'Złóż zlecenie SPRZEDAŻY'; }
bBuy.onclick=()=>setSide('buy'); bSell.onclick=()=>setSide('sell');
qty.oninput=upd; price.oninput=upd; upd();
</script>
<?php layout_footer();
