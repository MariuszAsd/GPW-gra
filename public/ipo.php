<?php
/**
 * IPO (podzakładka modułu Rynek): otwarta oferta publiczna z zapisami po cenie
 * emisyjnej + historia debiutów (redukcja, kurs dziś, wynik od emisji).
 * Zapisy z KONTA GŁÓWNEGO (portfel wyzwania nie bierze udziału w ofertach).
 */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];
[$sessionNo] = Engine::sessionInfo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subscribe'])) {
    [$ok, $msg] = Ipo::subscribe($uid, (int) $_POST['subscribe'], (int) ($_POST['qty'] ?? 0));
    flash($msg, $ok ? 'ok' : 'err');
    redirect('ipo.php');
}

$offer = Engine::row("SELECT o.*, sec.name AS sector_name FROM ipo_offers o JOIN sectors sec ON sec.id=o.sector_id WHERE o.status='open' ORDER BY o.id DESC LIMIT 1");
$mySub = $offer ? Engine::row("SELECT * FROM ipo_subs WHERE offer_id=? AND user_id=?", [(int) $offer['id'], $uid]) : null;
$subCount = $offer ? (int) Engine::one("SELECT COUNT(*) FROM ipo_subs WHERE offer_id=?", [(int) $offer['id']]) : 0;
$history = Engine::all("SELECT o.*, s.price AS cur_price, s.id AS sid
                        FROM ipo_offers o LEFT JOIN stocks s ON s.id = o.stock_id
                        WHERE o.status='done' ORDER BY o.id DESC LIMIT 12");
$cash = (float) Engine::one("SELECT cash FROM users WHERE id=?", [$uid]);

layout_header('IPO', $user, 'market');
?>
<div class="page-head"><h1>Rynek</h1><span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span></div>
<?php market_subnav('ipo'); ?>
<?php explainer('ipo', 'Jak działa oferta publiczna', [
    'zapisujesz się po cenie emisyjnej', 'przy nadsubskrypcji REDUKCJA (nadpłata wraca)',
    'przydział na otwarciu sesji rozliczenia', 'gorący popyt = zwykle gorący debiut']); ?>

<h2 style="margin:0 0 10px;font-size:15px">Oferty publiczne (IPO)</h2>

<?php if ($offer): $closeS = (int) $offer['close_session']; $maxQ = (int) $offer['per_player_max'];
      $canSub = $sessionNo < $closeS && ($user['role'] ?? '') === 'player'; ?>
  <section class="ch-hero" style="margin-bottom:16px">
    <span class="ch-hero-k">Zapisy otwarte · przydział w sesji #<?= $closeS ?></span>
    <h2>📢 <?= h($offer['name']) ?> (<?= h($offer['ticker']) ?>)</h2>
    <p>Sektor: <b><?= h($offer['sector_name']) ?></b> · cena emisyjna <b><?= money($offer['price']) ?> PLN</b> ·
       pula <b><?= number_format((int) $offer['shares_offered'], 0, ',', ' ') ?> akcji</b>
       (wartość <?= money_short((float) $offer['price'] * (int) $offer['shares_offered']) ?> PLN) ·
       limit na gracza: <b><?= number_format($maxQ, 0, ',', ' ') ?> szt.</b> · zapisanych graczy: <b><?= $subCount ?></b></p>
    <?php if ($mySub): ?>
      <p style="margin-top:10px"><b>✅ Twój zapis: <?= (int) $mySub['qty'] ?> akcji za <?= money($mySub['paid']) ?> PLN.</b>
        Przydział (z ewentualną redukcją) na otwarciu sesji #<?= $closeS ?> — dostaniesz powiadomienie.
        Kwota zapisu liczy się do Twojego kapitału.</p>
    <?php elseif ($canSub): ?>
      <form method="post" onsubmit="return confirm('Zapis na IPO jest wiążący do przydziału. Kontynuować?')">
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
          <input type="number" name="qty" min="1" max="<?= $maxQ ?>" value="<?= max(1, min($maxQ, (int) floor($cash / max(0.01, (float) $offer['price'])))) ?>"
                 style="width:110px;color:var(--ink)" required>
          <span style="opacity:.9">akcji × <?= money($offer['price']) ?> PLN</span>
          <button class="btn" name="subscribe" value="<?= (int) $offer['id'] ?>">Zapisuję się</button>
        </div>
        <small>Gotówka schodzi przy zapisie (masz <?= money($cash) ?> PLN wolne). Przy nadsubskrypcji przydział jest redukowany, a nadpłata wraca co do grosza.</small>
      </form>
    <?php elseif ($sessionNo >= $closeS): ?>
      <p style="margin-top:10px">Okno zapisów zamknięte — trwa rozliczenie przydziału.</p>
    <?php endif; ?>
  </section>
<?php else: ?>
  <section class="panel" style="margin-bottom:16px">
    <h2>Brak otwartej oferty</h2>
    <p class="muted">Kolejna spółka ogłosi ofertę publiczną automatycznie — dostaniesz powiadomienie 🔔, gdy ruszą zapisy.
      Rytm debiutów ustawia rynek (i GM).</p>
  </section>
<?php endif; ?>

<section class="panel" style="margin-bottom:16px">
  <h2>Jak czytać ofertę? <?= tip('Cena emisyjna jest stała — zapisujesz się na liczbę akcji, gotówka schodzi od razu i przez okno zapisów liczy się do Twojego kapitału. O debiucie decyduje łączny popyt: gracze + instytucje (fundusze w grze). Popyt ponad pulę = redukcja proporcjonalna i zwykle gorący debiut; słaby popyt = pełny przydział, ale chłodne otwarcie.', '') ?></h2>
  <div class="ch-steps">
    <div class="ch-step"><i>1</i><b>📝 Zapis</b><span>wybierasz liczbę akcji po cenie emisyjnej — gotówka schodzi od razu</span></div>
    <div class="ch-step"><i>2</i><b>⏳ Okno zapisów</b><span>~2 sesje; zapisują się też instytucje (fundusze w grze)</span></div>
    <div class="ch-step"><i>3</i><b>✂️ Redukcja</b><span>popyt &gt; pula → każdy dostaje proporcjonalnie mniej, nadpłata wraca</span></div>
    <div class="ch-step"><i>4</i><b>🔔 Debiut</b><span>akcje w portfelu po cenie emisyjnej; duży popyt = zwykle gorące otwarcie</span></div>
  </div>
</section>

<section class="panel">
  <h2>Historia debiutów <span class="muted" style="text-transform:none;letter-spacing:0;font-size:12px">· redukcja i wynik od ceny emisyjnej</span></h2>
  <?php if (!$history): ?><p class="muted">Pierwsza oferta z zapisami jeszcze przed nami — historia zbuduje się z czasem.</p><?php else: ?>
  <div class="tbl-scroll"><table>
    <thead><tr><th>Spółka</th><th class="num">Cena emisyjna</th><th class="num hide-m">Redukcja</th><th class="num">Kurs dziś</th><th class="num">Od emisji</th></tr></thead>
    <tbody>
    <?php foreach ($history as $hRow): $chg = $hRow['cur_price'] !== null && (float) $hRow['price'] > 0
              ? ((float) $hRow['cur_price'] / (float) $hRow['price'] - 1) * 100 : null; ?>
      <tr <?= $hRow['sid'] ? "class=\"rowlink\" onclick=\"location='stock.php?id=" . (int) $hRow['sid'] . "'\"" : '' ?>>
        <td><b><?= h($hRow['ticker']) ?></b> <span class="muted hide-m" style="font-size:12px"><?= h($hRow['name']) ?></span></td>
        <td class="num mono"><?= money($hRow['price']) ?></td>
        <td class="num hide-m"><?= (float) $hRow['reduction_pct'] > 0 ? '<b>' . number_format((float) $hRow['reduction_pct'], 1, ',', ' ') . '%</b>' : '<span class="muted">—</span>' ?></td>
        <td class="num mono"><?= $hRow['cur_price'] !== null ? money($hRow['cur_price']) : '—' ?></td>
        <td class="num"><?php if ($chg !== null): ?><span class="chg <?= $chg >= 0 ? 'p' : 'n' ?>"><?= ($chg >= 0 ? '+' : '') . number_format($chg, 1, ',', ' ') ?>%</span><?php else: ?>—<?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="muted" style="font-size:11.5px;margin-top:8px">Wysoka redukcja = popyt mocno przekroczył pulę — takie debiuty bywają najgorętsze. Starsze debiuty (sprzed systemu zapisów) nie mają wpisu w historii.</p>
  <?php endif; ?>
</section>
<?php layout_footer();
