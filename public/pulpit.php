<?php
/** Pulpit: kapitał -> moje spółki -> misje dnia -> skróty (ustalona kolejność wagi informacji). */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

[$sessionNo] = Engine::sessionInfo();
[$mhOn, $mhOpen, $mhClose] = Engine::marketHours();
$mhIsOpen = Engine::marketIsOpen();

// kapitał + wykres
$stockVal = (float) (Engine::one("SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0) FROM wallets w JOIN stocks s ON s.id=w.stock_id WHERE w.user_id=?", [$uid]) ?: 0);
$equity = (float) $user['cash'] + (float) $user['cash_reserved'] + $stockVal + Engine::lockedFunds($uid);
$startEq = (float) (Engine::one("SELECT start_equity FROM users WHERE id=?", [$uid]) ?: 0);
$ret = $startEq > 0 ? ($equity / $startEq - 1) * 100 : 0;
$eqSeries = array_reverse(array_map('floatval', Engine::col("SELECT equity FROM equity_history WHERE user_id=? ORDER BY t DESC LIMIT 150", [$uid])));
$eqSvg = equity_svg($eqSeries, 72);

// moje spółki: pozycje (wg wartości) + obserwowane
$pos = Engine::all("SELECT s.id, s.ticker, s.name, s.price, s.day_open_price, s.ta_signal, w.qty, w.qty_reserved, w.avg_price,
                    CASE WHEN s.day_open_price > 0 THEN (s.price / s.day_open_price - 1) * 100 ELSE 0 END AS chg
                    FROM wallets w JOIN stocks s ON s.id = w.stock_id
                    WHERE w.user_id=? AND (w.qty + w.qty_reserved) > 0
                    ORDER BY (w.qty + w.qty_reserved) * s.price DESC LIMIT 8", [$uid]);
$watchRows = Engine::all("SELECT s.id, s.ticker, s.name, s.price, s.ta_signal,
                          CASE WHEN s.day_open_price > 0 THEN (s.price / s.day_open_price - 1) * 100 ELSE 0 END AS chg
                          FROM watchlist w JOIN stocks s ON s.id = w.stock_id
                          WHERE w.user_id = ? AND s.id NOT IN (SELECT stock_id FROM wallets WHERE user_id=? AND (qty + qty_reserved) > 0)
                          ORDER BY s.ticker LIMIT 8", [$uid, $uid]);
$watchPremium = Tokens::hasPass($uid, 'analityk');

// pierwsze kroki (znikają, gdy wszystko odhaczone)
$steps = [
    ['done' => (bool) Engine::one("SELECT 1 FROM transactions WHERE buyer_id=? OR seller_id=? LIMIT 1", [$uid, $uid]),
     'txt' => 'Kup pierwsze akcje — wejdź na Rynek i kliknij spółkę', 'link' => 'market.php'],
    ['done' => (bool) Engine::one("SELECT 1 FROM orders WHERE user_id=? AND (sl_price IS NOT NULL OR tp_price IS NOT NULL) LIMIT 1", [$uid]),
     'txt' => 'Ustaw Stop-Loss lub Take-Profit — ochronę pozycji znajdziesz w Portfelu', 'link' => 'portfolio.php'],
    ['done' => (bool) Engine::one("SELECT 1 FROM challenge_players WHERE user_id=? LIMIT 1", [$uid]),
     'txt' => 'Zapisz się do wyzwania — konkurs z pulą nagród', 'link' => 'wyzwania.php'],
    ['done' => (bool) Engine::one("SELECT 1 FROM chat_messages WHERE user_id=? LIMIT 1", [$uid]),
     'txt' => 'Przywitaj się na czacie rynkowym', 'link' => 'market.php'],
];
$stepsDone = count(array_filter($steps, fn($s) => $s['done']));

// wyzwanie (najbliższe aktywne)
$ch = Challenges::activeAll()[0] ?? null;
$myEntryIds = array_map(fn($e) => (int) $e['challenge_id'], Challenges::entriesFor($uid));

// ruchy dnia
$movers = Engine::all("SELECT id, ticker, name, price, day_open_price,
                       CASE WHEN day_open_price > 0 THEN (price / day_open_price - 1) * 100 ELSE 0 END AS chg
                       FROM stocks WHERE day_open_price > 0 ORDER BY ABS(price / day_open_price - 1) DESC LIMIT 6");
usort($movers, fn($a, $b) => $b['chg'] <=> $a['chg']);

// codzienna pętla: seria + misje dnia (sprawdzenie misji wypłaca świeżo zaliczone)
$streak = Daily::streak($uid);
$missions = Daily::missions($uid);
$missionsDone = count(array_filter($missions, fn($m) => $m['done']));

// newsy i powiadomienia
$news = Engine::all("SELECT id, headline, type, published_at FROM news ORDER BY id DESC LIMIT 4");
$notifs = Engine::all("SELECT message, link, created_at, read_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 4", [$uid]);

// cel gry (osobisty próg gracza ma pierwszeństwo; zmiana w Portfelu)
$myGoal = Engine::one("SELECT goal_target FROM users WHERE id=?", [$uid]);
$goalTarget = ($myGoal !== false && $myGoal !== null) ? (float) $myGoal : (float) (Engine::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
$progress = $goalTarget > 0 ? min(100, $equity / $goalTarget * 100) : 0;

layout_header('Pulpit', $user, 'home');

/** wiersz spółki w "Moich spółkach": kurs, zmiana dnia, (premium) sygnał AT, (pozycje) wynik */
function stock_row(array $w, bool $premium, bool $withPl): void {
    ?>
    <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $w['id'] ?>'">
      <td class="tk" style="font-weight:700"><?= h($w['ticker']) ?> <span class="muted hide-m" style="font-weight:400;font-size:12px"><?= h($w['name']) ?></span></td>
      <td class="num mono"><?= money($w['price']) ?></td>
      <td class="num"><span class="chg <?= $w['chg'] >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $w['chg'] >= 0 ? '▲' : '▼' ?></span><?= number_format(abs((float) $w['chg']), 2, ',', ' ') ?>%</span></td>
      <?php if ($withPl): $q = (int) $w['qty'] + (int) $w['qty_reserved']; $pl = $q * ((float) $w['price'] - (float) $w['avg_price']); ?>
        <td class="num <?= $pl >= 0 ? 'up' : 'down' ?>"><?= ($pl >= 0 ? '+' : '') . money($pl) ?></td>
      <?php elseif ($premium): [$vTxt, $vCls] = Technical::verdict((float) $w['ta_signal']); ?>
        <td class="num"><span class="chg <?= $vCls ?>" style="font-size:11px"><?= h($vTxt) ?></span></td>
      <?php endif; ?>
    </tr>
    <?php
}
?>
<div class="page-head">
  <h1>Dzień dobry, <?= h($user['username']) ?></h1>
  <?= session_tag($sessionNo) ?>
  <?php if ($mhOn): ?>
    <span class="tag" style="<?= $mhIsOpen ? 'color:var(--up);border-color:var(--up)' : 'color:var(--faint)' ?>"><?= $mhIsOpen ? "rynek otwarty do $mhClose" : "rynek zamknięty · otwarcie $mhOpen" ?></span>
  <?php endif; ?>
</div>

<?php /* ---------- 1. KAPITAŁ ---------- */ ?>
<div class="stats">
  <div class="stat"><div class="k">Kapitał</div><div class="v"><?= money($equity) ?></div></div>
  <div class="stat"><div class="k">Wynik od startu</div><div class="v <?= $ret >= 0 ? 'up' : 'down' ?>"><?= ($ret >= 0 ? '+' : '') . number_format($ret, 1, ',', ' ') ?>%</div></div>
  <div class="stat"><div class="k">Wolna gotówka</div><div class="v"><?= money($user['cash']) ?></div></div>
  <div class="stat"><div class="k">Pozycje</div><div class="v"><?= count($pos) ?></div></div>
</div>
<?php if ($eqSvg): ?>
<div class="panel" style="margin-bottom:12px;padding:10px 14px 8px"><?= $eqSvg ?></div>
<?php endif; ?>
<?php if ($goalTarget > 0): ?>
<details class="goal-mini">
  <summary>🎯 Cel gry: <b><?= number_format($progress, 0, ',', ' ') ?>%</b> z <?= money_short($goalTarget) ?> PLN
    <span class="bar"><i style="width:<?= round(min(100, $progress), 1) ?>%"></i></span>
    <span class="muted" style="text-decoration:underline">szczegóły</span>
  </summary>
  <div class="panel" style="padding:10px 14px">
    <p class="muted" style="margin:0;font-size:12.5px">Kapitał <b><?= money($equity) ?> PLN</b> z celu <b><?= money($goalTarget) ?> PLN</b>.
      Własny próg ustawisz w <a href="portfolio.php">Portfelu</a> (sekcja Cel gry).</p>
  </div>
</details>
<?php endif; ?>

<?php /* dashboard: panele układają się w kolumny (masonry) na szerokim ekranie */ ?>
<div class="dash">

<?php /* onboarding tuż po kapitale — znika po odhaczeniu wszystkiego */ ?>
<?php if ($stepsDone < count($steps)): ?>
<section class="panel" style="margin-bottom:16px">
  <h2>Pierwsze kroki (<?= $stepsDone ?>/<?= count($steps) ?>)</h2>
  <?php foreach ($steps as $s): ?>
    <div class="check<?= $s['done'] ? ' done' : '' ?>">
      <span class="cbx"><?= $s['done'] ? '✓' : '' ?></span>
      <?php if ($s['done']): ?><span><?= h($s['txt']) ?></span>
      <?php else: ?><a href="<?= h($s['link']) ?>"><?= h($s['txt']) ?></a><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <p class="muted" style="margin:10px 0 0">Nie wiesz, od czego zacząć? <a href="samouczek.php">Przejdź samouczek</a> — 3 minuty i wszystko jasne.</p>
</section>
<?php endif; ?>

<?php /* ---------- 2. MOJE SPÓŁKI: pozycje + obserwowane ---------- */ ?>
<section class="panel" style="margin-bottom:16px">
  <h2>Moje spółki</h2>
  <?php if (!$pos && !$watchRows): ?>
    <p class="muted">Jeszcze pusto: kup akcje na <a href="market.php"><b>Rynku</b></a> albo oznacz spółki gwiazdką ★, żeby je tu widzieć.</p>
  <?php endif; ?>
  <?php if ($pos): ?>
    <p class="muted" style="margin:2px 0 4px;font-size:11.5px">W portfelu · wynik po bieżącym kursie</p>
    <table><tbody><?php foreach ($pos as $w) stock_row($w, $watchPremium, true); ?></tbody></table>
    <p style="margin:6px 0 0;font-size:12.5px"><a href="portfolio.php">Pełny portfel →</a></p>
  <?php endif; ?>
  <?php if ($watchRows): ?>
    <p class="muted" style="margin:<?= $pos ? '14px' : '2px' ?> 0 4px;font-size:11.5px">★ Obserwowane<?= $watchPremium ? ' · sygnał AT' : ' — sygnały AT i alerty 🔔 z Pakietem Analityka' ?></p>
    <table><tbody><?php foreach ($watchRows as $w) stock_row($w, $watchPremium, false); ?></tbody></table>
  <?php endif; ?>
</section>

<?php /* ---------- 3. MISJE DNIA ---------- */ ?>
<section class="panel" style="margin-bottom:16px">
  <h2>Misje dnia (<?= $missionsDone ?>/<?= count($missions) ?>)
    <span class="tag" style="margin-left:8px;color:var(--gold);border-color:var(--gold-border)">🔥 seria: <?= $streak ?> <?= $streak === 1 ? 'dzień' : 'dni' ?></span>
    <?= tip('Codziennie trzy wspólne dla wszystkich misje — za każdą tokeny. Wejście do gry podbija serię: +1 token dziennie i bonus +3 co 7. dzień z rzędu. Przerwa zeruje serię.', '') ?>
  </h2>
  <?php foreach ($missions as $m): ?>
    <div class="check<?= $m['done'] ? ' done' : '' ?>">
      <span class="cbx"><?= $m['done'] ? '✓' : '' ?></span>
      <span style="flex:1"><?= h($m['desc']) ?></span>
      <b style="color:var(--gold);font-size:12.5px;white-space:nowrap">🪙 <?= (int) $m['tokens'] ?></b>
    </div>
  <?php endforeach; ?>
  <p class="muted" style="margin:8px 0 0;font-size:12px">Nagrody wpadają same, gdy misja jest zaliczona. Jutro trzy nowe — te same dla wszystkich graczy.</p>
</section>

<?php /* ---------- 4. SKRÓTY + puls gry ---------- */ ?>
<section class="panel" style="margin-bottom:16px">
  <h2>Skróty</h2>
  <div class="shortcuts">
    <a href="market.php"><?= icon('chart') ?><span>Notowania</span></a>
    <a href="branze.php"><?= icon('chart') ?><span>Branże</span></a>
    <a href="rekomendacje.php"><?= icon('case') ?><span>Rekomendacje</span></a>
    <a href="wiadomosci.php?f=moje"><?= icon('news') ?><span>Newsy moich spółek</span></a>
    <a href="wyzwania.php"><?= icon('flag') ?><span>Wyzwania</span></a>
    <a href="ranking.php"><?= icon('trophy') ?><span>Ranking</span></a>
    <a href="sklep.php"><?= icon('shop') ?><span>Tokeny</span></a>
    <a href="dziennik.php"><?= icon('book') ?><span>Dziennik</span></a>
    <a href="samouczek.php"><?= icon('help') ?><span>Samouczek</span></a>
  </div>
</section>

  <section class="panel">
    <h2>Ruchy dnia</h2>
    <table>
      <tbody>
      <?php foreach ($movers as $m): ?>
        <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $m['id'] ?>'">
          <td class="tk" style="font-weight:700"><?= h($m['ticker']) ?> <span class="muted" style="font-weight:400;font-size:12px"><?= h($m['name']) ?></span></td>
          <td class="num mono"><?= money($m['price']) ?></td>
          <td class="num"><span class="chg <?= $m['chg'] >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $m['chg'] >= 0 ? '▲' : '▼' ?></span><?= number_format(abs((float) $m['chg']), 2, ',', ' ') ?>%</span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p style="margin:10px 0 0"><a href="market.php">Cały rynek →</a></p>
  </section>

  <section class="panel">
    <?php if ($ch): $iAmIn = in_array((int) $ch['id'], $myEntryIds, true); ?>
      <h2><?= h($ch['name']) ?></h2>
      <?php if ($ch['status'] === 'signup'): ?>
        <p class="muted" style="margin:6px 0">Zapisy trwają — start: sesja #<?= (int) $ch['start_session'] ?> · buy-in <?= money($ch['buyin']) ?> PLN · pula <b class="up"><?= money($ch['pot']) ?> PLN</b></p>
        <p><a class="btn sm" href="wyzwania.php"><?= $iAmIn ? 'Jesteś zapisany — zobacz szczegóły' : 'Zapisz się →' ?></a></p>
      <?php else: ?>
        <?php $board = Challenges::leaderboard((int) $ch['id']); $myPos = 0;
              foreach ($board as $i => $b) if ((int) $b['user_id'] === $uid) { $myPos = $i + 1; break; } ?>
        <p class="muted" style="margin:6px 0">Trwa do sesji #<?= (int) $ch['end_session'] ?> · pula <b class="up"><?= money($ch['pot']) ?> PLN</b>
          <?= $myPos ? " · Twoje miejsce: <b>$myPos/" . count($board) . '</b>' : '' ?></p>
        <p><a class="btn sm ghost" href="wyzwania.php">Tabela wyników →</a></p>
      <?php endif; ?>
    <?php else: ?>
      <h2>Wyzwania</h2>
      <p class="muted">Brak otwartej edycji — nowa wystartuje automatycznie, dostaniesz powiadomienie.</p>
    <?php endif; ?>

    <h2 style="margin-top:18px">Ostatnie powiadomienia</h2>
    <?php foreach ($notifs as $n): ?>
      <p style="margin:7px 0;font-size:13.5px<?= $n['read_at'] === null ? ';font-weight:600' : '' ?>">
        <?php if ($n['link']): ?><a href="<?= h($n['link']) ?>" style="color:inherit"><?= h(mb_substr($n['message'], 0, 110)) ?></a><?php else: ?><?= h(mb_substr($n['message'], 0, 110)) ?><?php endif; ?>
      </p>
    <?php endforeach; if (!$notifs) echo "<p class='muted'>Cisza — powiadomienia pojawią się przy pierwszych ruchach.</p>"; ?>
    <p style="margin:8px 0 0"><a href="powiadomienia.php">Wszystkie →</a> · <a href="dziennik.php">Dziennik</a></p>
  </section>

<section class="panel" style="margin-top:16px">
  <h2>Z ostatniej chwili</h2>
  <?php foreach ($news as $nw): ?>
    <p style="margin:7px 0"><span class="chg <?= $nw['type'] === 'POS' ? 'p' : ($nw['type'] === 'NEG' ? 'n' : '') ?>" style="font-size:10px"><?= h($nw['type']) ?></span>
      <?= h($nw['headline']) ?> <span class="muted mono" style="font-size:11px"><?= h(substr($nw['published_at'], 11, 5)) ?></span></p>
  <?php endforeach; ?>
  <p style="margin:8px 0 0"><a href="wiadomosci.php">Wszystkie newsy →</a></p>
</section>

</div><?php /* .dash */ ?>
<?php layout_footer();