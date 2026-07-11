<?php
/** Pulpit: pierwsza strona po zalogowaniu — co się dzieje i co warto teraz zrobić. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

[$sessionNo] = Engine::sessionInfo();
[$mhOn, $mhOpen, $mhClose] = Engine::marketHours();
$mhIsOpen = Engine::marketIsOpen();

// kapitał
$stockVal = (float) (Engine::one("SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0) FROM wallets w JOIN stocks s ON s.id=w.stock_id WHERE w.user_id=?", [$uid]) ?: 0);
$equity = (float) $user['cash'] + (float) $user['cash_reserved'] + $stockVal;
$startEq = (float) (Engine::one("SELECT start_equity FROM users WHERE id=?", [$uid]) ?: 0);
$ret = $startEq > 0 ? ($equity / $startEq - 1) * 100 : 0;
$posCount = (int) Engine::one("SELECT COUNT(*) FROM wallets WHERE user_id=? AND (qty + qty_reserved) > 0", [$uid]);

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

// newsy i powiadomienia
$news = Engine::all("SELECT id, headline, type, published_at FROM news ORDER BY id DESC LIMIT 4");
$notifs = Engine::all("SELECT message, link, created_at, read_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 4", [$uid]);

// cel gry
$goalTarget = (float) (Engine::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
$progress = $goalTarget > 0 ? min(100, $equity / $goalTarget * 100) : 0;

layout_header('Pulpit', $user, 'home');
?>
<div class="page-head">
  <h1>Dzień dobry, <?= h($user['username']) ?> 👋</h1>
  <span class="tag" style="color:var(--accent);border-color:var(--accent)">Sesja #<?= $sessionNo ?></span>
  <?php if ($mhOn): ?>
    <span class="tag" style="<?= $mhIsOpen ? 'color:var(--up);border-color:var(--up)' : 'color:var(--faint)' ?>"><?= $mhIsOpen ? "🔔 rynek otwarty do $mhClose" : "🌙 rynek zamknięty · otwarcie $mhOpen" ?></span>
  <?php endif; ?>
  <a class="btn sm ghost" style="margin-left:auto" href="samouczek.php">🎓 Samouczek</a>
</div>

<div class="stats">
  <div class="stat"><div class="k">Kapitał</div><div class="v"><?= money($equity) ?></div></div>
  <div class="stat"><div class="k">Wynik od startu</div><div class="v <?= $ret >= 0 ? 'up' : 'down' ?>"><?= ($ret >= 0 ? '+' : '') . number_format($ret, 1, ',', ' ') ?>%</div></div>
  <div class="stat"><div class="k">Wolna gotówka</div><div class="v"><?= money($user['cash']) ?></div></div>
  <div class="stat"><div class="k">Pozycje</div><div class="v"><?= $posCount ?></div></div>
</div>

<?php if ($goalTarget > 0): ?>
<div class="panel" style="margin-bottom:16px;padding:12px 16px">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
    <b>🏆 Cel gry: <?= money_short($goalTarget) ?> PLN</b>
    <span class="muted mono"><?= number_format($progress, 1, ',', ' ') ?>%</span>
  </div>
  <div style="background:var(--bg3);border-radius:20px;height:8px;margin-top:8px;overflow:hidden">
    <div style="background:var(--accent);height:100%;width:<?= max(1, $progress) ?>%"></div>
  </div>
</div>
<?php endif; ?>

<?php if ($stepsDone < count($steps)): ?>
<section class="panel" style="margin-bottom:16px">
  <h2>🚀 Pierwsze kroki (<?= $stepsDone ?>/<?= count($steps) ?>)</h2>
  <?php foreach ($steps as $s): ?>
    <p style="margin:8px 0"><?= $s['done'] ? '✅' : '⬜' ?>
      <?php if ($s['done']): ?><span class="muted" style="text-decoration:line-through"><?= h($s['txt']) ?></span>
      <?php else: ?><a href="<?= h($s['link']) ?>"><?= h($s['txt']) ?></a> →<?php endif; ?>
    </p>
  <?php endforeach; ?>
  <p class="muted" style="margin:10px 0 0">Nie wiesz, od czego zacząć? <a href="samouczek.php">🎓 Przejdź samouczek</a> — 3 minuty i wszystko jasne.</p>
</section>
<?php endif; ?>

<div class="gm-grid">
  <section class="panel">
    <h2>📈 Ruchy dnia</h2>
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
      <h2>⚔️ <?= h($ch['name']) ?></h2>
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
      <h2>⚔️ Wyzwania</h2>
      <p class="muted">Brak otwartej edycji — nowa wystartuje automatycznie, dostaniesz powiadomienie 🔔.</p>
    <?php endif; ?>

    <h2 style="margin-top:18px">🔔 Ostatnie powiadomienia</h2>
    <?php foreach ($notifs as $n): ?>
      <p style="margin:7px 0;font-size:13.5px<?= $n['read_at'] === null ? ';font-weight:600' : '' ?>">
        <?php if ($n['link']): ?><a href="<?= h($n['link']) ?>" style="color:inherit"><?= h(mb_substr($n['message'], 0, 110)) ?></a><?php else: ?><?= h(mb_substr($n['message'], 0, 110)) ?><?php endif; ?>
      </p>
    <?php endforeach; if (!$notifs) echo "<p class='muted'>Cisza — powiadomienia pojawią się przy pierwszych ruchach.</p>"; ?>
    <p style="margin:8px 0 0"><a href="powiadomienia.php">Wszystkie →</a> · <a href="dziennik.php">📜 Dziennik</a></p>
  </section>
</div>

<section class="panel" style="margin-top:16px">
  <h2>📰 Z ostatniej chwili</h2>
  <?php foreach ($news as $nw): ?>
    <p style="margin:7px 0"><span class="chg <?= $nw['type'] === 'POS' ? 'p' : ($nw['type'] === 'NEG' ? 'n' : '') ?>" style="font-size:10px"><?= h($nw['type']) ?></span>
      <?= h($nw['headline']) ?> <span class="muted mono" style="font-size:11px"><?= h(substr($nw['published_at'], 11, 5)) ?></span></p>
  <?php endforeach; ?>
  <p style="margin:8px 0 0"><a href="wiadomosci.php">Wszystkie newsy →</a></p>
</section>
<?php layout_footer();