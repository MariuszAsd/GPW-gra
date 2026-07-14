<?php
/**
 * Historia konta — dzienny przepływ gotówki: ile i na co WPŁYNĘŁO / WYPŁYNĘŁO.
 * Czytane wprost z trwałych tabel (retro, także sprzed wdrożenia tej strony):
 *   - transactions  -> kupno (−) / sprzedaż netto po prowizji (+)
 *   - ipo_subs      -> zapis IPO (−) / zwrot z redukcji (+)
 *   - deposits      -> lokata (−) / wypłata z odsetkami lub zwrot kapitału (+)
 *   - cash_ledger   -> to, czego nie widać w powyższych (dywidendy) — od wdrożenia
 * Grupowane po dniu (data serwera). Konto główne gracza (bez subkont wyzwań).
 */
require __DIR__ . '/_boot.php';
$user = require_login();

$pid = (int) ($_GET['id'] ?? $user['id']);
$isAdmin = ($user['role'] ?? '') === 'admin';
if ($pid !== (int) $user['id'] && !$isAdmin) { flash('Historia konta jest prywatna.', 'err'); redirect('historia.php'); }
$p = Engine::row("SELECT id, username, role, is_bot, cash, cash_reserved FROM users WHERE id=?", [$pid]);
if (!$p || (int) $p['is_bot'] === 1) { flash('Nie ma takiego gracza.', 'err'); redirect('historia.php'); }

$KAT = [
    ''            => 'Wszystko',
    'kupno'       => '🟢 Kupno akcji',
    'sprzedaz'    => '🔴 Sprzedaż akcji',
    'ipo'         => '📈 IPO (zapis/zwrot)',
    'lokata'      => '🏦 Lokaty',
    'dywidenda'   => '💰 Dywidendy',
];
$fil = array_key_exists($_GET['f'] ?? '', $KAT) ? ($_GET['f'] ?? '') : '';

// mapa sesja -> data (do datowania zdarzeń rozliczanych „na sesji": zwrot IPO, wypłata lokaty)
$sesDaty = [];
foreach (Engine::all("SELECT session, trade_date FROM session_dates") as $r) $sesDaty[(int) $r['session']] = $r['trade_date'];
$dateOfSession = function (?int $s, string $fallback) use ($sesDaty): string {
    if ($s !== null && isset($sesDaty[$s])) return $sesDaty[$s] . ' 12:00:00';
    return $fallback;
};

$feeRate = Engine::feeRate();
$LIMIT = 600;                 // ostatnie N zdarzeń każdego rodzaju
$mov = [];                    // [ts, amount(±), cat, label, note, link]
$add = function (string $ts, float $amount, string $cat, string $label, string $note, string $link = '') use (&$mov) {
    $mov[] = ['ts' => $ts, 'amount' => round($amount, 2), 'cat' => $cat, 'label' => $label, 'note' => $note, 'link' => $link];
};

