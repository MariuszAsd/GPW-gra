<?php
require __DIR__ . '/_boot.php';
$user = acting_user(require_login());
$uidReal = (int) ($user['owner_id'] ?? $user['id']);

// osobisty cel gry: gracz ustawia własny próg (puste/0 = wróć do domyślnego z GM)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_goal'])) {
    $g = (float) str_replace([' ', ','], ['', '.'], (string) $_POST['goal_value']);
    if ($g <= 0) {
        Db::pdo()->prepare("UPDATE users SET goal_target=NULL, goal_session=NULL WHERE id=?")->execute([$uidReal]);
        flash('Wrócono do domyślnego celu gry.');
    } elseif ($g < 1000 || $g > 1000000000) {
        flash('Cel musi być między 1 000 a 1 000 000 000 PLN.', 'err');
    } else {
        // nowy cel = nowe polowanie (sesja osiągnięcia zeruje się; zdobyte odznaki zostają)
        Db::pdo()->prepare("UPDATE users SET goal_target=?, goal_session=NULL WHERE id=?")->execute([round($g, 2), $uidReal]);
        Engine::journal($uidReal, 'goal', '🎯 Ustawiono osobisty cel gry: ' . number_format($g, 0, ',', ' ') . ' PLN.');
        flash('Nowy cel: ' . number_format($g, 0, ',', ' ') . ' PLN. Powodzenia!');
    }
    redirect('portfolio.php');
}

// lokaty: założenie / zerwanie (zawsze z KONTA GŁÓWNEGO — portfel wyzwania gra tylko akcjami)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bank_open'])) {
    $amount = (float) str_replace([' ', ','], ['', '.'], (string) ($_POST['bank_amount'] ?? '0'));
    [$ok, $msg] = Bank::open($uidReal, $amount, (int) ($_POST['bank_term'] ?? 0));
    flash($msg, $ok ? 'ok' : 'err');
    redirect('portfolio.php?tab=lok');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bank_break'])) {
    [$ok, $msg] = Bank::breakDeposit($uidReal, (int) $_POST['bank_break']);
    flash($msg, $ok ? 'ok' : 'err');
    redirect('portfolio.php?tab=lok');
}

