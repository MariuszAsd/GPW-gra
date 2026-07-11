<?php
/** Dziennik gracza: pełna oś czasu konta — zlecenia, transakcje, SL/TP, dywidendy, wyzwania, odznaki. */
require __DIR__ . '/_boot.php';
$user = require_login();

// własny dziennik; admin może obejrzeć dziennik dowolnego gracza (?id=)
$pid = (int) ($_GET['id'] ?? $user['id']);
$isAdmin = ($user['role'] ?? '') === 'admin';
if ($pid !== (int) $user['id'] && !$isAdmin) { flash('Dziennik gracza jest prywatny.', 'err'); redirect('dziennik.php'); }
$p = Engine::row("SELECT id, username, role, is_bot FROM users WHERE id=?", [$pid]);
if (!$p || (int) $p['is_bot'] === 1) { flash('Nie ma takiego gracza.', 'err'); redirect('dziennik.php'); }

$typy = [
    ''            => 'Wszystko',
    'order'       => '📝 Zlecenia',
    'trade'       => '🤝 Transakcje',
    'stop'        => '🛡️ SL/TP',
    'dividend'    => '💰 Dywidendy',
    'challenge'   => '⚔️ Wyzwania',
    'achievement' => '🎖️ Odznaki',
];
$typ = array_key_exists($_GET['typ'] ?? '', $typy) ? ($_GET['typ'] ?? '') : '';

// strumień 1: dziennik (wpisy silnika + akcje własne)
$jw = "user_id = ?"; $ja = [$pid];
if ($typ !== '' && $typ !== 'trade') { $jw .= " AND type = ?"; $ja[] = $typ; }
$journal = ($typ === 'trade') ? [] : Engine::all("SELECT ts, tick, type, message, link FROM player_journal WHERE $jw ORDER BY id DESC LIMIT 300", $ja);

// strumień 2: transakcje (kupno/sprzedaż z arkusza) — również subkont wyzwań tego gracza
$trades = [];
if ($typ === '' || $typ === 'trade') {
    $ids = [$pid];
    foreach (Engine::col("SELECT shadow_user_id FROM challenge_players WHERE user_id=? AND shadow_user_id IS NOT NULL", [$pid]) as $sh) $ids[] = (int) $sh;
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (Engine::all("SELECT t.created_at ts, t.qty, t.price, t.buyer_id, t.seller_id, s.ticker, s.id AS sid
                          FROM transactions t JOIN stocks s ON s.id = t.stock_id
                          WHERE t.buyer_id IN ($ph) OR t.seller_id IN ($ph)
                          ORDER BY t.id DESC LIMIT 300", array_merge($ids, $ids)) as $t) {
        $buy = in_array((int) $t['buyer_id'], $ids, true);
        $shadow = ($buy ? (int) $t['buyer_id'] : (int) $t['seller_id']) !== $pid;
        $trades[] = ['ts' => $t['ts'], 'tick' => null, 'type' => 'trade',
            'message' => ($shadow ? '⚔️ ' : '') . ($buy ? '🟢 Kupiono' : '🔴 Sprzedano') . ' ' . (int) $t['qty'] . ' szt. ' . $t['ticker']
                       . ' @ ' . number_format((float) $t['price'], 2, ',', ' ') . ' PLN'
                       . ' (razem ' . number_format((int) $t['qty'] * (float) $t['price'], 2, ',', ' ') . ')',
            'link' => 'stock.php?id=' . (int) $t['sid']];
    }
}

// scal i posortuj malejąco po czasie
$items = array_merge($journal, $trades);
usort($items, fn($a, $b) => strcmp($b['ts'], $a['ts']));
$items = array_slice($items, 0, 200);

$ikona = ['order' => '📝', 'trade' => '🤝', 'stop' => '🛡️', 'dividend' => '💰', 'challenge' => '⚔️',
          'achievement' => '🎖️', 'ipo' => '📈', 'goal' => '🏆', 'event' => '📢', 'report' => '📊', 'token' => '🪙', 'system' => 'ℹ️'];

layout_header('Dziennik', $user, 'portfolio');
?>
<div class="page-head">
  <h1>Dziennik<?= $pid !== (int) $user['id'] ? ': ' . h($p['username']) . ' <span class="tag">podgląd GM</span>' : '' ?></h1>
  <span class="muted">pełna historia konta — co się stało i kiedy (czas serwera)</span>
  <a class="btn sm ghost" style="margin-left:auto" href="portfolio.php">← Portfel</a>
</div>

<div class="panel" style="margin-bottom:14px">
  <?php foreach ($typy as $k => $lbl): ?>
    <a class="btn sm <?= $typ === $k ? '' : 'ghost' ?>" style="margin:2px" href="dziennik.php?typ=<?= h($k) ?><?= $pid !== (int) $user['id'] ? '&id=' . $pid : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <div class="tbl-scroll">
    <table>
      <thead><tr><th style="width:150px">Kiedy</th><th style="width:40px"></th><th>Zdarzenie</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <?php $ic = $ikona[$it['type']] ?? 'ℹ️'; $msg = $it['message'];
              if (str_starts_with($msg, $ic)) $msg = ltrim(mb_substr($msg, mb_strlen($ic)));   // nie dubluj ikony z treścią ?>
        <tr<?= $it['link'] ? " class='rowlink' onclick=\"location='" . h($it['link']) . "'\"" : '' ?>>
          <td class="mono muted" style="white-space:nowrap"><?= h($it['ts']) ?></td>
          <td><?= $ic ?></td>
          <td><?= h($msg) ?></td>
        </tr>
      <?php endforeach; if (!$items) echo "<tr><td class='muted' colspan=3 style='padding:22px'>Brak zdarzeń — dziennik zapisuje się od teraz przy każdej akcji na koncie.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="muted" style="margin-top:10px">Dziennik trzyma ~1000 ostatnich wpisów + 300 ostatnich transakcji. Szczegóły każdego zlecenia (oś czasu realizacji) znajdziesz klikając wpis.</p>
<?php layout_footer();