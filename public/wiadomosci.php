<?php
/**
 * Newsy i ESPI (podzakładka modułu Rynek): wydarzenia, komunikaty, raporty.
 * Filtry: zasięg (rynek/branże/spółki/MOJE spółki) x klasa (fundamenty/nastroje/technika).
 * Rekomendacje mają własną podzakładkę (rekomendacje.php).
 */
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());

$scope = in_array($_GET['f'] ?? '', ['MARKET', 'SECTOR', 'COMPANY', 'moje'], true) ? $_GET['f'] : '';
$kindF = in_array($_GET['k'] ?? '', ['fundamental', 'sentiment', 'technical'], true) ? $_GET['k'] : '';
$cond = [];
if ($scope === 'moje') {
    // spółki z portfela (konta, którym gracz aktualnie handluje) + ich sektory
    $myStocks = array_map('intval', Engine::col("SELECT stock_id FROM wallets WHERE user_id=? AND (qty + qty_reserved) > 0", [(int) $user['id']]));
    $mySecs = $myStocks ? array_map('intval', Engine::col("SELECT DISTINCT sector_id FROM stocks WHERE id IN (" . implode(',', $myStocks) . ")")) : [];
    $cond[] = $myStocks
        ? "((n.scope='COMPANY' AND n.target_id IN (" . implode(',', $myStocks) . "))"
          . ($mySecs ? " OR (n.scope='SECTOR' AND n.target_id IN (" . implode(',', $mySecs) . "))" : '') . ")"
        : "1=0";
} elseif ($scope !== '') {
    $cond[] = "n.scope = " . Db::pdo()->quote($scope);
}
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
[$sessionNo] = Engine::sessionInfo();
$fUrl = fn(string $f, string $k) => 'wiadomosci.php?' . http_build_query(array_filter(['f' => $f, 'k' => $k]));

layout_header('Newsy i ESPI', $user, 'market');
?>
<div class="page-head"><h1>Rynek</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span></div>
<?php market_subnav('new'); ?>
<?php explainer('newsy', 'Jak czytać newsy', [
    'ESPI = jedna spółka', 'wydarzenia = sektor albo cały rynek',
    'dobre wieści podnoszą kurs, złe zbijają', 'reaguj przed botami']); ?>

<div class="stock-grid"><?php /* inline grid-template psuł mobile (wygrywał z media query) — klasa stackuje <820px */ ?>
  <div class="panel" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px 2px;display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach ([['', 'Wszystkie'], ['moje', '★ Moje spółki'], ['MARKET', 'Rynek'], ['SECTOR', 'Branże'], ['COMPANY', 'Spółki']] as [$f, $lbl]): ?>
        <a class="tag" style="padding:5px 12px;<?= $scope === $f ? 'color:var(--accent);border-color:var(--accent)' : '' ?>" href="<?= h($fUrl($f, $kindF)) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <div style="padding:6px 16px 8px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
      <span class="muted" style="font-size:11px">Klasa:</span>
      <?php foreach ([['', 'wszystkie'], ['fundamental', 'Fundamenty'], ['sentiment', 'Nastroje'], ['technical', 'Technika']] as [$k, $lbl]): ?>
        <a class="tag" style="padding:4px 10px;font-size:10.5px;<?= $kindF === $k ? 'color:var(--accent);border-color:var(--accent)' : '' ?>" href="<?= h($fUrl($scope, $k)) ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
      <?= tip('Fundamenty = twarde fakty (przesuwają wycenę; grają na nich boty fundamentalne). Nastroje = plotki i opinie (paliwo botów newsowych; efekt zwykle wygasa). Technika = komentarze z wykresu (czytają je fundusze algorytmiczne).', '') ?>
    </div>
    <?php if ($scope === 'moje' && empty($myStocks)): ?>
      <p class="muted" style="padding:16px">Nie masz jeszcze akcji — filtr „Moje spółki" pokaże komunikaty spółek z Twojego portfela i ich branż.</p>
    <?php endif; ?>
    <?php foreach ($news as $nw):
        $tc = $nw['type'] === 'POS' ? 'up' : ($nw['type'] === 'NEG' ? 'down' : 'soft');
        $where2 = $nw['scope'] === 'MARKET' ? 'CAŁY RYNEK' : ($nw['scope'] === 'SECTOR' ? ($nw['sector_name'] ?? 'sektor') : ($nw['ticker'] ?? 'spółka'));
        $link = $nw['scope'] === 'COMPANY' && $nw['stock_id2'] ? 'stock.php?id=' . (int) $nw['stock_id2'] : null;
    ?>
      <?= $link ? "<a href='$link' style='display:block'>" : '<div>' ?>
      <div class="news-it">
        <div class="news-meta">
          <?php if ($nw['is_espi']): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)">ESPI</span><?php endif; ?>
          <?= kind_chip($nw['kind'] ?? null) ?>
          <span class="tag"><?= h($where2) ?></span>
          <span class="news-t" style="margin:0 0 0 auto"><?= h(substr($nw['published_at'], 5, 11)) ?></span>
        </div>
        <div class="news-h <?= $tc ?>"><?= h($nw['headline']) ?></div>
        <?php if ($nw['body']): ?><div class="news-b"><?= h($nw['body']) ?></div><?php endif; ?>
        <?php if ((int) $nw['expire_tick'] > $tickNow && (float) $nw['impact_strength'] != 0): ?>
          <div class="news-t">wpływ na kurs jeszcze ~<?= (int) $nw['expire_tick'] - $tickNow ?> ticków</div>
        <?php endif; ?>
      </div>
      <?= $link ? '</a>' : '</div>' ?>
    <?php endforeach; if (!$news && $scope !== 'moje') echo "<p class='muted' style='padding:20px'>Cisza w eterze.</p>"; ?>
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
