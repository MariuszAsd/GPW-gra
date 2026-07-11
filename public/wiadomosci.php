<?php
/** Wiadomości świata (chronologicznie: wydarzenia, ESPI, raporty) + kalendarz raportów. */
require __DIR__ . '/_boot.php';
$user = require_login();

$scope = in_array($_GET['f'] ?? '', ['MARKET', 'SECTOR', 'COMPANY'], true) ? $_GET['f'] : '';
$kindF = in_array($_GET['k'] ?? '', ['fundamental', 'sentiment', 'technical'], true) ? $_GET['k'] : '';
$cond = [];
if ($scope !== '') $cond[] = "n.scope = " . Db::pdo()->quote($scope);
if ($kindF !== '') $cond[] = "n.kind = " . Db::pdo()->quote($kindF);
$where = $cond ? 'WHERE ' . implode(' AND ', $cond) : '';
$news = Engine::all("SELECT n.*, sec.name AS sector_name, st.ticker, st.id AS stock_id2
                     FROM news n
                     LEFT JOIN sectors sec ON n.scope='SECTOR' AND sec.id = n.target_id
                     LEFT JOIN stocks st  ON n.scope='COMPANY' AND st.id = n.target_id
                     $where ORDER BY n.id DESC LIMIT 80");

/** Chip klasy informacji: kto na nią reaguje (odczyt: fundamenty=inwestorzy, nastroje=spekulanci, technika=algorytmy). */
function kind_chip(?string $k): string {
    return match ($k ?? 'fundamental') {
        'sentiment' => "<span class='tag' style='color:var(--gold);border-color:var(--gold-border)' title='Miękka informacja: plotki, opinie, nastroje. Gorące paliwo dla graczy newsowych — po wygaśnięciu kurs ciąży z powrotem do wyceny.'>NASTROJE</span>",
        'technical' => "<span class='tag' style='color:var(--up);border-color:var(--up-border)' title='Komentarz pisany z danych wykresu. Nie zmienia wartości spółki, ale czytają go fundusze algorytmiczne — bywa samospełniający.'>TECHNIKA</span>",
        default     => "<span class='tag' style='color:var(--accent);border-color:var(--accent)' title='Twarde fakty o pieniądzach: kontrakty, prognozy, kary, wyniki. Przesuwają wycenę fundamentalną — na nich grają inwestorzy wartościowi.'>FUNDAMENTY</span>",
    };
}

$tickNow = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
$calendar = Engine::all("SELECT id, ticker, name, next_report_tick, dividend_payout FROM stocks ORDER BY next_report_tick ASC LIMIT 12");

layout_header('Wiadomości', $user, 'news');
?>
<?php [$recoRows, $recoHidden, $recoPremium, $recoSession] = Recommendations::visibleFor((int) $user['id'], 10); ?>
<?php if ($recoRows || $recoHidden): ?>
<section class="panel" style="margin-bottom:16px">
  <h2>Rekomendacje DM GPW-gra
    <?= tip('Analitycy domu maklerskiego wydają rekomendacje z ceną docelową na otwarciu każdej sesji. Klienci z Pakietem Analityka widzą je NATYCHMIAST — pozostali dzień później. Rekomendacja to wskazówka, nie gwarancja.', '') ?>
  </h2>
  <?php if (!$recoPremium && $recoHidden > 0): ?>
    <?php $odm = $recoHidden === 1 ? 'nową rekomendację'
        : (($recoHidden % 10 >= 2 && $recoHidden % 10 <= 4 && ($recoHidden % 100 < 12 || $recoHidden % 100 > 14)) ? 'nowe rekomendacje' : 'nowych rekomendacji'); ?>
    <p class="flash info" style="margin:0 0 12px">Dziś rano analitycy wydali <b><?= $recoHidden ?></b> <?= $odm ?> —
      dostępne od razu dla klientów premium. <a href="sklep.php"><b>Aktywuj Pakiet Analityka →</b></a>
      <span class="muted">(Ty zobaczysz je jutro)</span></p>
  <?php endif; ?>
  <table>
    <thead><tr><th>Sesja</th><th>Spółka</th><th>Werdykt</th><th class="num">Cena docelowa</th><th class="num">Kurs</th><th class="num">Potencjał</th></tr></thead>
    <tbody>
    <?php foreach ($recoRows as $r): $up = (float) $r['price'] > 0 ? ((float) $r['target_price'] / (float) $r['price'] - 1) * 100 : 0; ?>
      <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $r['stock_id'] ?>'">
        <td class="muted">#<?= (int) $r['session'] ?><?= (int) $r['session'] === $recoSession ? ' <span class="chg p" style="font-size:10px">DZIŚ</span>' : '' ?></td>
        <td><b><?= h($r['ticker']) ?></b> <span class="muted" style="font-size:12px"><?= h($r['name']) ?></span></td>
        <td><span class="chg <?= $r['verdict'] === 'kupuj' ? 'p' : ($r['verdict'] === 'sprzedaj' ? 'n' : '') ?>"><?= h($r['verdict']) ?></span></td>
        <td class="num"><?= money($r['target_price']) ?></td>
        <td class="num muted"><?= money($r['price']) ?></td>
        <td class="num"><span class="<?= $up >= 0 ? 'up' : 'down' ?>"><?= ($up >= 0 ? '+' : '') . number_format($up, 1, ',', ' ') ?>%</span></td>
      </tr>
    <?php endforeach; if (!$recoRows) echo "<tr><td colspan=6 class='muted' style='padding:16px'>Pierwsze rekomendacje pojawią się na otwarciu najbliższej sesji.</td></tr>"; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php explainer('newsy', 'Jak czytać newsy', [
    'ESPI = jedna spółka', 'wydarzenia = sektor albo cały rynek',
    'dobre wieści podnoszą kurs, złe zbijają', 'reaguj przed botami']); ?>
<div class="page-head"><h1>Wiadomości</h1><span class="muted">wydarzenia świata, komunikaty ESPI i raporty — najnowsze u góry</span></div>

<div class="stock-grid" style="grid-template-columns:1.7fr 1fr">
  <div class="panel" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px 2px;display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach ([['', 'Wszystkie'], ['MARKET', 'Rynek'], ['SECTOR', 'Branże'], ['COMPANY', 'Spółki']] as [$f, $lbl]): ?>
        <a class="tag" style="padding:5px 12px;<?= $scope === $f ? 'color:var(--accent);border-color:var(--accent)' : '' ?>" href="wiadomosci.php?<?= http_build_query(array_filter(['f' => $f, 'k' => $kindF])) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <div style="padding:6px 16px 8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <span class="muted" style="font-size:11px">Klasa:</span>
      <?php foreach ([['', 'wszystkie'], ['fundamental', 'Fundamenty'], ['sentiment', 'Nastroje'], ['technical', 'Technika']] as [$k, $lbl]): ?>
        <a class="tag" style="padding:4px 10px;font-size:10.5px;<?= $kindF === $k ? 'color:var(--accent);border-color:var(--accent)' : '' ?>" href="wiadomosci.php?<?= http_build_query(array_filter(['f' => $scope, 'k' => $k])) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
      <?= tip('Fundamenty = twarde fakty (przesuwają wycenę; grają na nich boty fundamentalne). Nastroje = plotki i opinie (paliwo botów newsowych; efekt zwykle wygasa). Technika = komentarze z wykresu (czytają je fundusze algorytmiczne).', '') ?>
    </div>
    <?php foreach ($news as $nw):
        $tc = $nw['type'] === 'POS' ? 'up' : ($nw['type'] === 'NEG' ? 'down' : 'soft');
        $where2 = $nw['scope'] === 'MARKET' ? 'CAŁY RYNEK' : ($nw['scope'] === 'SECTOR' ? ($nw['sector_name'] ?? 'sektor') : ($nw['ticker'] ?? 'spółka'));
        $link = $nw['scope'] === 'COMPANY' && $nw['stock_id2'] ? 'stock.php?id=' . (int) $nw['stock_id2'] : null;
    ?>
      <?= $link ? "<a href='$link' style='display:block'>" : '<div>' ?>
      <div style="padding:11px 16px;border-bottom:1px solid var(--line)">
        <div style="display:flex;gap:8px;align-items:baseline;flex-wrap:wrap">
          <?php if ($nw['is_espi']): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)">ESPI</span><?php endif; ?>
          <?= kind_chip($nw['kind'] ?? null) ?>
          <span class="tag"><?= h($where2) ?></span>
          <span class="<?= $tc ?>" style="font-weight:600;font-size:14px"><?= h($nw['headline']) ?></span>
        </div>
        <?php if ($nw['body']): ?><div class="soft" style="font-size:12.5px;margin-top:3px"><?= h($nw['body']) ?></div><?php endif; ?>
        <div class="muted mono" style="font-size:11px;margin-top:3px"><?= h(substr($nw['published_at'], 5, 11)) ?><?php if ((int) $nw['expire_tick'] > $tickNow && (float) $nw['impact_strength'] != 0): ?> · wpływ jeszcze ~<?= (int) $nw['expire_tick'] - $tickNow ?> ticków<?php endif; ?></div>
      </div>
      <?= $link ? '</a>' : '</div>' ?>
    <?php endforeach; if (!$news) echo "<p class='muted' style='padding:20px'>Cisza w eterze.</p>"; ?>
  </div>

  <aside class="panel">
    <h2>📅 Kalendarz raportów<?= tip('Raport miesięczny potrafi mocno ruszyć kursem (niespodzianka w wynikach) i to z nim wypłacana jest dywidenda. Warto zająć pozycję przed raportem spółki, w którą wierzysz.', 'dywidenda') ?></h2>
    <table>
      <thead><tr><th>Spółka</th><th class="num">Raport za</th><th class="num">Dyw.</th></tr></thead>
      <tbody>
      <?php foreach ($calendar as $c): $in = max(0, (int) $c['next_report_tick'] - $tickNow); ?>
        <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $c['id'] ?>'">
          <td><div class="sym"><span class="tk"><?= h($c['ticker']) ?></span></div></td>
          <td class="num mono"><?= $in === 0 ? 'lada tick' : "~$in t. (≈" . ($in < 60 ? "$in min" : round($in / 60, 1) . " h") . ')' ?></td>
          <td class="num"><?= (float) $c['dividend_payout'] > 0 ? '<span class="up">tak</span>' : '<span class="muted">—</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="muted" style="font-size:11.5px;margin-top:10px">1 tick ≈ 1 minuta. „Dyw." — czy spółka wypłaca dywidendę przy raporcie.</p>
  </aside>
</div>
<?php layout_footer();
