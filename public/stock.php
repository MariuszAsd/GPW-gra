<?php
require __DIR__ . '/_boot.php';
$user = require_login();

$id = (int) ($_GET['id'] ?? 0);
$s = Engine::row("SELECT s.*, sec.name AS sector FROM stocks s JOIN sectors sec ON sec.id=s.sector_id WHERE s.id=?", [$id]);
if (!$s) { flash('Nie ma takiej spółki.', 'err'); redirect('market.php'); }
$reports = Engine::all("SELECT * FROM financial_reports WHERE stock_id=? ORDER BY id DESC LIMIT 12", [$id]);
$news = Engine::all("SELECT * FROM news WHERE (scope='COMPANY' AND target_id=?) OR (scope='SECTOR' AND target_id=?) OR scope='MARKET' ORDER BY id DESC LIMIT 15", [$id, (int) $s['sector_id']]);

$ref = (float) $s['day_open_price'] > 0 ? (float) $s['day_open_price'] : (float) $s['price'];
$chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0;   // zmiana od otwarcia sesji
[, , $tps] = Engine::sessionInfo();

$candles = array_reverse(Engine::all("SELECT o,h,l,c,v FROM candles WHERE stock_id=? ORDER BY t DESC LIMIT 80", [$id]));
[$sessionNo] = Engine::sessionInfo();
$sessTurnover = (float) (Engine::one("SELECT SUM(v * c) FROM candles WHERE stock_id=? AND t >= ?", [$id, ($sessionNo - 1) * $tps]) ?: 0);
[$liqCls, $liqTxt] = liq_label($s['liquidity']);

$bids = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='buy'  AND status='active' GROUP BY price ORDER BY price DESC LIMIT 8", [$id]);
$asks = Engine::all("SELECT price, SUM(qty) q FROM orders WHERE stock_id=? AND side='sell' AND status='active' GROUP BY price ORDER BY price ASC  LIMIT 8", [$id]);
$maxDepth = max(1, ...array_map(fn($r) => (int) $r['q'], array_merge($bids, $asks) ?: [['q' => 1]]));

$owned = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$user['id'], $id]) ?: 0);
$trades = Engine::all("SELECT qty, price, created_at FROM transactions WHERE stock_id=? ORDER BY id DESC LIMIT 14", [$id]);
$mcap = (float) $s['price'] * (float) $s['total_shares'];

// --- świece (inline SVG) ---
$chartSvg = "<div style='padding:40px;text-align:center;color:var(--faint)'>Zbieram dane do wykresu…</div>";
if (count($candles) > 1) {
    // układ: góra 10..186 ceny, 192..236 słupki wolumenu (klasyka aplikacji tradingowych)
    $W = 680; $H = 240; $pl = 4; $pr = 4; $pt = 10; $pb = 54; $volTop = 192; $volH = 44;
    $lows = array_map(fn($c) => (float) $c['l'], $candles);
    $highs = array_map(fn($c) => (float) $c['h'], $candles);
    $mn = min($lows); $mx = max($highs); $rng = ($mx - $mn) ?: 1;
    $maxV = max(1, max(array_map(fn($c) => (int) $c['v'], $candles)));
    $n = count($candles); $slot = ($W - $pl - $pr) / $n; $bw = max(1.4, $slot * 0.62);
    $yv = fn($v) => round($pt + (1 - ($v - $mn) / $rng) * ($H - $pt - $pb), 1);
    $svg = "<svg class='chart' viewBox='0 0 $W $H' preserveAspectRatio='none'>";
    for ($g = 0; $g <= 3; $g++) { $gy = round($pt + $g / 3 * ($H - $pt - $pb), 1); $svg .= "<line x1='0' x2='$W' y1='$gy' y2='$gy' stroke='var(--line)' stroke-width='1'/>"; }
    $svg .= "<line x1='0' x2='$W' y1='" . ($volTop - 4) . "' y2='" . ($volTop - 4) . "' stroke='var(--line)' stroke-width='1'/>";
    foreach ($candles as $i => $c) {
        $x = round($pl + $i * $slot + $slot / 2, 1);
        $o = (float) $c['o']; $cl = (float) $c['c']; $col = $cl >= $o ? 'var(--up)' : 'var(--down)';
        $top = $yv(max($o, $cl)); $bot = $yv(min($o, $cl)); $bh = max(1, round($bot - $top, 1));
        $bx = round($x - $bw / 2, 1);
        $svg .= "<line x1='$x' x2='$x' y1='" . $yv((float) $c['h']) . "' y2='" . $yv((float) $c['l']) . "' stroke='$col' stroke-width='1'/>";
        $svg .= "<rect x='$bx' y='$top' width='" . round($bw, 1) . "' height='$bh' fill='$col'/>";
        if ((int) $c['v'] > 0) {   // słupek wolumenu
            $vh = max(1, round((int) $c['v'] / $maxV * $volH, 1));
            $svg .= "<rect x='$bx' y='" . round($volTop + $volH - $vh, 1) . "' width='" . round($bw, 1) . "' height='$vh' fill='$col' opacity='0.45'/>";
        }
    }
    $chartSvg = $svg . "</svg>";
}

