<?php
/**
 * Rekomendacje: reko DM GPW-gra (premium widzi dzisiejsze od razu, reszta
 * z opóźnieniem 1 sesji) + skaner sygnałów technicznych podany jak rekomendacje.
 * Podzakładka modułu Rynek (market_subnav).
 */
require __DIR__ . '/_boot.php';
$user = require_login();
$uidReal = (int) ($user['owner_id'] ?? $user['id']);
[$sessionNo] = Engine::sessionInfo();

[$recoRows, $recoHidden, $recoPremium, $recoSession] = Recommendations::visibleFor($uidReal, 25);

// skaner AT: najsilniejsze sygnały zbiorcze (funkcja Pakietu Analityka — jak skaner na Rynku)
$taRows = [];
if ($recoPremium) {
    $taRows = Engine::all("SELECT id, ticker, name, price, ta_signal FROM stocks
                           WHERE ABS(ta_signal) >= 0.25 ORDER BY ABS(ta_signal) DESC LIMIT 12");
}

layout_header('Rekomendacje', $user, 'market');
?>
<div class="page-head"><h1>Rynek</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span></div>
<?php market_subnav('rek'); ?>
<?php explainer('reko', 'Jak czytać rekomendacje', [
    'analitycy wyceniają spółkę', 'kupuj = kurs poniżej wyceny',
    'premium widzi je DZIEŃ wcześniej', 'wskazówka, nie wyrocznia']); ?>

<section class="panel" style="margin-bottom:16px">
  <h2>Rekomendacje DM GPW-gra
    <?= tip('Analitycy domu maklerskiego wydają rekomendacje z ceną docelową na otwarciu każdej sesji. Klienci z Pakietem Analityka widzą je NATYCHMIAST — pozostali dzień później. Rekomendacja to wskazówka, nie gwarancja.', '') ?>
    <?php if ($recoPremium): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)">PREMIUM — widzisz dzisiejsze</span><?php endif; ?>
  </h2>
  <?php if (!$recoPremium && $recoHidden > 0): ?>
    <?php $odm = $recoHidden === 1 ? 'nową rekomendację'
        : (($recoHidden % 10 >= 2 && $recoHidden % 10 <= 4 && ($recoHidden % 100 < 12 || $recoHidden % 100 > 14)) ? 'nowe rekomendacje' : 'nowych rekomendacji'); ?>
    <p class="flash info" style="margin:0 0 12px">Dziś rano analitycy wydali <b><?= $recoHidden ?></b> <?= $odm ?> —
      dostępne od razu dla klientów premium. <a href="sklep.php"><b>Aktywuj Pakiet Analityka →</b></a>
      <span class="muted">(Ty zobaczysz je jutro)</span></p>
  <?php endif; ?>
  <div class="tbl-scroll"><table>
    <thead><tr><th>Sesja</th><th>Spółka</th><th>Werdykt</th><th class="num">Cena docelowa</th><th class="num hide-m">Kurs</th><th class="num">Potencjał</th></tr></thead>
    <tbody>
    <?php foreach ($recoRows as $r): $up = (float) $r['price'] > 0 ? ((float) $r['target_price'] / (float) $r['price'] - 1) * 100 : 0; ?>
      <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $r['stock_id'] ?>'">
        <td class="muted">#<?= (int) $r['session'] ?><?= (int) $r['session'] === $recoSession ? ' <span class="chg p" style="font-size:10px">DZIŚ</span>' : '' ?></td>
        <td><b><?= h($r['ticker']) ?></b> <span class="muted hide-m" style="font-size:12px"><?= h($r['name']) ?></span></td>
        <td><span class="chg <?= $r['verdict'] === 'kupuj' ? 'p' : ($r['verdict'] === 'sprzedaj' ? 'n' : '') ?>"><?= h($r['verdict']) ?></span></td>
        <td class="num"><?= money($r['target_price']) ?></td>
        <td class="num muted hide-m"><?= money($r['price']) ?></td>
        <td class="num"><span class="<?= $up >= 0 ? 'up' : 'down' ?>"><?= ($up >= 0 ? '+' : '') . number_format($up, 1, ',', ' ') ?>%</span></td>
      </tr>
    <?php endforeach; if (!$recoRows) echo "<tr><td colspan=6 class='muted' style='padding:16px'>Pierwsze rekomendacje pojawią się na otwarciu najbliższej sesji.</td></tr>"; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <h2>Skaner sygnałów technicznych
    <?= tip('Zbiorczy sygnał 10 wskaźników AT (ten sam co w zakładce Analiza na karcie spółki) — tu zebrany dla całego rynku i podany jak rekomendacje. Sygnały techniczne bywają samospełniające: czytają je też fundusze algorytmiczne w grze.', '') ?>
    <?php if (!$recoPremium): ?><span class="tag">🔒 Pakiet Analityka</span><?php endif; ?>
  </h2>
  <?php if (!$recoPremium): ?>
    <p class="flash info" style="margin:0">Skaner przeszukuje wszystkie spółki i pokazuje najsilniejsze sygnały kupna/sprzedaży
      — to funkcja <b>Pakietu Analityka</b>. <a href="sklep.php"><b>Aktywuj w Sklepie →</b></a>
      <span class="muted">Pojedynczą spółkę zawsze sprawdzisz za darmo: karta spółki → zakładka Analiza.</span></p>
  <?php elseif (!$taRows): ?>
    <p class="muted">Rynek bez wyraźnych sygnałów — żadna spółka nie przekracza progu |0,25|. Zajrzyj po następnej sesji.</p>
  <?php else: ?>
    <div class="tbl-scroll"><table>
      <thead><tr><th>Spółka</th><th>Sygnał AT</th><th class="num hide-m">Siła</th><th class="num">Kurs</th></tr></thead>
      <tbody>
      <?php foreach ($taRows as $t): [$vTxt, $vCls] = Technical::verdict((float) $t['ta_signal']); ?>
        <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $t['id'] ?>&tab=ta'">
          <td><b><?= h($t['ticker']) ?></b> <span class="muted hide-m" style="font-size:12px"><?= h($t['name']) ?></span></td>
          <td><span class="chg <?= $vCls ?>"><?= h($vTxt) ?></span></td>
          <td class="num mono hide-m"><?= number_format((float) $t['ta_signal'], 2, ',', ' ') ?></td>
          <td class="num"><?= money($t['price']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  <?php endif; ?>
</section>
<?php layout_footer();