$pos = Engine::all("SELECT w.stock_id, s.ticker, s.name, s.price, w.qty, w.qty_reserved, w.avg_price
                    FROM wallets w JOIN stocks s ON s.id=w.stock_id
                    WHERE w.user_id=? AND (w.qty>0 OR w.qty_reserved>0) ORDER BY s.ticker", [$user['id']]);
$orders = Engine::all("SELECT o.*, s.ticker FROM orders o JOIN stocks s ON s.id=o.stock_id
                       WHERE o.user_id=? AND o.status IN ('active','pending') ORDER BY o.id DESC", [$user['id']]);
// buyer_id=? OR seller_id=? rozbite na UNION: MySQL dla OR na dwóch kolumnach (z JOIN i ORDER BY)
// często NIE stosuje index_merge i skanuje całą — ogromną od botów — tabelę transakcji. UNION wymusza
// seek po ix_tx_buyer / ix_tx_seller. Wynik identyczny (gracz jest kupującym ALBO sprzedającym).
$history = Engine::all("SELECT t.*, s.ticker FROM transactions t JOIN stocks s ON s.id=t.stock_id WHERE t.buyer_id=?
                        UNION
                        SELECT t.*, s.ticker FROM transactions t JOIN stocks s ON s.id=t.stock_id WHERE t.seller_id=?
                        ORDER BY id DESC LIMIT 30", [$user['id'], $user['id']]);
$archive = Engine::all("SELECT o.*, s.ticker FROM orders o JOIN stocks s ON s.id=o.stock_id
                        WHERE o.user_id=? AND o.status<>'active' ORDER BY o.id DESC LIMIT 30", [$user['id']]);

// --- zamknięte pozycje: zrealizowany wynik metodą średniego kosztu (odtworzenie z transakcji) ---
// jak wyżej: OR -> UNION (seek po indeksach zamiast pełnego skanu transakcji na MySQL).
// t.id w SELECT jest potrzebne do poprawnego dedupu UNION (dwie identyczne transakcje mają różne id).
$allTx = Engine::all("SELECT t.id AS tid, t.stock_id, t.qty, t.price, t.buyer_id, s.ticker, s.name
                        FROM transactions t JOIN stocks s ON s.id=t.stock_id WHERE t.buyer_id=?
                      UNION
                      SELECT t.id AS tid, t.stock_id, t.qty, t.price, t.buyer_id, s.ticker, s.name
                        FROM transactions t JOIN stocks s ON s.id=t.stock_id WHERE t.seller_id=?
                      ORDER BY tid", [$user['id'], $user['id']]);
$feeRate = Engine::feeRate();
$closed = [];   // stock_id => [ticker, name, sold_qty, buy_value, sell_value_net, realized]
$lots = [];     // stock_id => [qty, avg]
foreach ($allTx as $t) {
    $sid2 = (int) $t['stock_id']; $q2 = (int) $t['qty']; $p2 = (float) $t['price'];
    if (!isset($lots[$sid2])) $lots[$sid2] = ['qty' => 0, 'avg' => 0.0];
    $L = &$lots[$sid2];
    if ((int) $t['buyer_id'] === (int) $user['id']) {
        $L['avg'] = $L['qty'] + $q2 > 0 ? ($L['qty'] * $L['avg'] + $q2 * $p2) / ($L['qty'] + $q2) : 0;
        $L['qty'] += $q2;
    } else {
        $sellQ = min($q2, $L['qty']);            // akcje spoza odtworzonej historii (np. pakiet startowy) pomijamy
        if ($sellQ <= 0) continue;
        $val = $sellQ * $p2;
        $net = $val - round($val * $feeRate, 2);
        if (!isset($closed[$sid2])) $closed[$sid2] = ['ticker' => $t['ticker'], 'name' => $t['name'], 'sold' => 0, 'cost' => 0.0, 'net' => 0.0];
        $closed[$sid2]['sold'] += $sellQ;
        $closed[$sid2]['cost'] += $sellQ * $L['avg'];
        $closed[$sid2]['net']  += $net;
        $L['qty'] -= $sellQ;
    }
    unset($L);
}
$realizedTotal = 0.0;
foreach ($closed as &$c) { $c['pl'] = $c['net'] - $c['cost']; $realizedTotal += $c['pl']; }
unset($c);
uasort($closed, fn($a, $b) => $b['pl'] <=> $a['pl']);

// --- wykres kapitału (equity_history pisane co tick przez silnik) ---
$eqSeries = array_reverse(array_map('floatval', Engine::col("SELECT equity FROM equity_history WHERE user_id=? ORDER BY t DESC LIMIT 150", [$user['id']])));
$eqSvg = equity_svg($eqSeries);

$value = 0; $cost = 0;
foreach ($pos as $p) { $q = $p['qty'] + $p['qty_reserved']; $value += $q * $p['price']; $cost += $q * $p['avg_price']; }
$pl = $value - $cost;
$locked = Engine::lockedFunds((int) $user['id']);   // lokaty + zapisy IPO (konto-cień wyzwania: 0)
$equity = $user['cash'] + $user['cash_reserved'] + $value + $locked;
$plPct = $cost > 0 ? $pl / $cost * 100 : 0;
$deposits = ($user['ctx'] ?? '') !== 'challenge' ? Bank::activeFor($uidReal) : [];

// --- cel gry: osobisty próg gracza ma pierwszeństwo przed domyślnym z GM ---
$goalDefault = (float) (Engine::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
$goalSessions = (int) (Engine::one("SELECT v FROM game_state WHERE k='goal_sessions'") ?: 0);
[$sessionNo] = Engine::sessionInfo();
$me = Engine::row("SELECT joined_session, goal_session, goal_target AS my_goal FROM users WHERE id=?", [$uidReal]);
$goalTarget = $me['my_goal'] !== null ? (float) $me['my_goal'] : $goalDefault;
$deadline = (int) ($me['joined_session'] ?? 1) + $goalSessions - 1;
$sessionsLeft = $deadline - $sessionNo;
$progress = $goalTarget > 0 ? min(100, $equity / $goalTarget * 100) : 0;

layout_header('Portfel', $user, 'portfolio');
?>
<div class="page-head"><h1>Portfel</h1><?= session_tag($sessionNo) ?>
  <a class="btn sm ghost" style="margin-left:auto" href="historia.php">💸 Historia konta</a>
  <a class="btn sm ghost" href="dziennik.php">Dziennik</a></div>
<?php explainer('portfel', 'Jak czytać Portfel', [
    'pozycje: kurs, cena kupna i wynik', 'kliknij pozycję, by ustawić SL/TP',
    'kliknij zlecenie po szczegóły', 'pełna historia w Dzienniku']); ?>

<?php if ($goalTarget > 0 && ($user['ctx'] ?? '') !== 'challenge'): // cel gry = dyskretna wzmianka (szczegóły po rozwinięciu) ?>
<details class="goal-mini">
  <summary>🎯 Cel gry: <b><?= number_format($progress, 0, ',', ' ') ?>%</b> z <?= money_short($goalTarget) ?> PLN
    <span class="bar"><i style="width:<?= round(min(100, $progress), 1) ?>%"></i></span>
    <?php if ($me['goal_session'] !== null): ?><span class="up">🏆 osiągnięty (sesja #<?= (int) $me['goal_session'] ?>)</span>
    <?php elseif ($sessionsLeft >= 0 && $me['my_goal'] === null): ?><span class="muted" title="Ile sesji zostało Ci na milion CAŁYM kapitałem konta (nie dotyczy Wyzwań)">do celu gry: <?= $sessionsLeft ?> sesji</span><?php endif; ?>
    <span class="muted" style="text-decoration:underline">szczegóły</span>
  </summary>
  <div class="panel" style="padding:12px 14px">
    <div class="goal-nums mono"><span><?= money($equity) ?> PLN</span><span><?= number_format($progress, 1, ',', ' ') ?>%</span><span><?= money($goalTarget) ?> PLN</span></div>
    <form method="post" class="inline" style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="hidden" name="set_goal" value="1">
      <label style="margin:0;display:inline">Twój własny cel (PLN):</label>
      <input type="number" name="goal_value" min="0" step="10000" value="<?= $me['my_goal'] !== null ? (int) $me['my_goal'] : '' ?>" placeholder="np. 500000" style="width:140px">
      <button class="btn sm ghost">Zapisz</button>
      <span class="muted" style="font-size:11.5px">puste = wróć do domyślnego · zmiana zaczyna polowanie od nowa</span>
    </form>
  </div>
</details>
<?php endif; ?>

<div class="stats">
  <div class="stat"><div class="k">Kapitał</div><div class="v"><?= money($equity) ?></div></div>
  <div class="stat"><div class="k">Gotówka</div><div class="v"><?= money($user['cash']) ?></div></div>
  <div class="stat"><div class="k">Wartość akcji</div><div class="v"><?= money($value) ?></div></div>
  <?php if ($locked > 0): ?>
  <div class="stat"><div class="k">Lokaty i zapisy IPO</div><div class="v"><?= money($locked) ?></div></div>
  <?php endif; ?>
  <div class="stat"><div class="k">Wynik</div><div class="v <?= $pl >= 0 ? 'up' : 'down' ?>"><?= ($pl >= 0 ? '+' : '') . money($pl) ?><span style="font-size:13px"> (<?= ($plPct >= 0 ? '+' : '') . number_format($plPct, 1, ',', ' ') ?>%)</span></div></div>
</div>

<div class="subtabs">
  <button class="on" data-tab="poz">Pozycje<?= $pos ? ' (' . count($pos) . ')' : '' ?></button>
  <button data-tab="zle">Zlecenia<?= $orders ? ' (' . count($orders) . ')' : '' ?></button>
  <button data-tab="lok">Lokaty<?= $deposits ? ' (' . count($deposits) . ')' : '' ?></button>
  <button data-tab="his">Historia</button>
</div>

<div class="tabpane on" id="tab-poz">
<div class="panel" style="margin-bottom:16px;padding:14px 16px 10px">
  <h2 style="margin:0">Wartość portfela w czasie</h2>
  <?= $eqSvg ?: "<p class='muted' style='margin:10px 0'>Wykres buduje się z każdym tickiem rynku…</p>" ?>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:14px 16px 0"><h2>Pozycje w portfelu <span class="muted" style="text-transform:none;letter-spacing:0">· kliknij pozycję — SL/TP i szczegóły</span></h2></div>
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>Instrument</th><th class="num">Ilość</th><th class="num">Kurs</th><th class="num">Kupno</th><th class="num hide-m">Wartość</th><th class="hide-m">Udział</th><th class="num">Wynik</th></tr></thead>
      <tbody>
      <?php $maxPosVal = 0.0; foreach ($pos as $pp) { $vv = ((int) $pp['qty'] + (int) $pp['qty_reserved']) * (float) $pp['price']; if ($vv > $maxPosVal) $maxPosVal = $vv; }
            foreach ($pos as $p): $q = $p['qty'] + $p['qty_reserved']; $ppl = $q * ($p['price'] - $p['avg_price']);
            $pplPct = (float) $p['avg_price'] > 0 ? ($p['price'] / $p['avg_price'] - 1) * 100 : 0; $sid3 = (int) $p['stock_id'];
            $posVal = $q * (float) $p['price']; $sharePct = (float) $value > 0 ? $posVal / (float) $value * 100 : 0;
            $barPct = $maxPosVal > 0 ? min(100, $posVal / $maxPosVal * 100) : 0; ?>
        <tr class="pos-row" data-pos="<?= $sid3 ?>" title="Kliknij — zlecenie obronne SL/TP i szczegóły pozycji">
          <td><div class="sym"><a class="tk" href="stock.php?id=<?= $sid3 ?>"><?= h($p['ticker']) ?></a><span class="nm hide-m"><?= h($p['name']) ?></span></div></td>
          <td class="num"><?= (int) $q ?></td>
          <td class="num mono"><?= money($p['price']) ?></td>
          <td class="num mono muted"><?= money($p['avg_price']) ?></td>
          <td class="num hide-m"><?= money($q * $p['price']) ?></td>
          <td class="hide-m"><div class="wbar" title="<?= number_format($sharePct, 1, ',', ' ') ?>% wartości akcji"><i style="width:<?= round($barPct, 1) ?>%"></i></div><span class="muted mono" style="font-size:11px"><?= number_format($sharePct, 1, ',', ' ') ?>%</span></td>
          <td class="num <?= $ppl >= 0 ? 'up' : 'down' ?>"><b><?= ($ppl >= 0 ? '+' : '') . money($ppl) ?></b>
            <span style="display:block;font-size:11px"><?= ($pplPct >= 0 ? '+' : '') . number_format($pplPct, 1, ',', ' ') ?>%</span></td>
        </tr>
        <tr class="pos-detail" id="pos-d-<?= $sid3 ?>" hidden>
          <td colspan="7">
            <?php if ($p['qty_reserved'] > 0): ?><p class="muted" style="margin:0 0 8px;font-size:12px"><?= (int) $p['qty_reserved'] ?> szt. czeka w zleceniach sprzedaży (zakładka Zlecenia).</p><?php endif; ?>
            <div class="pos-actions">
              <form method="post" action="set_sltp.php" class="sltp-form">
                <div><label>Ilość (szt.)</label><input type="number" name="qty" min="1" value="<?= (int) $p['qty'] ?>"></div>
                <div><label>Stop-Loss</label><input type="number" step="0.01" name="sl_price" placeholder="—"></div>
                <div><label>Take-Profit</label><input type="number" step="0.01" name="tp_price" placeholder="—"></div>
                <div><label>SL krocz. %<?= tip('SL kroczący: próg sam podąża za rosnącym kursem, np. 8 = zawsze 8% pod szczytem.', 'sl') ?></label><input type="number" step="0.5" min="0.5" max="50" name="trail" placeholder="—"></div>
                <input type="hidden" name="stock_id" value="<?= $sid3 ?>">
                <button class="btn sm">Ustaw zlecenie obronne</button>
              </form>
              <a class="btn sm ghost" href="stock.php?id=<?= $sid3 ?>">Karta spółki →</a>
            </div>
            <p class="muted" style="margin:8px 0 0;font-size:11.5px">Zlecenie obronne sprzeda podaną ilość, gdy kurs spadnie do SL (ucina stratę) lub wzrośnie do TP (zgarnia zysk). Widoczne i anulowalne w zakładce Zlecenia.</p>
          </td>
        </tr>
      <?php endforeach; if (!$pos) echo "<tr><td class='muted' colspan=7 style='padding:20px'>Brak pozycji — kup coś na Rynku.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
</div><!-- /tab-poz -->

<div class="tabpane" id="tab-zle">
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:14px 16px 0"><h2>Aktywne zlecenia <span class="muted" style="text-transform:none;letter-spacing:0">· kliknij wiersz, aby zobaczyć szczegóły</span></h2></div>
  <div class="tbl-scroll tbl-cap">
    <table>
      <thead><tr><th>Instrument</th><th>Typ</th><th class="num">Ilość<?= tip('Ile jeszcze czeka w arkuszu / ile było w całym zleceniu. „częściowo" = część już się zrealizowała, reszta czeka na kupca/sprzedawcę.', '') ?></th><th class="num">Cena</th><th class="hide-m">Ważność</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): $isStop = $o['status'] === 'pending'; $init = (int) ($o['qty_init'] ?? $o['qty']); $rem = (int) $o['qty']; $done = max(0, $init - $rem); ?>
        <tr class="rowlink" onclick="location='order.php?id=<?= (int) $o['id'] ?>'" title="Kliknij — szczegóły zlecenia">
          <td class="tk"><?= h($o['ticker']) ?></td>
          <td><?php if ($isStop): ?><span class="chg" style="color:var(--gold);background:var(--gold-bg)">OBRONNE</span>
              <?php else: ?><span class="chg <?= $o['side'] === 'buy' ? 'p' : 'n' ?>"><?= $o['side'] === 'buy' ? 'KUPNO' : 'SPRZEDAŻ' ?></span><?php endif; ?></td>
          <td class="num"><?php if (!$isStop && $done > 0): ?><b><?= $rem ?></b><span class="muted" style="font-size:11px"> z <?= $init ?></span><span class="chg p" style="font-size:9px;display:block;letter-spacing:0">częściowo · <?= $done ?> kupione</span><?php else: ?><?= $rem ?><?php endif; ?></td>
          <td class="num"><?php if ($isStop): ?><span class="mono" style="font-size:12px"><?= $o['sl_price'] !== null ? (($o['trail_pct'] ?? null) !== null ? 'SL krocz. ' . rtrim(rtrim(number_format((float) $o['trail_pct'], 1, ',', ''), '0'), ',') . '%: ' : 'SL ') . money($o['sl_price']) : '' ?><?= $o['sl_price'] !== null && $o['tp_price'] !== null ? ' · ' : '' ?><?= $o['tp_price'] !== null ? 'TP ' . money($o['tp_price']) : '' ?></span><?php else: ?><?= money($o['price']) ?><?php endif; ?></td>
          <td class="muted hide-m"><?= $isStop ? 'do wyzwolenia' : ($o['expires_session'] !== null ? 'sesja #' . (int) $o['expires_session'] : 'bezterm.') ?></td>
          <td style="text-align:right"><div style="display:flex;gap:6px;justify-content:flex-end;align-items:flex-start;flex-wrap:wrap"><?= order_edit_form($o, 'portfolio.php') ?><form method="post" action="cancel_order.php" onclick="event.stopPropagation()"><input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>"><button class="btn sm ghost">Anuluj</button></form></div></td>
        </tr>
      <?php endforeach; if (!$orders) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Brak aktywnych zleceń.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
<?php /* archiwum zleceń — razem ze zleceniami (ta sama podzakładka) */ ?>
<div class="panel" style="padding:0;overflow:hidden;margin-top:16px">
  <div style="padding:14px 16px 0"><h2>Archiwum zleceń <span class="muted" style="text-transform:none;letter-spacing:0">· kliknij wiersz — pełna oś czasu: co, kiedy i dlaczego</span></h2></div>
  <div class="tbl-scroll tbl-cap">
    <table>
      <thead><tr><th class="hide-m">Czas</th><th>Instrument</th><th>Strona</th><th>Status</th><th class="num hide-m">Obrót</th><th></th></tr></thead>
      <tbody>
      <?php
      $stLabel = ['filled' => ['zrealizowane', 'up'], 'cancelled' => ['anulowane', 'muted'], 'expired' => ['wygasłe', 'muted'],
                  'triggered' => ['SL/TP wyzwolone', 'soft']];
      // obrót zrealizowany per zlecenie (suma transakcji podpiętych do zlecenia) — szczegóły w osi czasu
      $ids = array_map(fn($o) => (int) $o['id'], $archive);
      $turn = [];
      if ($ids) {
          $ph = implode(',', array_fill(0, count($ids), '?'));
          foreach (Engine::all("SELECT COALESCE(buy_order_id, sell_order_id) oid, SUM(qty*price) v
                                FROM transactions WHERE buy_order_id IN ($ph) OR sell_order_id IN ($ph)
                                GROUP BY COALESCE(buy_order_id, sell_order_id)", array_merge($ids, $ids)) as $r) {
              $turn[(int) $r['oid']] = (float) $r['v'];
          }
      }
      foreach ($archive as $o):
          [$lbl, $cls] = $stLabel[$o['status']] ?? [$o['status'], 'muted'];
          $init = (int) $o['qty_init']; $done = $init > 0 ? $init - (int) $o['qty'] : 0;
          if ($done > 0 && !in_array($o['status'], ['filled', 'triggered'], true)) { $lbl .= ' (częściowo)'; $cls = 'soft'; }
          $v = $turn[(int) $o['id']] ?? 0.0;
      ?>
        <tr class="rowlink" onclick="location='order.php?id=<?= (int) $o['id'] ?>'">
          <td class="muted mono hide-m"><?= h(substr($o['created_at'], 5, 11)) ?></td>
          <td class="tk"><?= h($o['ticker']) ?></td>
          <td><span class="chg <?= $o['side'] === 'buy' ? 'p' : 'n' ?>"><?= $o['side'] === 'buy' ? 'KUPNO' : 'SPRZEDAŻ' ?></span></td>
          <td class="<?= $cls ?>"><?= h($lbl) ?></td>
          <td class="num hide-m"><?= $v > 0 ? money($v) . ' PLN' : '<span class="muted">—</span>' ?></td>
          <td style="text-align:right;padding-right:14px"><a class="btn sm ghost" href="order.php?id=<?= (int) $o['id'] ?>" onclick="event.stopPropagation()">Szczegóły →</a></td>
        </tr>
      <?php endforeach; if (!$archive) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Brak zakończonych zleceń.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
</div><!-- /tab-zle -->

<div class="tabpane" id="tab-lok">
<?php if (($user['ctx'] ?? '') === 'challenge'): ?>
  <div class="panel"><h2>Lokaty</h2>
    <p class="muted">Portfel wyzwania gra wyłącznie akcjami — lokaty znajdziesz na koncie głównym.</p></div>
<?php else: [$curSession] = Engine::sessionInfo(); ?>
  <div class="panel" style="margin-bottom:16px">
    <h2>Lokaty — bezpieczny procent
      <?= tip('Zamrażasz gotówkę na N sesji za stały procent (wypłata automatyczna). Kapitał lokaty CAŁY CZAS liczy się do Twojego kapitału w rankingu i celu gry. Zerwanie przed terminem zwraca kapitał, ale odsetki przepadają.', '') ?>
    </h2>
    <div class="ch-grid">
      <?php foreach (Bank::offers() as $term => $rate): ?>
        <div class="ch-stat"><small><?= $term ?> <?= Bank::sesje($term) ?></small><b class="up"><?= number_format($rate, 1, ',', ' ') ?>%</b></div>
      <?php endforeach; ?>
    </div>
    <form method="post" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;margin-top:10px">
      <input type="hidden" name="bank_open" value="1">
      <div><label style="font-size:11.5px">Kwota (min <?= number_format(Bank::MIN_AMOUNT, 0, ',', ' ') ?> PLN)</label>
        <input type="number" name="bank_amount" min="<?= (int) Bank::MIN_AMOUNT ?>" step="100" placeholder="np. 20000" style="width:140px" required></div>
      <div><label style="font-size:11.5px">Okres</label>
        <select name="bank_term" style="width:auto">
          <?php foreach (Bank::offers() as $term => $rate): ?>
            <option value="<?= $term ?>"><?= $term ?> <?= Bank::sesje($term) ?> · <?= number_format($rate, 1, ',', ' ') ?>%</option>
          <?php endforeach; ?>
        </select></div>
      <button class="btn sm" style="width:auto">Załóż lokatę</button>
      <span class="muted" style="font-size:11.5px">wolna gotówka: <?= money($user['cash']) ?> PLN</span>
    </form>
  </div>
  <div class="panel">
    <h2>Aktywne lokaty<?= $deposits ? ' — razem ' . money(array_sum(array_map(fn($d) => (float) $d['amount'], $deposits))) . ' PLN' : '' ?></h2>
    <?php if (!$deposits): ?><p class="muted">Brak aktywnych lokat. Nadwyżkę gotówki, której nie inwestujesz, możesz tu bezpiecznie oprocentować.</p><?php else: ?>
    <div class="deps">
      <?php foreach ($deposits as $d): $payout = round((float) $d['amount'] * (1 + (float) $d['rate_pct'] / 100), 2);
            $left = max(0, (int) $d['end_session'] - $curSession);
            $odm = $left === 1 ? 'sesję' : Bank::sesje($left); ?>
        <div class="dep">
          <div class="dep-col">
            <b class="mono"><?= money($d['amount']) ?> PLN</b>
            <span class="muted"><?= number_format((float) $d['rate_pct'], 1, ',', ' ') ?>% za cały okres</span>
          </div>
          <div class="dep-col">
            <b class="mono up">→ <?= money($payout) ?> PLN</b>
            <span class="muted"><?= $left > 0 ? "wypłata za $left $odm (sesja #" . (int) $d['end_session'] . ')' : 'wypłata przy najbliższej sesji' ?></span>
          </div>
          <form method="post" onsubmit="return confirm('Zerwać lokatę? Kapitał wróci, ale odsetki przepadną.')">
            <button class="btn sm ghost" name="bank_break" value="<?= (int) $d['id'] ?>">Zerwij</button></form>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>
</div><!-- /tab-lok -->

<div class="tabpane" id="tab-his">
<?php if ($closed): ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div style="padding:14px 16px 0"><h2>Zamknięte pozycje <span class="muted" style="text-transform:none;letter-spacing:0">· na czym zarobiłeś, na czym straciłeś (po prowizji, bez dywidend)</span>
    <span style="float:right" class="mono <?= $realizedTotal >= 0 ? 'up' : 'down' ?>"><?= ($realizedTotal >= 0 ? '+' : '') . money($realizedTotal) ?> PLN</span></h2></div>
  <div class="tbl-scroll tbl-cap">
    <table>
      <thead><tr><th>Instrument</th><th class="num hide-m">Sprzedane</th><th class="num hide-m">Śr. koszt</th><th class="num hide-m">Śr. sprzedaż (netto)</th><th class="num">Zrealizowany wynik</th></tr></thead>
      <tbody>
      <?php foreach ($closed as $sid2 => $c): ?>
        <tr class="rowlink" onclick="location='stock.php?id=<?= (int) $sid2 ?>'">
          <td><div class="sym"><span class="tk"><?= h($c['ticker']) ?></span><span class="nm"><?= h($c['name']) ?></span></div></td>
          <td class="num hide-m"><?= (int) $c['sold'] ?> szt.</td>
          <td class="num hide-m"><?= money($c['cost'] / max(1, $c['sold'])) ?></td>
          <td class="num hide-m"><?= money($c['net'] / max(1, $c['sold'])) ?></td>
          <td class="num <?= $c['pl'] >= 0 ? 'up' : 'down' ?>"><b><?= ($c['pl'] >= 0 ? '+' : '') . money($c['pl']) ?></b></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="panel" style="padding:0;overflow:hidden;margin-top:16px">
  <div style="padding:14px 16px 0"><h2>Historia transakcji</h2></div>
  <div class="tbl-scroll tbl-cap">
    <table>
      <thead><tr><th>Czas</th><th>Instrument</th><th>Strona</th><th class="num">Ilość</th><th class="num">Kurs</th><th class="num hide-m">Wartość</th></tr></thead>
      <tbody>
      <?php foreach ($history as $t): $isBuy = (int) $t['buyer_id'] === (int) $user['id']; $v = $t['qty'] * $t['price'];
            $myOrder = $isBuy ? ($t['buy_order_id'] ?? null) : ($t['sell_order_id'] ?? null); ?>
        <tr<?= $myOrder ? " class='rowlink' onclick=\"location='order.php?id=" . (int) $myOrder . "'\" title='Kliknij — szczegóły zlecenia'" : '' ?>>
          <td class="muted mono"><?= h(substr($t['created_at'], 5, 11)) ?></td>
          <td class="tk"><?= h($t['ticker']) ?></td>
          <td><span class="chg <?= $isBuy ? 'p' : 'n' ?>"><?= $isBuy ? 'KUPNO' : 'SPRZEDAŻ' ?></span></td>
          <td class="num"><?= (int) $t['qty'] ?></td>
          <td class="num"><?= money($t['price']) ?></td>
          <td class="num hide-m"><?= money($v) ?></td>
        </tr>
      <?php endforeach; if (!$history) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Jeszcze nic nie kupiłeś ani nie sprzedałeś.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="muted" style="margin:12px 2px;font-size:12px">Pełna oś czasu konta (dywidendy, SL/TP, odznaki, wyzwania) — w <a href="dziennik.php"><b>Dzienniku</b></a>.</p>
</div><!-- /tab-his -->

<script>
// akordeon pozycji: klik w wiersz rozwija SL/TP i szczegóły (linki/formularze nie przełączają)
document.querySelectorAll('.pos-row').forEach(r => r.onclick = e => {
  if (e.target.closest('a,form,button,input,select,label')) return;
  const d = document.getElementById('pos-d-' + r.dataset.pos);
  const wasOpen = d && !d.hidden;
  document.querySelectorAll('.pos-detail').forEach(x => x.hidden = true);
  document.querySelectorAll('.pos-row').forEach(x => x.classList.remove('open'));
  if (d && !wasOpen) { d.hidden = false; r.classList.add('open'); }
});
document.querySelectorAll('.subtabs button').forEach(b => b.onclick = () => {
  document.querySelectorAll('.subtabs button').forEach(x => x.classList.remove('on'));
  document.querySelectorAll('.tabpane').forEach(x => x.classList.remove('on'));
  b.classList.add('on');
  document.getElementById('tab-' + b.dataset.tab)?.classList.add('on');
});
// głęboki link ?tab=zle / ?tab=his (np. z powiadomień)
const ptab = new URLSearchParams(location.search).get('tab');
if (ptab) document.querySelector(`.subtabs button[data-tab="${ptab}"]`)?.click();
</script>
<?php layout_footer();
