<?php
require __DIR__ . '/_boot.php';
$user = require_login();

[$sessionNo, , $tps] = Engine::sessionInfo();
$sessStart = ($sessionNo - 1) * $tps;
$isPremium = Tokens::hasPass((int) $user['id'], 'analityk');
$stocks = Engine::all("SELECT s.id, s.ticker, s.name, sec.name AS sector, s.price, s.day_open_price, s.liquidity, s.ta_signal,
                              (SELECT SUM(c.v * c.c) FROM candles c WHERE c.stock_id = s.id AND c.t >= $sessStart) AS turnover
                       FROM stocks s JOIN sectors sec ON sec.id=s.sector_id ORDER BY s.ticker");

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
// aktywne wydarzenie rynkowe/sektorowe (krach, hossa, kryzys/boom) -> baner
$tickNow = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
$event = Engine::row("SELECT n.*, sec.name AS sector_name FROM news n
                      LEFT JOIN sectors sec ON sec.id = n.target_id AND n.scope = 'SECTOR'
                      WHERE n.expire_tick > ? AND ABS(n.impact_strength) >= 0.8 AND n.scope IN ('MARKET','SECTOR')
                      ORDER BY n.id DESC LIMIT 1", [$tickNow]);

// sektory: dzienna zmiana ważona kapitalizacją
$sectorRows = Engine::all("SELECT sec.name, SUM(s.price*s.total_shares) cur, SUM(s.day_open_price*s.total_shares) op
                           FROM stocks s JOIN sectors sec ON sec.id=s.sector_id GROUP BY sec.id, sec.name ORDER BY sec.name");
$bid = []; foreach (Engine::all("SELECT stock_id, MAX(price) p FROM orders WHERE side='buy'  AND status='active' GROUP BY stock_id") as $r) $bid[$r['stock_id']] = $r['p'];
$ask = []; foreach (Engine::all("SELECT stock_id, MIN(price) p FROM orders WHERE side='sell' AND status='active' GROUP BY stock_id") as $r) $ask[$r['stock_id']] = $r['p'];

// sparkline: zamknięcia z ostatnich ~40 ticków (jedno zapytanie dla wszystkich spółek)
$spark = [];
foreach (Engine::all("SELECT stock_id, c FROM candles WHERE t > ? ORDER BY stock_id, t", [$tickNow - 40]) as $r) {
    $spark[(int) $r['stock_id']][] = (float) $r['c'];
}
$sparkSvg = function (array $vals): string {
    if (count($vals) < 2) return '';
    $W = 84; $H = 24; $mn = min($vals); $mx = max($vals); $rng = ($mx - $mn) ?: 1; $n = count($vals); $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i / ($n - 1) * $W, 1) . ',' . round(2 + (1 - ($v - $mn) / $rng) * ($H - 4), 1);
    $col = end($vals) >= $vals[0] ? 'var(--up)' : 'var(--down)';
    return "<svg class='spark' viewBox='0 0 $W $H' preserveAspectRatio='none'><polyline points='" . implode(' ', $pts) . "' fill='none' stroke='$col' stroke-width='1.3' stroke-linejoin='round'/></svg>";
};

layout_header('Rynek', $user, 'market');
?>
<?php [$mhOn, $mhOpen, $mhClose] = Engine::marketHours(); $mhIsOpen = Engine::marketIsOpen(); ?>
<div class="page-head"><h1>Rynek</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span>
  <?php if ($mhOn): ?>
    <span class="tag" style="<?= $mhIsOpen ? 'color:var(--up);border-color:var(--up)' : 'color:var(--faint)' ?>"><?= $mhIsOpen ? "● otwarty do $mhClose" : "○ zamknięty · otwarcie $mhOpen" ?></span>
  <?php endif; ?>
  <span class="muted">zmiana liczona od otwarcia sesji · kliknij, aby handlować</span></div>
<?php explainer('rynek', 'Jak korzystać z Rynku', [
    'przeglądaj kursy i sektory', 'kliknij spółkę, aby ją otworzyć',
    'kupuj taniej, sprzedawaj drożej', 'rozmawiaj na czacie obok']); ?>

<?php if ($event): $neg = $event['type'] === 'NEG'; $left = (int) $event['expire_tick'] - $tickNow; ?>
<div class="panel" style="margin-bottom:16px;border-left:3px solid var(--<?= $neg ? 'down' : 'up' ?>);background:var(--<?= $neg ? 'down' : 'up' ?>-bg)">
  <b class="<?= $neg ? 'down' : 'up' ?>" style="font-size:15px"><?= h($event['headline']) ?></b>
  <div class="muted" style="font-size:12px;margin-top:3px"><?= h($event['body']) ?> · siła wydarzenia wygasa za ~<?= max(1, $left) ?> ticków</div>
</div>
<?php endif; ?>
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
<div class="market-grid">
<div>
<div class="panel" style="padding:0;overflow:hidden">
  <div class="tbl-scroll">
    <table class="mw">
      <thead><tr><th>Instrument</th><th></th><th class="num">Kurs</th><th class="num">Zmiana</th><th class="num">Sygnał AT<?= $isPremium ? '' : ' 🔒' ?><?= tip('Zbiorczy sygnał analizy technicznej (10 wskaźników z zakładki Analiza). Skaner całego rynku to funkcja Pakietu Analityka — pojedynczo sprawdzisz za darmo na karcie spółki.', '') ?></th><th class="num">Bid</th><th class="num">Ask</th><th class="num">Obrót (sesja)<?= tip('Za ile PLN handlowano akcjami tej spółki od otwarcia sesji. Kropka pokazuje płynność: przy niskiej (czerwonej) kupno/sprzedaż większych pakietów rusza kursem.', 'plynnosc') ?></th></tr></thead>
      <tbody>
      <?php foreach ($stocks as $s): $id = (int) $s['id'];
          $ref = (float) $s['day_open_price'] > 0 ? (float) $s['day_open_price'] : (float) $s['price'];
          $chg = $ref > 0 ? ((float) $s['price'] - $ref) / $ref * 100 : 0;
          [$liqCls, $liqTxt] = liq_label($s['liquidity']); ?>
        <tr onclick="location='stock.php?id=<?= $id ?>'">
          <td><div class="sym"><span class="tk"><?= h($s['ticker']) ?></span><span class="nm"><?= h($s['name']) ?></span><span class="tag"><?= h($s['sector']) ?></span></div></td>
          <td style="padding:4px 6px"><?= $sparkSvg($spark[$id] ?? []) ?></td>
          <td class="num px" data-px="<?= $id ?>"><?= money($s['price']) ?></td>
          <td class="num"><span class="chg <?= $chg >= 0 ? 'p' : 'n' ?>" data-chg="<?= $id ?>"><span class="ar"><?= $chg >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($chg), 2, ',', ' ') ?>%</span></td>
          <td class="num">
            <?php if ($isPremium): [$vTxt, $vCls] = Technical::verdict((float) $s['ta_signal']); ?>
              <span class="chg <?= $vCls ?>" style="font-size:11px"><?= h($vTxt) ?></span>
            <?php else: ?>
              <a href="sklep.php" class="muted" title="Odblokuj skaner — Pakiet Analityka" onclick="event.stopPropagation()">🔒</a>
            <?php endif; ?>
          </td>
          <td class="num bid"><?= isset($bid[$id]) ? money($bid[$id]) : '—' ?></td>
          <td class="num ask"><?= isset($ask[$id]) ? money($ask[$id]) : '—' ?></td>
          <td class="num nowrap"><span class="mono" data-vol="<?= $id ?>"><?= money_short((float) $s['turnover']) ?></span> <span class="liq <?= $liqCls ?>" title="<?= $liqTxt ?>">●</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
</div>

<aside class="panel chatbox">
  <h2 style="margin-bottom:8px">Czat rynkowy<?= tip('Rozmowy graczy na żywo. Plotki bywają prawdziwe… albo i nie — jak na prawdziwym parkiecie. GM widzi wszystko.', '') ?></h2>
  <div id="chat-list" class="chat-list"><p class="muted" style="padding:8px">Ładowanie…</p></div>
  <form id="chat-form" class="chat-form" autocomplete="off">
    <input type="text" id="chat-msg" maxlength="300" placeholder="Napisz coś…">
    <button class="btn sm">➤</button>
  </form>
</aside>
</div>
<script>
// --- czat rynkowy ---
let chatSince = 0, chatAdmin = 0; const myUid = <?= (int) $user['id'] ?>;
const chatList = document.getElementById('chat-list');
function chatRow(m) {
  const del = chatAdmin ? ` <a href="#" class="chat-del" data-id="${m.id}" title="Ukryj wpis">✕</a>` : '';
  const who = m.pl ? `<a class="chat-u" href="gracz.php?id=${m.uid}">${esc(m.u)}</a>`
                   : `<span class="chat-u${m.gm ? ' gm' : ''}">${esc(m.u)}</span>`;   // GM/QA bez linku do profilu
  return `<div class="chat-msg${m.uid === myUid ? ' own' : ''}" data-mid="${m.id}"><span class="chat-t">${m.t}</span> ${who}: ${esc(m.m)}${del}</div>`;
}
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
let chatBusy = false;
async function chatPoll(first) {
  if (chatBusy) return;   // nie nakładaj równoległych polli (duplikaty)
  chatBusy = true;
  try {
    const j = await (await fetch('api_chat.php?since=' + chatSince)).json();
    if (!j.ok) return;
    chatAdmin = j.admin;
    if (first) chatList.innerHTML = j.msgs.length ? '' : '<p class="muted" style="padding:8px">Cisza na parkiecie — napisz coś pierwszy!</p>';
    if (j.msgs.length) {
      if (first || chatSince === 0) chatList.innerHTML = '';
      for (const m of j.msgs) {
        if (chatList.querySelector(`[data-mid="${m.id}"]`)) continue;   // dedup po id
        chatList.insertAdjacentHTML('beforeend', chatRow(m));
        chatSince = Math.max(chatSince, m.id);
      }
      chatList.scrollTop = chatList.scrollHeight;
    }
    for (const hid of (j.hidden || [])) {   // moderacja GM znika u wszystkich na żywo
      const el = chatList.querySelector(`[data-mid="${hid}"]`); if (el) el.remove();
    }
  } catch (e) {} finally { chatBusy = false; }
}
document.getElementById('chat-form').onsubmit = async (ev) => {
  ev.preventDefault();
  const inp = document.getElementById('chat-msg'), v = inp.value.trim();
  if (!v) return;
  const fd = new FormData(); fd.append('msg', v);
  const j = await (await fetch('api_chat.php', { method: 'POST', body: fd })).json();
  if (j.ok) { inp.value = ''; chatPoll(false); } else if (j.err) alert(j.err);
};
chatList.addEventListener('click', async (ev) => {
  const del = ev.target.closest('.chat-del'); if (!del) return;
  ev.preventDefault();
  const fd = new FormData(); fd.append('del', del.dataset.id);
  await fetch('api_chat.php', { method: 'POST', body: fd });
  del.closest('.chat-msg').remove();
});
chatPoll(true);
setInterval(() => chatPoll(false), 5000);
</script>
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
      const vc = document.querySelector(`[data-vol="${id}"]`); if (vc && d.vol !== undefined) vc.textContent = d.vol;
      if (cg) { const up = d.chg >= 0; cg.className = 'chg ' + (up ? 'p' : 'n');
        cg.innerHTML = '<span class="ar">' + (up ? '▲' : '▼') + '</span>' + Math.abs(d.chg).toFixed(2).replace('.', ',') + '%'; }
    }
  } catch (e) {}
}, 5000);
</script>
<?php layout_footer();
