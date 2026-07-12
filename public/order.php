<?php
/** Szczegóły zlecenia: co, kiedy i dlaczego się stało (oś czasu: złożenie, realizacje, SL/TP, anulowanie). */
require __DIR__ . '/_boot.php';
$user = require_login();

$oid = (int) ($_GET['id'] ?? 0);
$o = Engine::row("SELECT o.*, s.ticker, s.name, s.price AS cur_price FROM orders o JOIN stocks s ON s.id=o.stock_id WHERE o.id=?", [$oid]);
// dostęp: właściciel zlecenia, admin — albo właściciel subkonta wyzwania, z którego zlecenie poszło
$mine = [(int) $user['id']];
foreach (Engine::col("SELECT shadow_user_id FROM challenge_players WHERE user_id=? AND shadow_user_id IS NOT NULL", [$user['id']]) as $sid) $mine[] = (int) $sid;
if (!$o || (!in_array((int) $o['user_id'], $mine, true) && ($user['role'] ?? '') !== 'admin')) {
    flash('Nie znaleziono takiego zlecenia.', 'err');
    redirect('portfolio.php');
}

$isStop = $o['sl_price'] !== null || $o['tp_price'] !== null;
$fills = Engine::all("SELECT t.*, CASE WHEN t.buy_order_id=? THEN 'buy' ELSE 'sell' END AS my_side
                      FROM transactions t WHERE t.buy_order_id=? OR t.sell_order_id=? ORDER BY t.id", [$oid, $oid, $oid]);
$logs = Engine::all("SELECT * FROM logs WHERE context LIKE ? ORDER BY id", ['%"order_id":' . $oid . '%']);

// oś czasu: złożenie -> fille -> log SL/TP/anulowań -> stan końcowy
$events = [];
$typLbl = $isStop ? 'OBRONNE (SL/TP)' : ($o['side'] === 'buy' ? 'KUPNO z limitem' : 'SPRZEDAŻ z limitem');
$events[] = ['t' => $o['created_at'], 'cls' => '', 'm' => "Złożono zlecenie: $typLbl",
    'd' => $isStop
        ? 'pakiet ' . (int) $o['qty_init'] . ' szt. · ' . ($o['sl_price'] !== null ? 'SL ' . money($o['sl_price']) . ' PLN' : '') . ($o['sl_price'] !== null && $o['tp_price'] !== null ? ' · ' : '') . ($o['tp_price'] !== null ? 'TP ' . money($o['tp_price']) . ' PLN' : '')
        : (int) $o['qty_init'] . ' szt. po ' . money($o['price']) . ' PLN' . ($o['expires_session'] !== null ? ' · ważne do końca sesji #' . (int) $o['expires_session'] : ' · bezterminowe')];

foreach ($fills as $f) {
    $v = $f['qty'] * $f['price'];
    $events[] = ['t' => $f['created_at'], 'cls' => 'ok',
        'm' => 'Transakcja: ' . ($f['my_side'] === 'buy' ? 'kupiono' : 'sprzedano') . ' ' . (int) $f['qty'] . ' szt. po ' . money($f['price']) . ' PLN',
        'd' => 'wartość ' . money($v) . ' PLN' . ($f['my_side'] === 'sell' ? ' · prowizja ' . money(round($v * Engine::feeRate(), 2)) . ' PLN' : '')];
}

$slFills = [];
foreach ($logs as $lg) {
    $ctx = json_decode((string) $lg['context'], true) ?: [];
    if (in_array($lg['event'], ['stops.sl', 'stops.tp'], true)) {
        $events[] = ['t' => $lg['ts'], 'cls' => 'warn', 'm' => '⚡ ' . ($lg['event'] === 'stops.sl' ? 'Wyzwolił się Stop-Loss' : 'Wyzwolił się Take-Profit'), 'd' => $lg['message']];
        if (!empty($ctx['tx_from'])) {
            $slFills = Engine::all("SELECT * FROM transactions WHERE id > ? AND id <= ? AND seller_id = ? AND stock_id = ? ORDER BY id",
                [(int) $ctx['tx_from'], (int) ($ctx['tx_to'] ?? $ctx['tx_from']), (int) $o['user_id'], (int) $o['stock_id']]);
            foreach ($slFills as $f) {
                $v = $f['qty'] * $f['price'];
                $events[] = ['t' => $f['created_at'], 'cls' => 'ok', 'm' => 'Sprzedaż obronna: ' . (int) $f['qty'] . ' szt. po ' . money($f['price']) . ' PLN',
                    'd' => 'wartość ' . money($v) . ' PLN · prowizja ' . money(round($v * Engine::feeRate(), 2)) . ' PLN'];
            }
        }
    } elseif (in_array($lg['event'], ['order.place', 'order.stop', 'order.cancel'], true)) {
        // złożenie/anulowanie pokazujemy z danych zlecenia — pomijamy duplikaty
    } else {
        $events[] = ['t' => $lg['ts'], 'cls' => $lg['level'] === 'error' ? 'bad' : '', 'm' => $lg['message'] ?: $lg['event'], 'd' => ''];
    }
}

$stMap = ['active' => ['aktywne — czeka w arkuszu', ''], 'pending' => ['obronne — czeka na wyzwolenie', 'warn'],
          'filled' => ['zrealizowane w całości', 'ok'], 'cancelled' => ['anulowane', ''],
          'expired' => ['wygasło z końcem sesji (zwrot rezerwacji)', ''], 'triggered' => ['wyzwolone — pakiet sprzedany zleceniem obronnym', 'warn']];
[$stTxt, $stCls] = $stMap[$o['status']] ?? [$o['status'], ''];
if (in_array($o['status'], ['cancelled', 'expired'], true)) {
    $done = (int) $o['qty_init'] - (int) $o['qty'];
    $events[] = ['t' => '', 'cls' => '', 'm' => $o['status'] === 'cancelled' ? 'Zlecenie anulowane' : 'Zlecenie wygasło (koniec sesji)',
        'd' => $done > 0 ? "wcześniej zrealizowano $done z {$o['qty_init']} szt." : 'nic się nie zrealizowało — całość rezerwacji wróciła'];
}

usort($events, fn($a, $b) => strcmp($a['t'], $b['t']));

layout_header('Zlecenie #' . $oid, $user, 'portfolio');
$sum = 0; $qsum = 0;
foreach ($fills as $f) { $sum += $f['qty'] * $f['price']; $qsum += $f['qty']; }
foreach ($slFills as $f) { $sum += $f['qty'] * $f['price']; $qsum += $f['qty']; }
?>
<div class="page-head">
  <h1>Zlecenie #<?= $oid ?></h1>
  <a class="tk" href="stock.php?id=<?= (int) $o['stock_id'] ?>" style="font-weight:800"><?= h($o['ticker']) ?></a>
  <span class="muted"><?= h($o['name']) ?></span>
  <a class="btn sm ghost" style="margin-left:auto" href="portfolio.php">← Portfel</a>
</div>

<div class="stats" style="grid-template-columns:repeat(4,1fr)">
  <div class="stat"><div class="k">Typ</div><div class="v" style="font-size:16px"><?= $isStop ? '🛡️ OBRONNE' : ($o['side'] === 'buy' ? 'KUPNO' : 'SPRZEDAŻ') ?><?= tip($isStop ? 'Zlecenie obronne pilnuje kursu i samo sprzedaje pakiet po przekroczeniu progu SL lub TP.' : 'Zlecenie z limitem ceny — realizuje się po podanej cenie albo lepszej.', $isStop ? 'sl' : 'limit') ?></div></div>
  <div class="stat"><div class="k">Status</div><div class="v <?= $stCls === 'ok' ? 'up' : '' ?>" style="font-size:16px"><?= h($stTxt) ?></div></div>
  <div class="stat"><div class="k">Zrealizowano</div><div class="v" style="font-size:16px"><?= $qsum ?> / <?= (int) $o['qty_init'] ?> szt.</div></div>
  <div class="stat"><div class="k"><?= $qsum > 0 ? 'Śr. cena / wartość' : 'Kurs teraz' ?></div>
    <div class="v" style="font-size:16px"><?= $qsum > 0 ? money($sum / $qsum) . ' / ' . money($sum) . ' PLN' : money($o['cur_price']) . ' PLN' ?></div></div>
</div>

<div class="panel">
  <h2>Oś czasu — co się działo</h2>
  <div class="timeline">
    <?php foreach ($events as $e): ?>
      <div class="tl-item <?= h($e['cls']) ?>">
        <?php if ($e['t']): ?><div class="t"><?= h($e['t']) ?></div><?php endif; ?>
        <div class="m"><?= h($e['m']) ?></div>
        <?php if ($e['d']): ?><div class="d"><?= h($e['d']) ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (in_array($o['status'], ['active', 'pending'], true)): ?>
      <div class="tl-item"><div class="m muted">… zlecenie wciąż <?= $o['status'] === 'pending' ? 'pilnuje kursu' : 'czeka w arkuszu' ?></div>
        <div style="display:flex;gap:8px;align-items:flex-start;margin-top:6px;flex-wrap:wrap">
          <?= order_edit_form($o, 'order.php?id=' . $oid) ?>
          <form method="post" action="cancel_order.php"><input type="hidden" name="order_id" value="<?= $oid ?>"><button class="btn sm ghost">Anuluj zlecenie</button></form>
        </div>
        <?php if ($o['status'] === 'active' && !$isStop): ?><p class="muted" style="margin:6px 0 0;font-size:11.5px">Zmiana ceny lub ilości ustawia zlecenie od nowa w arkuszu (traci priorytet czasu). Rezerwacja koryguje się automatycznie.</p><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_footer();
