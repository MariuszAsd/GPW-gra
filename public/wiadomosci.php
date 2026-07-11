<?php
/** Wiadomości świata (chronologicznie: wydarzenia, ESPI, raporty) + kalendarz raportów. */
require __DIR__ . '/_boot.php';
$user = require_login();

$scope = in_array($_GET['f'] ?? '', ['MARKET', 'SECTOR', 'COMPANY'], true) ? $_GET['f'] : '';
$where = $scope !== '' ? "WHERE n.scope = " . Db::pdo()->quote($scope) : '';
$news = Engine::all("SELECT n.*, sec.name AS sector_name, st.ticker, st.id AS stock_id2
                     FROM news n
                     LEFT JOIN sectors sec ON n.scope='SECTOR' AND sec.id = n.target_id
                     LEFT JOIN stocks st  ON n.scope='COMPANY' AND st.id = n.target_id
                     $where ORDER BY n.id DESC LIMIT 80");

$tickNow = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
$calendar = Engine::all("SELECT id, ticker, name, next_report_tick, dividend_payout FROM stocks ORDER BY next_report_tick ASC LIMIT 12");

layout_header('Wiadomości', $user, 'news');
?>
<div class="page-head"><h1>Wiadomości</h1><span class="muted">wydarzenia świata, komunikaty ESPI i raporty — najnowsze u góry</span></div>

<div class="stock-grid" style="grid-template-columns:1.7fr 1fr">
  <div class="panel" style="padding:0;overflow:hidden">
    <div style="padding:12px 16px 8px;display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach ([['', 'Wszystkie'], ['MARKET', 'Rynek'], ['SECTOR', 'Branże'], ['COMPANY', 'Spółki']] as [$f, $lbl]): ?>
        <a class="tag" style="padding:5px 12px;<?= $scope === $f ? 'color:var(--accent);border-color:var(--accent)' : '' ?>" href="wiadomosci.php<?= $f ? "?f=$f" : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
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
