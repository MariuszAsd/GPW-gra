<?php
/**
 * Branże: trendy sektorowe — indeks branży (ważony kapitalizacją), zmiana
 * dzienna i ~5-sesyjna, sparkline, liderzy wzrostów/spadków, spółki branży.
 * Podzakładka modułu Rynek (market_subnav).
 */
require __DIR__ . '/_boot.php';
$user = require_login();
[$sessionNo, , $tps] = Engine::sessionInfo();
$tickNow = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);

// spółki z dzienną zmianą, pogrupowane po sektorze
$rows = Engine::all("SELECT s.id, s.ticker, s.name, s.price, s.day_open_price, s.total_shares, sec.id AS sec_id, sec.name AS sec_name
                     FROM stocks s JOIN sectors sec ON sec.id = s.sector_id ORDER BY sec.name, s.ticker");
$sectors = [];
foreach ($rows as $r) {
    $sid = (int) $r['sec_id'];
    $sectors[$sid]['name'] = $r['sec_name'];
    $ref = (float) $r['day_open_price'] > 0 ? (float) $r['day_open_price'] : (float) $r['price'];
    $r['chg'] = $ref > 0 ? ((float) $r['price'] - $ref) / $ref * 100 : 0;
    $sectors[$sid]['stocks'][] = $r;
    $sectors[$sid]['cur'] = ($sectors[$sid]['cur'] ?? 0) + (float) $r['price'] * (float) $r['total_shares'];
    $sectors[$sid]['op']  = ($sectors[$sid]['op'] ?? 0) + $ref * (float) $r['total_shares'];
}

// historia indeksu branży: kapitalizacja per (sektor, tick) z ostatnich ~5 sesji świec
$histFrom = max(0, $tickNow - 5 * max(1, $tps));
$secHist = [];
foreach (Engine::all("SELECT s.sector_id AS sid, c.t, SUM(c.c * s.total_shares) v
                      FROM candles c JOIN stocks s ON s.id = c.stock_id
                      WHERE c.t >= ? GROUP BY s.sector_id, c.t ORDER BY c.t", [$histFrom]) as $r) {
    $secHist[(int) $r['sid']][] = (float) $r['v'];
}
$sparkSvg = function (array $vals): string {
    if (count($vals) < 2) return '';
    $W = 120; $H = 30; $mn = min($vals); $mx = max($vals); $rng = ($mx - $mn) ?: 1; $n = count($vals); $pts = [];
    foreach ($vals as $i => $v) $pts[] = round($i / ($n - 1) * $W, 1) . ',' . round(2 + (1 - ($v - $mn) / $rng) * ($H - 4), 1);
    $col = end($vals) >= $vals[0] ? 'var(--up)' : 'var(--down)';
    return "<svg class='spark' style='width:120px;height:30px' viewBox='0 0 $W $H' preserveAspectRatio='none'><polyline points='" . implode(' ', $pts) . "' fill='none' stroke='$col' stroke-width='1.4' stroke-linejoin='round'/></svg>";
};

// sortowanie kart: najmocniejsza branża dnia na górze
uasort($sectors, function ($a, $b) {
    $ca = ($a['op'] ?? 0) > 0 ? ($a['cur'] / $a['op'] - 1) : 0;
    $cb = ($b['op'] ?? 0) > 0 ? ($b['cur'] / $b['op'] - 1) : 0;
    return $cb <=> $ca;
});

layout_header('Branże', $user, 'market');
?>
<div class="page-head"><h1>Rynek</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span></div>
<?php market_subnav('bra'); ?>
<?php explainer('branze', 'Po co patrzeć na branże', [
    'sektory poruszają się falami', 'wydarzenia branżowe ciągną wszystkie spółki',
    'kupuj liderów mocnych branż', 'albo okazje w przecenionych']); ?>

<section class="panel">
  <h2>Trendy branżowe <span class="muted" style="text-transform:none;letter-spacing:0;font-size:12px">· zmiana od otwarcia sesji · wykres z ~5 sesji · najmocniejsze na górze</span></h2>
  <?php foreach ($sectors as $secId => $sec):
      $chg = ($sec['op'] ?? 0) > 0 ? ($sec['cur'] / $sec['op'] - 1) * 100 : 0;
      $h = $secHist[$secId] ?? [];
      $chg5 = count($h) > 1 && $h[0] > 0 ? (end($h) / $h[0] - 1) * 100 : null;
      usort($sec['stocks'], fn($a, $b) => $b['chg'] <=> $a['chg']);
      $best = $sec['stocks'][0]; $worst = end($sec['stocks']);
  ?>
    <div class="sec-card">
      <div class="sec-head">
        <b><?= h($sec['name']) ?></b>
        <span class="chg <?= $chg >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $chg >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($chg), 2, ',', ' ') ?>%</span>
        <?php if ($chg5 !== null): ?>
          <span class="muted" style="font-size:11.5px">5 sesji: <b class="<?= $chg5 >= 0 ? 'up' : 'down' ?>"><?= ($chg5 >= 0 ? '+' : '') . number_format($chg5, 1, ',', ' ') ?>%</b></span>
        <?php endif; ?>
        <span style="margin-left:auto"><?= $sparkSvg($h) ?></span>
      </div>
      <div class="muted" style="font-size:11.5px;margin-top:4px">
        najmocniejsza: <a href="stock.php?id=<?= (int) $best['id'] ?>"><b><?= h($best['ticker']) ?></b></a>
        <span class="<?= $best['chg'] >= 0 ? 'up' : 'down' ?>"><?= ($best['chg'] >= 0 ? '+' : '') . number_format($best['chg'], 1, ',', ' ') ?>%</span>
        · najsłabsza: <a href="stock.php?id=<?= (int) $worst['id'] ?>"><b><?= h($worst['ticker']) ?></b></a>
        <span class="<?= $worst['chg'] >= 0 ? 'up' : 'down' ?>"><?= ($worst['chg'] >= 0 ? '+' : '') . number_format($worst['chg'], 1, ',', ' ') ?>%</span>
      </div>
      <div class="sec-tickers">
        <?php foreach ($sec['stocks'] as $st): ?>
          <a class="tag" style="padding:4px 9px" href="stock.php?id=<?= (int) $st['id'] ?>"><?= h($st['ticker']) ?>
            <b class="<?= $st['chg'] >= 0 ? 'up' : 'down' ?> mono" style="font-size:10.5px"><?= ($st['chg'] >= 0 ? '+' : '') . number_format($st['chg'], 1, ',', ' ') ?>%</b></a>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</section>
<p class="muted" style="font-size:11.5px;margin-top:10px">Indeks branży ważony kapitalizacją spółek. Wydarzenia sektorowe (Newsy i ESPI) poruszają całą branżą — silna/słaba branża to często wiatr w plecy albo w oczy dla każdej jej spółki.</p>
<?php layout_footer();