layout_header($s['ticker'] . ' · ' . $s['name'], $user, 'market');
?>
<div class="shead">
  <div class="idn"><div class="tk"><?= h($s['ticker']) ?></div><div class="nm"><?= h($s['name']) ?> · <?= h($s['sector']) ?></div>
    <div class="nm" style="margin-top:3px">Obrót sesji: <b class="mono" data-turnover><?= money_short($sessTurnover) ?> PLN</b>
      · <span class="liq <?= $liqCls ?>">●</span> <?= $liqTxt ?><?= tip('Płynność mówi, jak łatwo kupić/sprzedać bez ruszania kursu. Przy niskiej płynności widełki bid-ask są szersze, a PKC może zrealizować się po gorszej cenie.', 'plynnosc') ?></div>
  </div>
  <div class="price">
    <div class="p" data-px><?= money($s['price']) ?> <span style="font-size:15px;color:var(--faint)">PLN</span></div>
    <span class="chg <?= $chg >= 0 ? 'p' : 'n' ?>" data-chg><span class="ar"><?= $chg >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($chg), 2, ',', ' ') ?>%</span>
  </div>
</div>

<div class="stock-grid">
  <div>
    <div class="panel" style="padding:8px">
      <div class="chartbar">
        <div class="cgrp" id="cg-iv">
          <button data-iv="1" class="on">1 tick</button>
          <button data-iv="5">5 ticków</button>
          <button data-iv="<?= (int) $tps ?>">Sesja</button>
        </div>
        <div class="cgrp" id="cg-type">
          <button data-type="candles" class="on">Świece</button>
          <button data-type="line">Linia</button>
        </div>
        <span class="muted" style="font-size:11px;margin-left:auto">1 świeca = 1 tick rynku (~1 min) · odświeża się co 5 s</span>
      </div>
      <div id="chartbox"><?= $chartSvg ?></div>
    </div>

    <div class="panel" style="margin-top:16px">
      <div class="subtabs">
        <button class="on" data-tab="book">Arkusz zleceń</button>
        <button data-tab="trades">Transakcje</button>
        <button data-tab="reports">Raporty</button>
        <button data-tab="news">Wiadomości</button>
        <button data-tab="info">Info</button>
      </div>

      <div class="tabpane on" id="tab-book">
        <div class="book">
          <div class="col b"><h3>Kupno (bid) <span class="muted" style="text-transform:none;letter-spacing:0">· razem <?= array_sum(array_map(fn($r) => (int) $r['q'], $bids)) ?> szt.</span></h3>
            <?php foreach ($bids as $r): ?>
              <div class="lvl"><span class="depth" style="width:<?= (int) round($r['q'] / $maxDepth * 100) ?>%"></span><span class="p"><?= money($r['price']) ?></span><span class="q"><?= (int) $r['q'] ?></span></div>
            <?php endforeach; if (!$bids) echo "<div class='muted' style='padding:6px'>brak</div>"; ?>
          </div>
          <div class="col s"><h3>Sprzedaż (ask) <span class="muted" style="text-transform:none;letter-spacing:0">· razem <?= array_sum(array_map(fn($r) => (int) $r['q'], $asks)) ?> szt.</span></h3>
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

      <div class="tabpane" id="tab-reports">
        <table><thead><tr><th>Okres</th><th class="num">Przychody</th><th class="num">Zysk netto</th><th class="num">EPS</th><th class="num">Niespodzianka</th><th class="num">Dywidenda<?= tip('Część zysku wypłacana akcjonariuszom za każdą akcję. W dniu wypłaty kurs jest pomniejszany o jej wartość (odcięcie).', 'dywidenda') ?></th></tr></thead><tbody>
          <?php foreach ($reports as $r): $sp = (float) $r['surprise_pct']; $dv = (float) ($r['dividend'] ?? 0); ?>
            <tr><td><?= h($r['period']) ?></td><td class="num"><?= money($r['revenue']) ?></td><td class="num"><?= money($r['net_profit']) ?></td><td class="num"><?= number_format($r['eps'], 2, ',', ' ') ?></td><td class="num <?= $sp >= 0 ? 'up' : 'down' ?>"><?= ($sp >= 0 ? '+' : '') . number_format($sp, 1, ',', ' ') ?>%</td><td class="num"><?= $dv > 0 ? '<b class="up">' . money($dv) . '</b>' : '<span class="muted">—</span>' ?></td></tr>
          <?php endforeach; if (!$reports) echo "<tr><td class='muted' colspan=6>brak raportów</td></tr>"; ?>
        </tbody></table>
      </div>

      <div class="tabpane" id="tab-news">
        <?php foreach ($news as $nw): $tc = $nw['type'] === 'POS' ? 'up' : ($nw['type'] === 'NEG' ? 'down' : 'soft'); ?>
          <div style="padding:9px 2px;border-bottom:1px solid var(--line)">
            <?php if ($nw['is_espi']): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)">ESPI</span> <?php endif; ?>
            <span class="<?= $tc ?>" style="font-weight:600"><?= h($nw['headline']) ?></span>
            <div class="muted" style="font-size:12px;margin-top:2px"><?= h(substr($nw['published_at'], 0, 16)) ?> · <?= h($nw['scope']) ?></div>
          </div>
        <?php endforeach; if (!$news) echo "<div class='muted' style='padding:8px'>brak wiadomości</div>"; ?>
      </div>

      <div class="tabpane" id="tab-info">
        <?php if (!empty($s['description'])): ?><p class="soft" style="margin:4px 0 12px"><?= h($s['description']) ?></p><?php endif; ?>
        <table><tbody>
          <tr><td class="muted">Sektor</td><td class="num"><?= h($s['sector']) ?></td></tr>
          <tr><td class="muted">Kapitalizacja</td><td class="num"><?= money($mcap) ?> PLN</td></tr>
          <tr><td class="muted">Liczba akcji</td><td class="num"><?= number_format($s['total_shares'], 0, ',', ' ') ?></td></tr>
          <tr><td class="muted">Wartość fundamentalna</td><td class="num"><?= money($s['fundamental']) ?> PLN</td></tr>
          <tr><td class="muted">C/Z docelowe</td><td class="num"><?= number_format($s['pe_target'], 1, ',', ' ') ?></td></tr>
          <tr><td class="muted">EPS (roczny)</td><td class="num"><?= number_format($s['last_eps'], 2, ',', ' ') ?> PLN</td></tr>
          <?php $po = (float) ($s['dividend_payout'] ?? 0); ?>
          <tr><td class="muted">Polityka dywidendy<?= tip('Jaką część miesięcznego zysku spółka wypłaca akcjonariuszom. Wystarczy mieć akcje w dniu raportu.', 'dywidenda') ?></td>
              <td class="num"><?= $po > 0 ? '<b class="up">' . number_format($po * 100, 0) . '% zysku</b> <span class="muted">(~' . number_format($po / max(1, (float) $s['pe_target']) * 100, 1, ',', ' ') . '% rocznie)</span>' : '<span class="muted">nie wypłaca (reinwestuje)</span>' ?></td></tr>
        </tbody></table>
      </div>
    </div>
  </div>

  <aside class="panel orderpanel">
    <h2>Zlecenie</h2>
    <form method="post" action="place_order.php">
      <input type="hidden" name="stock_id" value="<?= $id ?>">
      <input type="hidden" name="side" id="side" value="buy">
      <input type="hidden" name="type" id="type" value="limit">
      <div class="seg">
        <button type="button" class="buy on" id="tb-buy">KUP</button>
        <button type="button" class="sell" id="tb-sell">SPRZEDAJ</button>
      </div>
      <div class="seg" style="margin-top:0;align-items:center">
        <button type="button" class="on" id="tt-limit" title="Zlecenie z limitem ceny — czeka w arkuszu">LIMIT</button>
        <button type="button" id="tt-pkc" title="Po każdej cenie — kupuje/sprzedaje natychmiast z arkusza">PKC</button>
        <?= tip('LIMIT: podajesz swoją cenę i czekasz na realizację. PKC: bierzesz od razu to, co jest w arkuszu.', 'limit') ?>
      </div>
      <label>Ilość <span class="muted">(masz: <?= $owned ?> szt.)</span></label>
      <input type="number" name="qty" id="qty" min="1" value="10" required>
      <div id="f-price">
        <label>Cena limit (PLN)</label>
        <input type="number" step="0.01" name="price" id="price" value="<?= number_format($s['price'], 2, '.', '') ?>" required>
      </div>
      <div id="f-validity">
        <label>Ważność zlecenia<?= tip('Bezterminowe czeka aż je anulujesz. Sesyjne samo znika z końcem sesji, a rezerwacja wraca.', 'waznosc') ?></label>
        <select name="validity">
          <option value="gtc">Bezterminowe</option>
          <option value="session">Do końca sesji</option>
        </select>
      </div>
      <div class="summary"><span id="val-label">Wartość zlecenia</span><b id="val">—</b></div>
      <?php if ($liqCls === 'lo'): ?>
      <p class="muted" style="font-size:12px;margin:0 0 4px"><span class="liq lo">●</span> <b>Niska płynność</b> — arkusz jest płytki: zlecenie PKC może zrealizować się po wyraźnie gorszej cenie (poślizg). Bezpieczniej użyć zlecenia LIMIT.</p>
      <?php endif; ?>
      <div id="f-sltp">
        <div class="adv">
          <div><label>Stop-Loss<?= tip('Automatyczny hamulec strat: gdy kurs SPADNIE do progu, gra sama sprzeda ten pakiet.', 'sl') ?></label><input type="number" step="0.01" name="sl_price" placeholder="—"></div>
          <div><label>Take-Profit<?= tip('Automatyczna kasa zysku: gdy kurs WZROŚNIE do progu, gra sama sprzeda ten pakiet.', 'tp') ?></label><input type="number" step="0.01" name="tp_price" placeholder="—"></div>
        </div>
        <p class="muted" style="font-size:11px;margin:6px 0 0">Utworzy zlecenie obronne na kupowany pakiet — widoczne i anulowalne w Portfelu.</p>
      </div>
      <button class="btn buy" id="submit" style="margin-top:14px">Kup <?= h($s['ticker']) ?></button>
    </form>
    <?php $feePct = Engine::one("SELECT v FROM game_state WHERE k='fee_rate'"); $feePct = ($feePct === false || $feePct === null) ? 0.5 : (float) $feePct; ?>
    <p class="muted" style="margin-top:10px;font-size:12px">Prowizja <?= rtrim(rtrim(number_format($feePct, 2, ',', ''), '0'), ',') ?>% wartości pobierana przy sprzedaży. SL/TP są egzekwowane przez silnik: co tick sprawdza kurs i zamyka pozycję.</p>
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
const val=document.getElementById('val'), sub=document.getElementById('submit'), type=document.getElementById('type');
const tk=<?= json_encode($s['ticker']) ?>, curPx=<?= json_encode((float) $s['price']) ?>;
function upd(){ const px=type.value==='market'?curPx:(parseFloat(price.value)||0);
  const v=(parseFloat(qty.value)||0)*px;
  document.getElementById('val-label').textContent=type.value==='market'?'Wartość (szacunkowo)':'Wartość zlecenia';
  val.textContent='≈ '.repeat(type.value==='market'?1:0)+v.toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2})+' PLN'; }
