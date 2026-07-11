<?php
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());

$id = (int) ($_GET['id'] ?? 0);
$s = Engine::row("SELECT s.*, sec.name AS sector FROM stocks s JOIN sectors sec ON sec.id=s.sector_id WHERE s.id=?", [$id]);
if (!$s) { flash('Nie ma takiej spółki.', 'err'); redirect('market.php'); }

// FORUM SPÓŁKI: wpis (anty-spam 15 s) i moderacja GM
$uidForum = (int) ($user['owner_id'] ?? $user['id']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment'])) {
    $msg = trim((string) $_POST['comment']);
    if ($msg === '' || mb_strlen($msg) > 300) flash('Wpis musi mieć 1–300 znaków.', 'err');
    else {
        $cutoff = date('Y-m-d H:i:s', time() - 15);
        $st = Db::pdo()->prepare("INSERT INTO stock_comments (stock_id, user_id, message, created_at)
                                  SELECT ?,?,?,? FROM (SELECT 1 AS one) t
                                  WHERE NOT EXISTS (SELECT 1 FROM stock_comments WHERE user_id=? AND created_at > ?)");
        $st->execute([$id, $uidForum, $msg, Db::now(), $uidForum, $cutoff]);
        flash($st->rowCount() > 0 ? 'Wpis dodany do dyskusji.' : 'Nie tak szybko — odczekaj chwilę między wpisami.', $st->rowCount() > 0 ? 'ok' : 'err');
    }
    redirect("stock.php?id=$id&tab=forum");
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['del_comment']) && ($user['role'] ?? '') === 'admin') {
    Db::pdo()->prepare("UPDATE stock_comments SET deleted=1 WHERE id=?")->execute([(int) $_POST['del_comment']]);
    Log::write('info', 'gm', 'forum.delete', 'ukryto wpis #' . (int) $_POST['del_comment'] . " na spółce #$id");
    flash('Wpis ukryty.');
    redirect("stock.php?id=$id&tab=forum");
}
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
$holders = Engine::all("SELECT u.id AS uid, u.username, u.is_bot, u.role, (w.qty + w.qty_reserved) AS n
                        FROM wallets w JOIN users u ON u.id = w.user_id
                        WHERE w.stock_id=? AND (w.qty + w.qty_reserved) > 0 AND u.role <> 'qa'
                        ORDER BY n DESC LIMIT 10", [$id]);
$heldTotal = max(1, (int) (Engine::one("SELECT SUM(w.qty + w.qty_reserved) FROM wallets w JOIN users u ON u.id = w.user_id
                                        WHERE w.stock_id=? AND u.role <> 'qa'", [$id]) ?: 1));
$trades = Engine::all("SELECT qty, price, created_at FROM transactions WHERE stock_id=? ORDER BY id DESC LIMIT 14", [$id]);
$mcap = (float) $s['price'] * (float) $s['total_shares'];

// analiza techniczna: sygnały, wagi tej spółki, sygnał zbiorczy i charakter
$taSig = Technical::signals($id);
$taW = Technical::weights($id);
$taComp = Technical::composite($id);
[$taVerdict, $taCls] = Technical::verdict($taComp);
$taAff = (float) ($s['tech_affinity'] ?? 0.5);

// forum spółki: 40 najnowszych wpisów graczy
$comments = Engine::all("SELECT c.*, u.username, u.chat_color, u.role AS urole, u.title AS utitle
                         FROM stock_comments c JOIN users u ON u.id = c.user_id
                         WHERE c.stock_id=? AND c.deleted=0 ORDER BY c.id DESC LIMIT 40", [$id]);
$openTab = in_array($_GET['tab'] ?? '', ['forum'], true) ? $_GET['tab'] : '';

// obserwowane + Raport Premium (pakiety liczą się na koncie głównym, także w trybie wyzwania)
$uidReal = (int) ($user['owner_id'] ?? $user['id']);
$isWatched = (bool) Engine::one("SELECT id FROM watchlist WHERE user_id=? AND stock_id=?", [$uidReal, $id]);
$hasRaport = Tokens::hasPass($uidReal, 'raport');

// --- świece (inline SVG) ---
$chartSvg = "<div style='padding:40px;text-align:center;color:var(--faint)'>Zbieram dane do wykresu…</div>";
if (count($candles) > 1) {
    // układ: góra 10..186 ceny, 192..236 słupki wolumenu (klasyka aplikacji tradingowych)
    $W = 680; $H = 240; $pl = 16; $pr = 16; $pt = 12; $pb = 54; $volTop = 192; $volH = 44;
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
<?php explainer('spolka', 'Jak złożyć zlecenie', [
    'wybierz KUP albo SPRZEDAJ', 'LIMIT = twoja cena, PKC = natychmiast',
    'podaj ilość', 'dodaj SL/TP jako ochronę', 'zatwierdź']); ?>
<div class="shead">
  <div class="idn"><div class="tk"><?= h($s['ticker']) ?></div><div class="nm"><?= h($s['name']) ?> · <?= h($s['sector']) ?></div>
    <div class="nm" style="margin-top:3px">Obrót sesji: <b class="mono" data-turnover><?= money_short($sessTurnover) ?> PLN</b>
      · <span class="liq <?= $liqCls ?>">●</span> <?= $liqTxt ?><?= tip('Płynność mówi, jak łatwo kupić/sprzedać bez ruszania kursu. Przy niskiej płynności widełki bid-ask są szersze, a PKC może zrealizować się po gorszej cenie.', 'plynnosc') ?></div>
  </div>
  <div class="price">
    <div class="p" data-px><?= money($s['price']) ?> <span style="font-size:15px;color:var(--faint)">PLN</span></div>
    <span class="chg <?= $chg >= 0 ? 'p' : 'n' ?>" data-chg><span class="ar"><?= $chg >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($chg), 2, ',', ' ') ?>%</span>
    <button class="star lg<?= $isWatched ? ' on' : '' ?>" data-watch="<?= $id ?>" title="Obserwuj: spółka na górze Rynku i w widżecie Pulpitu; z Pakietem Analityka dostaniesz alert 🔔 przy mocnym sygnale AT">★ <span><?= $isWatched ? 'Obserwujesz' : 'Obserwuj' ?></span></button>
  </div>
</div>

<div class="stock-grid">
  <div>
    <div class="panel" style="padding:8px">
      <div class="chartbar">
        <div class="cgrp" id="cg-range">
          <button data-range="d" class="on">1D</button>
          <button data-range="t">1T</button>
          <button data-range="m">1M</button>
          <button data-range="r">1R</button>
          <button data-range="max">MAX</button>
        </div>
        <div class="cgrp" id="cg-type">
          <button data-type="candles" class="on">Świece</button>
          <button data-type="line">Linia</button>
        </div>
        <div class="cgrp"><button id="cg-at" title="Nakładka analizy technicznej: średnie kroczące SMA 20 (niebieska) i SMA 50 (złota)">AT</button></div>
        <span class="muted" style="font-size:11px;margin-left:auto" id="cg-note">1D = bieżąca sesja · dłuższe zakresy = świece dzienne</span>
      </div>
      <div id="chartbox"><?= $chartSvg ?></div>
    </div>

    <div class="panel" style="margin-top:16px">
      <div class="subtabs">
        <button class="on" data-tab="book">Arkusz zleceń</button>
        <button data-tab="trades">Transakcje</button>
        <button data-tab="reports">Raporty</button>
        <button data-tab="news">Wiadomości</button>
        <button data-tab="ta">Analiza</button>
        <button data-tab="raport">Raport DM<?= $hasRaport ? '' : ' 🔒' ?></button>
        <button data-tab="forum">Dyskusja<?= $comments ? ' (' . count($comments) . ')' : '' ?></button>
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
        <?php $kindLbl = ['fundamental' => ['FUNDAMENTY', 'var(--accent)'], 'sentiment' => ['NASTROJE', 'var(--gold)'], 'technical' => ['TECHNIKA', 'var(--up)']];
        foreach ($news as $nw): $tc = $nw['type'] === 'POS' ? 'up' : ($nw['type'] === 'NEG' ? 'down' : 'soft');
            [$kl, $kc] = $kindLbl[$nw['kind'] ?? 'fundamental'] ?? $kindLbl['fundamental']; ?>
          <div style="padding:9px 2px;border-bottom:1px solid var(--line)">
            <?php if ($nw['is_espi']): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)">ESPI</span> <?php endif; ?>
            <span class="tag" style="color:<?= $kc ?>;border-color:<?= $kc ?>;font-size:10px"><?= $kl ?></span>
            <span class="<?= $tc ?>" style="font-weight:600"><?= h($nw['headline']) ?></span>
            <?php if ($nw['body']): ?><div class="soft" style="font-size:12.5px;margin-top:3px"><?= h($nw['body']) ?></div><?php endif; ?>
            <div class="muted" style="font-size:12px;margin-top:2px"><?= h(substr($nw['published_at'], 0, 16)) ?> · <?= h($nw['scope']) ?></div>
          </div>
        <?php endforeach; if (!$news) echo "<div class='muted' style='padding:8px'>brak wiadomości</div>"; ?>
      </div>

      <div class="tabpane" id="tab-ta">
        <h3 style="margin:4px 0 10px;font-size:14px;font-weight:700">Analiza techniczna
          <?= tip('10 klasycznych wskaźników liczonych z wykresu tej spółki. Każdy daje sygnał od -1 (sprzedaj) do +1 (kupuj), a sygnał zbiorczy to średnia ważona — wagi są RÓŻNE dla każdej spółki. Boty techniczne widzą dokładnie ten sam sygnał co Ty.', '') ?>
        </h3>
        <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:6px">
          <span class="chg <?= $taCls ?>" style="font-size:15px;padding:6px 14px"><?= h($taVerdict) ?></span>
          <span class="muted">sygnał zbiorczy: <b class="num <?= $taComp >= 0 ? 'up' : 'down' ?>"><?= ($taComp >= 0 ? '+' : '') . number_format($taComp, 2, ',', ' ') ?></b> (od −1 do +1)</span>
        </div>
        <div class="ta-gauge"><i style="left:<?= round(($taComp + 1) / 2 * 100, 1) ?>%"></i></div>
        <p class="muted" style="margin:8px 0 12px">Charakter spółki: <b style="color:var(--ink)"><?= h(Technical::character($taAff)) ?></b>
          <?= tip('Każda spółka ma DNA podatności na technikę. Techniczne mocniej reagują na sygnały AT (boty grają je odważniej), fundamentalne słuchają raportów i zysków.', '') ?></p>
        <table>
          <thead><tr><th>Wskaźnik</th><th>Typ</th><th class="num">Waga u tej spółki</th><th class="num">Sygnał</th><th class="num">Odczyt</th></tr></thead>
          <tbody>
          <?php foreach (Technical::CATALOG as $k => [$nm, $typ]): $v = $taSig[$k]; ?>
            <tr>
              <td><?= h($nm) ?></td>
              <td class="muted" style="font-size:12px"><?= $typ === 'trend' ? 'trend' : 'odwrócenie' ?></td>
              <td class="num muted">×<?= number_format($taW[$k], 2, ',', ' ') ?></td>
              <td class="num">
                <span class="ta-bar"><i class="<?= $v >= 0 ? 'p' : 'n' ?>" style="width:<?= round(abs($v) * 50, 1) ?>%;<?= $v >= 0 ? 'left:50%' : 'right:50%' ?>"></i></span>
              </td>
              <td class="num"><span class="chg <?= $v > 0.08 ? 'p' : ($v < -0.08 ? 'n' : '') ?>"><?= $v > 0.08 ? 'kupuj' : ($v < -0.08 ? 'sprzedaj' : 'neutralnie') ?> <?= ($v >= 0 ? '+' : '') . number_format($v, 2, ',', ' ') ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <p class="muted" style="margin:10px 0 0;font-size:12px">Wskaźniki liczone ze świec tickowych (bieżąca sesja i ostatnie ~2 godziny handlu). Młode spółki bez historii pokazują sygnały neutralne.</p>
      </div>

      <div class="tabpane" id="tab-raport">
        <?php if (!$hasRaport): ?>
          <div style="text-align:center;padding:26px 14px">
            <div style="font-size:34px">🔒</div>
            <h3 style="margin:8px 0 6px;font-size:15px">Raport Premium — pełna analiza spółki od DM GPW-gra</h3>
            <p class="muted" style="max-width:460px;margin:0 auto 14px">Wycena z premią/dyskontem do kursu, historia wyników i niespodzianek,
               polityka dywidendowa z realną stopą, profil ryzyka i charakter spółki. Wiedza, którą inni muszą zgadywać z wykresu.</p>
            <a class="btn sm" style="display:inline-block" href="sklep.php">Aktywuj Raport Premium — 🪙 <?= Tokens::PASSES['raport'][1] ?> / <?= Tokens::PASSES['raport'][0] ?> dni</a>
          </div>
        <?php else: ?>
          <?php
            $fair = (float) $s['pe_target'] * (float) $s['last_eps'];
            $prem = $fair > 0 ? ((float) $s['price'] / $fair - 1) * 100 : 0;
            $rvOld = count($reports) > 1 ? (float) end($reports)['revenue'] : 0; reset($reports);
            $rvNew = $reports ? (float) $reports[0]['revenue'] : 0;
            $surAvg = $reports ? array_sum(array_map(fn($r) => (float) $r['surprise_pct'], array_slice($reports, 0, 3))) / min(3, count($reports)) : 0;
            $dpsSum = array_sum(array_map(fn($r) => (float) $r['dividend'], $reports));
            $dpsYield = (float) $s['price'] > 0 && $reports ? $dpsSum / count($reports) * 12 / (float) $s['price'] * 100 : 0;
            $risk = fn(float $v, array $lbl) => $v >= 1.25 ? $lbl[2] : ($v >= 0.85 ? $lbl[1] : $lbl[0]);
          ?>
          <h3 style="margin:4px 0 10px;font-size:14px;font-weight:700">Raport analityczny DM GPW-gra <span class="tag" style="color:var(--gold);border-color:var(--gold-border)">PREMIUM</span></h3>
          <div class="ch-grid" style="margin-bottom:12px">
            <div class="ch-stat"><small>WYCENA GODZIWA (C/Z × EPS)</small><b><?= money($fair) ?> PLN</b></div>
            <div class="ch-stat"><small>KURS VS WYCENA</small><b class="<?= $prem <= 0 ? 'up' : 'down' ?>"><?= $prem >= 0 ? '+' : '' ?><?= number_format($prem, 1, ',', ' ') ?>%</b>
              <span class="muted" style="font-size:11px;display:block"><?= $prem <= -5 ? 'dyskonto — kurs poniżej wartości' : ($prem >= 5 ? 'premia — kurs powyżej wartości' : 'blisko wyceny') ?></span></div>
            <div class="ch-stat"><small>ŚR. NIESPODZIANKA (3 RAPORTY)</small><b class="<?= $surAvg >= 0 ? 'up' : 'down' ?>"><?= ($surAvg >= 0 ? '+' : '') . number_format($surAvg, 1, ',', ' ') ?>%</b>
              <span class="muted" style="font-size:11px;display:block"><?= $surAvg >= 2 ? 'spółka regularnie bije oczekiwania' : ($surAvg <= -2 ? 'spółka zawodzi oczekiwania' : 'wyniki zgodne z prognozami') ?></span></div>
            <div class="ch-stat"><small>STOPA DYWIDENDY (SZAC. ROCZNA)</small><b><?= $dpsYield > 0 ? number_format($dpsYield, 1, ',', ' ') . '%' : '—' ?></b>
              <span class="muted" style="font-size:11px;display:block"><?= (float) $s['dividend_payout'] > 0 ? 'wypłaca ' . number_format((float) $s['dividend_payout'] * 100, 0) . '% zysku' : 'reinwestuje zyski' ?></span></div>
          </div>
          <table style="margin-bottom:12px"><tbody>
            <tr><td class="muted">Profil ryzyka</td><td><b><?= $risk((float) $s['volatility'], ['spokojna', 'umiarkowana', 'rozchwiana']) ?></b>
                <span class="muted">(zmienność ×<?= number_format((float) $s['volatility'], 2, ',', ' ') ?>, beta ×<?= number_format((float) $s['beta'], 2, ',', ' ') ?>)</span></td></tr>
            <tr><td class="muted">Odporność finansowa</td><td><b><?= $risk((float) $s['financial_resilience'], ['krucha', 'przeciętna', 'twierdza']) ?></b>
                <span class="muted">— jak mocno złe wieści i kryzysy biją w wyniki</span></td></tr>
            <tr><td class="muted">Wrażliwość na newsy</td><td><b><?= $risk((float) $s['news_impact'], ['niska', 'średnia', 'wysoka']) ?></b>
                <span class="muted">— siła reakcji kursu na ESPI i plotki</span></td></tr>
            <tr><td class="muted">Charakter spółki</td><td><b><?= h(Technical::character($taAff)) ?></b>
                <span class="muted">— <?= $taAff >= 0.62 ? 'sygnały AT działają tu mocniej (boty techniczne grają odważniej)' : ($taAff <= 0.38 ? 'liczą się raporty i zyski, technika ma mały wpływ' : 'równowaga techniki i fundamentów') ?></span></td></tr>
            <tr><td class="muted">Trend przychodów</td><td><b class="<?= $rvNew >= $rvOld ? 'up' : 'down' ?>"><?= $rvOld > 0 ? (($rvNew >= $rvOld ? '+' : '') . number_format(($rvNew / $rvOld - 1) * 100, 1, ',', ' ') . '%') : '—' ?></b>
                <span class="muted">— zmiana od najstarszego z <?= count($reports) ?> ostatnich raportów</span></td></tr>
            <tr><td class="muted">Dywidendy (suma, <?= count($reports) ?> raportów)</td><td><b><?= $dpsSum > 0 ? money($dpsSum) . ' PLN/akcję' : 'brak wypłat' ?></b></td></tr>
          </tbody></table>
          <p class="muted" style="font-size:12px;margin:0">Raport liczony na żywo z danych spółki (te same, z których korzysta silnik gry). Werdykt DM z ceną docelową publikujemy osobno w Newsach — rotacyjnie ~5 spółek na sesję.</p>
        <?php endif; ?>
      </div>

      <div class="tabpane" id="tab-forum">
        <h3 style="margin:4px 0 10px;font-size:14px;font-weight:700">Dyskusja o <?= h($s['ticker']) ?>
          <?= tip('Forum spółki: opinie, pytania, plotki graczy. Nicki prowadzą do profili. Pamiętaj — wpisy innych to opinie, nie rekomendacje. GM moderuje.', '') ?>
        </h3>
        <form method="post" style="display:flex;gap:8px;margin-bottom:12px">
          <input name="comment" maxlength="300" placeholder="Co myślisz o <?= h($s['ticker']) ?>? (max 300 znaków)" style="flex:1" required>
          <button class="btn sm" style="width:auto">Dodaj wpis</button>
        </form>
        <?php foreach ($comments as $c):
            $col = preg_match('/^#[0-9a-f]{6}$/i', (string) $c['chat_color']) ? $c['chat_color'] : 'var(--accent)'; ?>
          <div style="padding:9px 2px;border-bottom:1px solid var(--line)">
            <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap">
              <?php if ($c['urole'] === 'player'): ?>
                <a href="gracz.php?id=<?= (int) $c['user_id'] ?>" style="font-weight:700;color:<?= h($col) ?>"><?= h($c['username']) ?></a>
              <?php else: ?><b style="color:var(--gold)"><?= h($c['username']) ?></b><?php endif; ?>
              <?php if (trim((string) $c['utitle']) !== ''): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border);font-size:10px"><?= h($c['utitle']) ?></span><?php endif; ?>
              <span class="muted" style="font-size:11px"><?= h(substr($c['created_at'], 5, 11)) ?></span>
              <?php if (($user['role'] ?? '') === 'admin'): ?>
                <form method="post" class="inline" style="margin-left:auto"><input type="hidden" name="del_comment" value="<?= (int) $c['id'] ?>"><button class="btn sm ghost" style="padding:2px 8px;font-size:11px">Ukryj</button></form>
              <?php endif; ?>
            </div>
            <div style="font-size:13.5px;margin-top:2px"><?= h($c['message']) ?></div>
          </div>
        <?php endforeach; if (!$comments) echo "<p class='muted' style='padding:12px 2px'>Jeszcze cicho — zacznij dyskusję o tej spółce jako pierwszy!</p>"; ?>
        <p class="muted" style="margin:10px 0 0;font-size:11.5px">Wpisy graczy to opinie, nie rekomendacje inwestycyjne. Forum trzyma 40 ostatnich wpisów.</p>
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

        <h2 style="margin-top:16px">🏛️ Najwięksi akcjonariusze<?= tip('Kto trzyma najwięcej akcji tej spółki. Gracze (ludzie) są podświetleni — możesz kliknąć i podejrzeć ich profil.', '') ?></h2>
        <table><thead><tr><th>#</th><th>Akcjonariusz</th><th class="num">Akcje</th><th class="num">Udział</th></tr></thead><tbody>
          <?php foreach ($holders as $i => $hd): $human = !(int) $hd['is_bot']; $linkable = $human && $hd['role'] === 'player'; ?>
            <tr<?= $linkable ? " class='rowlink' onclick=\"location='gracz.php?id=" . (int) $hd['uid'] . "'\"" : '' ?>>
              <td class="muted"><?= $i + 1 ?></td>
              <td><?= $human ? '<b style="color:var(--accent)">👤 ' . h($hd['username']) . '</b>' : '<span class="muted">🤖 ' . h($hd['username']) . '</span>' ?></td>
              <td class="num"><?= number_format($hd['n'], 0, ',', ' ') ?></td>
              <td class="num mono"><?= number_format($hd['n'] / $heldTotal * 100, 1, ',', ' ') ?>%</td>
            </tr>
          <?php endforeach; if (!$holders) echo "<tr><td class='muted' colspan=4>nikt nie trzyma akcji</td></tr>"; ?>
        </tbody></table>
      </div>
    </div>
  </div>

  <aside class="panel orderpanel">
    <button type="button" class="sheet-close" onclick="closeSheet()" title="Zamknij">✕</button>
    <h2>Zlecenie</h2>
    <?php if (!Engine::marketIsOpen() && !in_array($user['role'] ?? '', ['admin', 'qa'], true)): [, $mhO, $mhC] = Engine::marketHours(); ?>
      <p class="flash info" style="margin:0 0 10px">Giełda zamknięta — handel trwa <?= h($mhO) ?>–<?= h($mhC) ?>. Zlecenia złożysz po otwarciu.</p>
    <?php endif; ?>
    <form method="post" action="place_order.php">
      <input type="hidden" name="stock_id" value="<?= $id ?>">
      <input type="hidden" name="side" id="side" value="buy">
      <input type="hidden" name="type" id="type" value="limit">
      <?php $bestBid = $bids[0]['price'] ?? null; $bestAsk = $asks[0]['price'] ?? null; ?>
      <div class="seg">
        <button type="button" class="sell" id="tb-sell" <?= $owned <= 0 ? 'disabled title="Nie masz akcji tej spółki — najpierw kup"' : '' ?>><span class="lbl">SPRZEDAJ</span><span class="pr"><?= $bestBid !== null ? money($bestBid) : '—' ?></span></button>
        <button type="button" class="buy on" id="tb-buy"><span class="lbl">KUP</span><span class="pr"><?= $bestAsk !== null ? money($bestAsk) : '—' ?></span></button>
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
// gwiazdka obserwowania (nagłówek spółki)
document.querySelectorAll('[data-watch]').forEach(b => b.onclick = async () => {
  const fd = new FormData(); fd.append('stock_id', b.dataset.watch);
  try { const j = await (await fetch('api_watch.php', { method: 'POST', body: fd })).json();
    if (j.ok) { b.classList.toggle('on', j.on); const t = b.querySelector('span'); if (t) t.textContent = j.on ? 'Obserwujesz' : 'Obserwuj'; }
    else if (j.err) alert(j.err); } catch (e) {}
});
// zakładki (+ auto-otwarcie z ?tab=, np. po dodaniu wpisu na forum)
document.querySelectorAll('.subtabs button').forEach(b => b.onclick = () => {
  document.querySelectorAll('.subtabs button').forEach(x => x.classList.remove('on'));
  document.querySelectorAll('.tabpane').forEach(x => x.classList.remove('on'));
  b.classList.add('on'); document.getElementById('tab-' + b.dataset.tab).classList.add('on');
});
<?php if ($openTab !== ''): ?>document.querySelector('.subtabs button[data-tab=<?= h($openTab) ?>]')?.click();<?php endif; ?>
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
const chartNote=document.getElementById('cg-note');
let cRange='d', cType='candles', cAT=false;
try{ cAT=localStorage.getItem('chart_at')==='1'; }catch(e){}
function sma(cs,p){ const out=[]; let sum=0;
  for(let i=0;i<cs.length;i++){ sum+=cs[i].c; if(i>=p) sum-=cs[i-p].c; out.push(i>=p-1?sum/p:null); }
  return out; }
const RANGE_LBL={d:'1D — bieżąca sesja',t:'1T — tydzień',m:'1M — miesiąc',r:'1R — rok',max:'MAX — cała historia'};
async function drawChart(){ try{
  const j=await (await fetch('api_chart.php?id=<?= $id ?>&range='+cRange)).json();
  if(!j.ok) return;
  if(chartNote){
    let n=RANGE_LBL[cRange]||'';
    if(cRange!=='d'){ n+=' · świec dziennych: '+(j.days??j.candles.length);
      if(j.fallback||(j.days_total!==undefined&&j.days_total<8)) n+=' (świat gry jest młody — historia dopiero się buduje)'; }
    chartNote.textContent=n;
  }
  if(j.candles.length<2){
    chartBox.innerHTML='<div style="padding:40px;text-align:center;color:var(--faint)">Za mało danych dla tego zakresu — wróć za kilka sesji.</div>';
    return;
  }
  const W=680,H=240,pl=16,pr=16,pt=12,pb=54,volTop=192,volH=44,cs=j.candles,n=cs.length;
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
  if(cAT&&n>=8){   // nakładka AT: dwie średnie kroczące — okresy kurczą się przy krótkiej historii
    const p1=Math.min(20,Math.max(3,Math.floor(n/3))), p2=Math.min(50,Math.max(p1+2,Math.floor(2*n/3)));
    let drawn=[];
    for(const [p,col] of [[p1,'var(--accent)'],[p2,'var(--gold)']]){
      if(n<p+1) continue;
      const m=sma(cs,p), pts=[];
      for(let i=0;i<n;i++) if(m[i]!==null) pts.push((pl+i*slot+slot/2).toFixed(1)+','+yv(m[i]));
      if(pts.length>1){ s+='<polyline points="'+pts.join(' ')+'" fill="none" stroke="'+col+'" stroke-width="1.4" opacity="0.85" stroke-linejoin="round"/>'; drawn.push(p); }
    }
    if(drawn.length&&chartNote) chartNote.textContent+=' · AT: SMA '+drawn.join('/');
  }
  chartBox.innerHTML=s+'</svg>';
}catch(e){} }
const atBtn=document.getElementById('cg-at');
if(atBtn){ atBtn.classList.toggle('on',cAT);
  atBtn.onclick=()=>{ cAT=!cAT; atBtn.classList.toggle('on',cAT);
    try{ localStorage.setItem('chart_at',cAT?'1':'0'); }catch(e){} drawChart(); }; }
document.querySelectorAll('#cg-range button').forEach(b=>b.onclick=()=>{
  document.querySelectorAll('#cg-range button').forEach(x=>x.classList.remove('on'));
  b.classList.add('on'); cRange=b.dataset.range||'d'; drawChart(); });
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
<!-- mobilny pasek handlu (nad dolną nawigacją): ceny na żywo, klik ustawia stronę i zwija do formularza -->
<div class="tradebar">
  <button type="button" class="sell" onclick="setSide('sell');openSheet()" <?= $owned <= 0 ? 'disabled' : '' ?>>
    <span class="lbl">SPRZEDAJ</span><span class="pr"><?= $bestBid !== null ? money($bestBid) : '—' ?></span>
  </button>
  <button type="button" class="buy" onclick="setSide('buy');openSheet()">
    <span class="lbl">KUP</span><span class="pr"><?= $bestAsk !== null ? money($bestAsk) : '—' ?></span>
  </button>
</div>
<div class="sheet-backdrop" onclick="closeSheet()"></div>
<script>
document.body.classList.add('has-tradebar');
function openSheet(){ document.body.classList.add('sheet-open'); }
function closeSheet(){ document.body.classList.remove('sheet-open'); }
</script>
<?php layout_footer();