// 1) KUPNO / SPRZEDAŻ z arkusza (konto główne gracza)
foreach (Engine::all("SELECT t.created_at ts, t.qty, t.price, t.buyer_id, t.seller_id, s.ticker, s.id sid
                      FROM transactions t JOIN stocks s ON s.id = t.stock_id
                      WHERE t.buyer_id = ? OR t.seller_id = ?
                      ORDER BY t.id DESC LIMIT $LIMIT", [$pid, $pid]) as $t) {
    $val = round((int) $t['qty'] * (float) $t['price'], 2);
    $lnk = 'stock.php?id=' . (int) $t['sid'];
    if ((int) $t['buyer_id'] === $pid)
        $add($t['ts'], -$val, 'kupno', '🟢 Kupno akcji',
             'Kupno ' . (int) $t['qty'] . '× ' . $t['ticker'] . ' @ ' . money($t['price']) . ' PLN', $lnk);
    if ((int) $t['seller_id'] === $pid) {
        $fee = round($val * $feeRate, 2);
        $add($t['ts'], $val - $fee, 'sprzedaz', '🔴 Sprzedaż akcji',
             'Sprzedaż ' . (int) $t['qty'] . '× ' . $t['ticker'] . ' @ ' . money($t['price']) . ' PLN'
             . ($fee > 0 ? ' (prowizja −' . money($fee) . ')' : ''), $lnk);
    }
}

// 2) IPO — zapis (−) i zwrot z redukcji (+)
foreach (Engine::all("SELECT su.qty, su.paid, su.allotted, su.refund, su.created_at, o.name, o.ticker, o.price, o.close_session, o.stock_id
                      FROM ipo_subs su JOIN ipo_offers o ON o.id = su.offer_id
                      WHERE su.user_id = ? ORDER BY su.id DESC LIMIT $LIMIT", [$pid]) as $s) {
    $lnk = $s['stock_id'] ? 'stock.php?id=' . (int) $s['stock_id'] : 'ipo.php';
    $add($s['created_at'], -(float) $s['paid'], 'ipo', '📈 IPO — zapis',
         'Zapis IPO ' . $s['name'] . ' (' . $s['ticker'] . '): ' . (int) $s['qty'] . ' akcji @ ' . money($s['price']) . ' PLN', $lnk);
    if ((float) $s['refund'] > 0) {
        $note = 'Zwrot z redukcji IPO ' . $s['name']
              . ($s['allotted'] !== null ? ' — przydział ' . (int) $s['allotted'] . ' z ' . (int) $s['qty'] . ' akcji' : '');
        $add($dateOfSession((int) $s['close_session'], $s['created_at']), (float) $s['refund'], 'ipo', '📈 IPO — zwrot', $note, $lnk);
    }
}

// 3) LOKATY — wpłata (−) i wypłata/zwrot (+)
foreach (Engine::all("SELECT amount, rate_pct, end_session, status, created_at FROM deposits
                      WHERE user_id = ? ORDER BY id DESC LIMIT $LIMIT", [$pid]) as $d) {
    $amt = (float) $d['amount'];
    $add($d['created_at'], -$amt, 'lokata', '🏦 Lokata — wpłata',
         'Lokata ' . money($d['rate_pct']) . '% (termin: sesja #' . (int) $d['end_session'] . ')');
    if ($d['status'] === 'paid') {
        $payout = round($amt * (1 + (float) $d['rate_pct'] / 100), 2);
        $add($dateOfSession((int) $d['end_session'], $d['created_at']), $payout, 'lokata', '🏦 Lokata — wypłata',
             'Wypłata lokaty (w tym odsetki +' . money($payout - $amt) . ' PLN)');
    } elseif ($d['status'] === 'broken') {
        $add($d['created_at'], $amt, 'lokata', '🏦 Lokata — zerwana', 'Zerwano lokatę — zwrot kapitału (bez odsetek)');
    }
}

// 4) KSIĘGA GOTÓWKI (dywidendy itd. — od wdrożenia strony)
foreach (Engine::all("SELECT ts, amount, category, note, link FROM cash_ledger
                      WHERE user_id = ? ORDER BY id DESC LIMIT $LIMIT", [$pid]) as $c) {
    $cat = $c['category'] === 'dividend' ? 'dywidenda' : $c['category'];
    $lbl = $c['category'] === 'dividend' ? '💰 Dywidenda' : 'ℹ️ ' . $c['category'];
    $add($c['ts'], (float) $c['amount'], $cat, $lbl, (string) $c['note'], (string) ($c['link'] ?? ''));
}

// filtr kategorii
if ($fil !== '') $mov = array_values(array_filter($mov, fn($m) => $m['cat'] === $fil));

// sortuj malejąco po czasie i pogrupuj po dniu
usort($mov, fn($a, $b) => strcmp($b['ts'], $a['ts']));
$mov = array_slice($mov, 0, 800);
$dni = [];                    // data => ['in'=>, 'out'=>, 'items'=>[]]
$sumIn = 0.0; $sumOut = 0.0;
foreach ($mov as $m) {
    $day = substr($m['ts'], 0, 10);
    if (!isset($dni[$day])) $dni[$day] = ['in' => 0.0, 'out' => 0.0, 'items' => []];
    if ($m['amount'] >= 0) { $dni[$day]['in'] += $m['amount']; $sumIn += $m['amount']; }
    else { $dni[$day]['out'] += -$m['amount']; $sumOut += -$m['amount']; }
    $dni[$day]['items'][] = $m;
}

$cash = (float) $p['cash']; $reserved = (float) $p['cash_reserved'];

layout_header('Historia konta', $user, 'portfolio');
?>
<div class="page-head">
  <h1>Historia konta<?= $pid !== (int) $user['id'] ? ': ' . h($p['username']) . ' <span class="tag">podgląd GM</span>' : '' ?></h1>
  <span class="muted">ile gotówki wpływa i wypływa z konta — dzień po dniu</span>
  <a class="btn sm ghost" style="margin-left:auto" href="dziennik.php<?= $pid !== (int) $user['id'] ? '?id=' . $pid : '' ?>">📓 Dziennik</a>
  <a class="btn sm ghost" href="portfolio.php">← Portfel</a>
</div>

<div class="grid3" style="margin-bottom:14px">
  <div class="panel"><div class="muted sm">Wolna gotówka</div><div style="font-size:1.35rem;font-weight:700"><?= money($cash) ?> PLN</div>
    <?php if ($reserved > 0.005): ?><div class="muted sm">+ zamrożone w zleceniach: <?= money($reserved) ?> PLN</div><?php endif; ?></div>
  <div class="panel"><div class="muted sm">Wpłynęło (widoczny okres)</div><div style="font-size:1.35rem;font-weight:700;color:#16a34a">+<?= money($sumIn) ?> PLN</div></div>
  <div class="panel"><div class="muted sm">Wypłynęło (widoczny okres)</div><div style="font-size:1.35rem;font-weight:700;color:#dc2626">−<?= money($sumOut) ?> PLN</div></div>
</div>

<div class="panel" style="margin-bottom:14px">
  <?php foreach ($KAT as $k => $lbl): ?>
    <a class="btn sm <?= $fil === $k ? '' : 'ghost' ?>" style="margin:2px" href="historia.php?f=<?= h($k) ?><?= $pid !== (int) $user['id'] ? '&id=' . $pid : '' ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$dni): ?>
  <div class="panel"><p class="muted" style="padding:22px;margin:0">Brak przepływów w tym widoku. Kupno/sprzedaż, IPO i lokaty pojawią się tu automatycznie; dywidendy — od teraz.</p></div>
<?php else: foreach ($dni as $day => $g): $net = $g['in'] - $g['out']; ?>
  <div class="panel" style="margin-bottom:12px;padding:0;overflow:hidden">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:11px 14px;border-bottom:1px solid var(--line,rgba(128,128,128,.2))">
      <b><?= h($day) ?></b>
      <span class="muted sm"><?= (int) count($g['items']) ?> zdarz.</span>
      <span style="margin-left:auto;color:#16a34a">+<?= money($g['in']) ?></span>
      <span style="color:#dc2626">−<?= money($g['out']) ?></span>
      <span title="saldo dnia" style="font-weight:700;<?= $net >= 0 ? 'color:#16a34a' : 'color:#dc2626' ?>">
        <?= $net >= 0 ? '+' : '−' ?><?= money(abs($net)) ?> PLN</span>
    </div>
    <div class="tbl-scroll">
      <table>
        <tbody>
        <?php foreach ($g['items'] as $m): $pos = $m['amount'] >= 0; ?>
          <tr<?= $m['link'] ? " class='rowlink' onclick=\"location='" . h($m['link']) . "'\"" : '' ?>>
            <td class="mono muted" style="white-space:nowrap;width:150px"><?= h(substr($m['ts'], 11, 5)) ?> · <?= h($m['label']) ?></td>
            <td><?= h($m['note']) ?></td>
            <td class="mono" style="text-align:right;white-space:nowrap;font-weight:600;<?= $pos ? 'color:#16a34a' : 'color:#dc2626' ?>">
              <?= $pos ? '+' : '−' ?><?= money(abs($m['amount'])) ?> PLN</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; endif; ?>

<p class="muted sm" style="margin-top:10px">
  Pokazuję ostatnie zdarzenia (do ~800). Sprzedaż liczona po potrąceniu prowizji od obrotu.
  Zwroty z IPO i wypłaty lokat datowane są dniem rozliczenia sesji. Dywidendy zapisują się od wdrożenia tej strony.
</p>
<?php layout_footer();