function setSide(s){ side.value=s;
  document.getElementById('tb-buy').classList.toggle('on',s==='buy');
  document.getElementById('tb-sell').classList.toggle('on',s==='sell');
  document.getElementById('f-sltp').style.display=s==='buy'?'':'none';
  sub.className='btn '+s; sub.textContent=(s==='buy'?'Kup ':'Sprzedaj ')+tk; }
function setType(t){ type.value=t;
  document.getElementById('tt-limit').classList.toggle('on',t==='limit');
  document.getElementById('tt-pkc').classList.toggle('on',t==='market');
  document.getElementById('f-price').style.display=t==='limit'?'':'none';
  document.getElementById('f-validity').style.display=t==='limit'?'':'none';
  price.required=(t==='limit'); upd(); }
document.getElementById('tb-buy').onclick=()=>setSide('buy');
document.getElementById('tb-sell').onclick=()=>setSide('sell');
document.getElementById('tt-limit').onclick=()=>setType('limit');
document.getElementById('tt-pkc').onclick=()=>setType('market');
qty.oninput=upd; price.oninput=upd; upd();
// wykres: rysowanie w przeglądarce (świece/linia, interwał) + auto-odświeżanie
const chartBox=document.getElementById('chartbox');
let cIv=1, cType='candles';
async function drawChart(){ try{
  const j=await (await fetch('api_chart.php?id=<?= $id ?>&iv='+cIv)).json();
  if(!j.ok||j.candles.length<2) return;
  const W=680,H=240,pl=4,pr=4,pt=10,pb=54,volTop=192,volH=44,cs=j.candles,n=cs.length;
  let mn,mx;
  if(cType==='line'){ const v=cs.map(c=>c.c); mn=Math.min(...v); mx=Math.max(...v); }
  else { mn=Math.min(...cs.map(c=>c.l)); mx=Math.max(...cs.map(c=>c.h)); }
  const rng=(mx-mn)||1, slot=(W-pl-pr)/n, bw=Math.max(1.4,slot*0.62);
  const maxV=Math.max(1,...cs.map(c=>c.v||0));
  const yv=v=>(pt+(1-(v-mn)/rng)*(H-pt-pb)).toFixed(1);
  let s='<svg class="chart" viewBox="0 0 '+W+' '+H+'" preserveAspectRatio="none">';
  for(let g=0;g<=3;g++){ const gy=(pt+g/3*(H-pt-pb)).toFixed(1);
    s+='<line x1="0" x2="'+W+'" y1="'+gy+'" y2="'+gy+'" stroke="var(--line)" stroke-width="1"/>'; }
  s+='<line x1="0" x2="'+W+'" y1="'+(volTop-4)+'" y2="'+(volTop-4)+'" stroke="var(--line)" stroke-width="1"/>';
  for(let i=0;i<n;i++){ const c=cs[i]; if(!(c.v>0)) continue;
    const col=c.c>=c.o?'var(--up)':'var(--down)', vh=Math.max(1,c.v/maxV*volH);
    s+='<rect x="'+(pl+i*slot+slot/2-bw/2).toFixed(1)+'" y="'+(volTop+volH-vh).toFixed(1)+'" width="'+bw.toFixed(1)+'" height="'+vh.toFixed(1)+'" fill="'+col+'" opacity="0.45"/>'; }
  if(cType==='line'){
    const pts=cs.map((c,i)=>(pl+i*slot+slot/2).toFixed(1)+','+yv(c.c)).join(' ');
    const col=cs[n-1].c>=cs[0].c?'var(--up)':'var(--down)';
    s+='<polygon points="'+pl+','+H+' '+pts+' '+(W-pr)+','+H+'" fill="'+col+'" opacity="0.10"/>';
    s+='<polyline points="'+pts+'" fill="none" stroke="'+col+'" stroke-width="1.6" stroke-linejoin="round"/>';
  } else {
    for(let i=0;i<n;i++){ const c=cs[i], x=pl+i*slot+slot/2, col=c.c>=c.o?'var(--up)':'var(--down)';
      const top=+yv(Math.max(c.o,c.c)), bot=+yv(Math.min(c.o,c.c)), bh=Math.max(1,bot-top);
      s+='<line x1="'+x.toFixed(1)+'" x2="'+x.toFixed(1)+'" y1="'+yv(c.h)+'" y2="'+yv(c.l)+'" stroke="'+col+'" stroke-width="1"/>';
      s+='<rect x="'+(x-bw/2).toFixed(1)+'" y="'+top.toFixed(1)+'" width="'+bw.toFixed(1)+'" height="'+bh.toFixed(1)+'" fill="'+col+'"/>'; }
  }
  chartBox.innerHTML=s+'</svg>';
}catch(e){} }
document.querySelectorAll('#cg-iv button').forEach(b=>b.onclick=()=>{
  document.querySelectorAll('#cg-iv button').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); cIv=parseInt(b.dataset.iv)||1; drawChart(); });
document.querySelectorAll('#cg-type button').forEach(b=>b.onclick=()=>{
  document.querySelectorAll('#cg-type button').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); cType=b.dataset.type; drawChart(); });
drawChart();
// live kurs w nagłówku + odświeżenie wykresu
setInterval(async()=>{ try{
  const j=await (await fetch('api_market.php')).json(); if(!j.ok) return; const d=j.data[<?= $id ?>]; if(!d) return;
  const p=document.querySelector('[data-px]'); const c=document.querySelector('[data-chg]'); const up=d.chg>=0;
  if(p) p.innerHTML=Number(d.price).toLocaleString('pl-PL',{minimumFractionDigits:2,maximumFractionDigits:2})+' <span style="font-size:15px;color:var(--faint)">PLN</span>';
  if(c){ c.className='chg '+(up?'p':'n'); c.innerHTML='<span class="ar">'+(up?'▲':'▼')+'</span>'+Math.abs(d.chg).toFixed(2).replace('.',',')+'%'; }
  drawChart();
}catch(e){} },5000);
</script>
<?php layout_footer();
