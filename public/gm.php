<?php
require __DIR__ . '/_boot.php';
$user = require_login();

// tylko admin
$role = Engine::one("SELECT role FROM users WHERE id=?", [$user['id']]);
if ($role !== 'admin') { http_response_code(403); echo "Brak dostępu (tylko admin)."; exit; }

// --- akcje ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    $pdo = Db::pdo();
    if ($a === 'sentiment') {
        Engine::setState('sentiment', (string) (float) str_replace(',', '.', $_POST['sentiment'] ?? '0'));
        flash('Ustawiono nastawienie rynku.');
    } elseif ($a === 'botact') {
        Engine::setState('bot_activity', (string) max(0, min(3, (float) str_replace(',', '.', $_POST['bot_activity'] ?? '1'))));
        flash('Ustawiono aktywność botów.');
    } elseif ($a === 'goal') {
        Engine::setState('goal_target', (string) max(0, (float) str_replace(',', '.', $_POST['goal_target'] ?? '0')));
        Engine::setState('goal_sessions', (string) max(1, (int) ($_POST['goal_sessions'] ?? 60)));
        Engine::setState('ticks_per_session', (string) max(1, (int) ($_POST['ticks_per_session'] ?? 20)));
        flash('Zapisano ustawienia celu gry i sesji.');
    } elseif ($a === 'invite') {
        Engine::setState('invite_code', trim($_POST['invite_code'] ?? ''));
        flash(trim($_POST['invite_code'] ?? '') === '' ? 'Rejestracja otwarta (bez kodu).' : 'Ustawiono kod zaproszenia.');
    } elseif ($a === 'fee') {
        Engine::setState('fee_rate', (string) max(0, min(5, (float) str_replace(',', '.', $_POST['fee_rate'] ?? '0.5'))));
        flash('Ustawiono prowizję od obrotu.');
    } elseif ($a === 'qa') {
        require_once __DIR__ . '/../src/Qa.php';
        $r = Qa::run();
        flash($r['ok'] ? "✅ QA: wszystkie {$r['checks']} asercji OK." : '❌ QA znalazł błędy: ' . implode(' | ', array_slice($r['fails'], 0, 3)), $r['ok'] ? 'ok' : 'err');
    } elseif ($a === 'stock') {
        $pdo->prepare("UPDATE stocks SET bias=?, volatility=?, profit_trend=?, dividend_payout=? WHERE id=?")->execute([
            (float) str_replace(',', '.', $_POST['bias'] ?? '0'),
            max(0.1, (float) str_replace(',', '.', $_POST['vol'] ?? '1')),
            (float) str_replace(',', '.', $_POST['profit_trend'] ?? '0'),
            max(0, min(0.8, (float) str_replace(',', '.', $_POST['dividend'] ?? '0') / 100)),
            (int) $_POST['stock_id'],
        ]);
        flash('Zapisano sterowanie spółką.');
    } elseif ($a === 'sector') {
        $pdo->prepare("UPDATE sectors SET trend=?, profit_climate=? WHERE id=?")->execute([
            (float) str_replace(',', '.', $_POST['trend'] ?? '0'),
            (float) str_replace(',', '.', $_POST['profit_climate'] ?? '0'),
            (int) $_POST['sector_id'],
        ]);
        flash('Zapisano sterowanie sektorem.');
    } elseif ($a === 'event') {
        $pct = (float) str_replace(',', '.', $_POST['pct'] ?? '0');
        $pdo->prepare("UPDATE stocks SET fundamental = ROUND(fundamental * (1 + ?/100), 2) WHERE id=?")
            ->execute([$pct, (int) $_POST['stock_id']]);
        Engine::runTick();
        flash(($pct >= 0 ? 'Pompka' : 'Zrzut') . " {$pct}% + tick wykonany.");
    } elseif ($a === 'report') {
        $t = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
        $pdo->prepare("UPDATE stocks SET next_report_tick=? WHERE id=?")->execute([$t, (int) $_POST['stock_id']]);
        Engine::generateReports($t);
        flash('Opublikowano raport miesięczny.');
    } elseif ($a === 'tick') {
        $n = max(1, min(30, (int) ($_POST['n'] ?? 1)));
        try {
            for ($i = 0; $i < $n; $i++) Engine::runTick();
            flash("Wykonano {$n} ticków.");
        } catch (Throwable $e) {
            Log::write('error', 'engine', 'tick.exception', $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
            flash('Błąd ticka (zapisany w dzienniku): ' . $e->getMessage(), 'err');
        }
    } elseif ($a === 'world_event') {
        $kind = $_POST['kind'] ?? '';
        $tpl = EventCatalog::get($kind);
        if ($tpl && $tpl['scope'] === 'MARKET') { $head = Engine::triggerEvent($kind); Engine::runTick(); flash("Wydarzenie: $head (+1 tick)."); }
        else flash('Nieznane wydarzenie rynkowe.', 'err');
    } elseif ($a === 'sector_event') {
        $kind = $_POST['kind'] ?? '';
        $tpl = EventCatalog::get($kind);
        if ($tpl && $tpl['scope'] === 'SECTOR') { $head = Engine::triggerEvent($kind, (int) $_POST['sector_id']); Engine::runTick(); flash("Wydarzenie: $head (+1 tick)."); }
        else flash('Nieznane wydarzenie sektorowe.', 'err');
    } elseif ($a === 'company_event') {
        $kind = $_POST['kind'] ?? '';
        $tpl = EventCatalog::get($kind);
        if ($tpl && $tpl['scope'] === 'COMPANY') { $head = Engine::triggerEvent($kind, null, (int) $_POST['stock_id']); Engine::runTick(); flash("Wydarzenie: $head (+1 tick)."); }
        else flash('Nieznane wydarzenie spółkowe.', 'err');
    } elseif ($a === 'tempo') {
        Engine::setState('sub_rounds', (string) max(0, min(3, (int) ($_POST['sub_rounds'] ?? 2))));
        flash('Ustawiono tempo handlu.');
    } elseif ($a === 'events_toggle') {
        $on = ($_POST['enabled'] ?? '1') === '1';
        Engine::setState('events_enabled', $on ? '1' : '0');
        flash($on ? 'Losowe wydarzenia WŁĄCZONE.' : 'Losowe wydarzenia wyłączone (ręczne nadal działają).');
    } elseif ($a === 'reset') {
        $pdo->exec("UPDATE stocks SET bias=0, volatility=1, profit_trend=0");
        $pdo->exec("UPDATE sectors SET trend=0, profit_climate=0");
        Engine::setState('sentiment', '0');
        flash('Zresetowano sterowanie.');
    }
    redirect('gm.php');
}

$sentiment = (float) (Engine::one("SELECT v FROM game_state WHERE k='sentiment'") ?: 0);
$botact = Engine::one("SELECT v FROM game_state WHERE k='bot_activity'");
$botact = $botact === false || $botact === null ? 1.0 : (float) $botact;
$botCount = (int) Engine::one("SELECT COUNT(*) FROM users WHERE is_bot=1");
$tick = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
$goalTarget = (float) (Engine::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
$goalSessions = (int) (Engine::one("SELECT v FROM game_state WHERE k='goal_sessions'") ?: 60);
$inviteCode = (string) (Engine::one("SELECT v FROM game_state WHERE k='invite_code'") ?: '');
$playerCount = (int) Engine::one("SELECT COUNT(*) FROM users WHERE is_bot=0 AND role='player'");
$treasury = (float) (Engine::one("SELECT v FROM game_state WHERE k='treasury'") ?: 0);
$divPaid = (float) (Engine::one("SELECT v FROM game_state WHERE k='dividends_paid'") ?: 0);
$lastQa = Engine::row("SELECT ts, level, message FROM logs WHERE source='qa' AND event='qa.run' ORDER BY id DESC LIMIT 1");
$errors24 = (int) Engine::one("SELECT COUNT(*) FROM logs WHERE level='error' AND ts >= ?", [date('Y-m-d H:i:s', time() - 86400)]);
$feeRatePct = Engine::one("SELECT v FROM game_state WHERE k='fee_rate'");
$feeRatePct = ($feeRatePct === false || $feeRatePct === null) ? 0.5 : (float) $feeRatePct;
[$sessionNo, $ticksLeft, $tps] = Engine::sessionInfo();
$stocks = Engine::all("SELECT * FROM stocks ORDER BY ticker");
$sectors = Engine::all("SELECT * FROM sectors ORDER BY symbol");
$fmt = fn($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';

layout_header('Panel GM', $user, 'gm');
?>
<div class="page-head">
  <h1>Panel GM — sterowanie rynkiem</h1>
  <span class="muted">tick #<?= $tick ?> · sesja #<?= $sessionNo ?> (do końca: <?= $ticksLeft ?> ticków)</span>
</div>

<section class="panel" style="margin-bottom:16px">
  <h2>Cel gry i sesje</h2>
  <form method="post" class="row" style="align-items:flex-end">
    <input type="hidden" name="action" value="goal">
    <div><label>Cel — kapitał (PLN)</label><input type="number" step="10000" name="goal_target" value="<?= (int) $goalTarget ?>" style="width:140px"></div>
    <div><label>Limit sesji na cel</label><input type="number" min="1" name="goal_sessions" value="<?= $goalSessions ?>" style="width:110px"></div>
    <div><label>Ticków na sesję</label><input type="number" min="1" name="ticks_per_session" value="<?= $tps ?>" style="width:110px"></div>
    <button class="btn sm">Zapisz</button>
  </form>
  <p class="muted" style="margin-top:8px">Gracz ma osiągnąć zadany kapitał w limicie sesji od dołączenia. Sesja = dzień giełdowy (na otwarciu zapisuje się kurs odniesienia dla dziennej zmiany).</p>
  <h2 style="margin-top:18px">Rejestracja (graczy: <?= $playerCount ?>)</h2>
  <form method="post" class="row" style="align-items:flex-end">
    <input type="hidden" name="action" value="invite">
    <div><label>Kod zaproszenia <span class="muted">(puste = rejestracja otwarta)</span></label><input name="invite_code" value="<?= h($inviteCode) ?>" style="width:200px"></div>
    <button class="btn sm">Zapisz</button>
  </form>
</section>

<div class="gm-grid">
  <section class="panel">
    <h2>Nastawienie rynku (globalne)</h2>
    <p class="muted">Dryf wszystkich spółek w %/tick. Dodatnie = hossa, ujemne = bessa.</p>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="sentiment">
      <input type="number" step="0.05" name="sentiment" value="<?= $sentiment ?>" style="width:120px">
      <button class="btn sm">Ustaw</button>
    </form>
    <h2 style="margin-top:18px">Aktywność botów (<?= $botCount ?> na rynku)</h2>
    <p class="muted">0 = boty wyłączone · 1 = normalnie · 2–3 = agresywny handel.</p>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="botact">
      <input type="number" step="0.1" min="0" max="3" name="bot_activity" value="<?= rtrim(rtrim((string)$botact,'0'),'.') ?: '0' ?>" style="width:120px">
      <button class="btn sm">Ustaw</button>
    </form>
    <h2 style="margin-top:18px">💰 Skarbiec gry: <span class="up mono"><?= money($treasury) ?> PLN</span></h2>
    <p class="muted">Zebrane prowizje od obrotu (płaci sprzedający przy każdej transakcji — gracze i boty). Do wykorzystania na eventy / market making.
       Spółki wypłaciły dotąd <b class="mono"><?= money($divPaid) ?> PLN</b> dywidend (świeża gotówka w świecie gry).</p>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="fee">
      <label style="display:inline">Prowizja (% wartości):</label>
      <input type="number" step="0.05" min="0" max="5" name="fee_rate" value="<?= rtrim(rtrim((string)$feeRatePct,'0'),'.') ?: '0' ?>" style="width:90px">
      <button class="btn sm">Ustaw</button>
    </form>
  </section>

  <section class="panel">
    <h2>Silnik</h2>
    <p class="muted">Ręczne wykonanie cykli rynku (gdy nie chcesz czekać na cron).</p>
    <div class="row">
      <form method="post" class="inline"><input type="hidden" name="action" value="tick"><input type="hidden" name="n" value="1"><button class="btn sm">+1 tick</button></form>
      <form method="post" class="inline"><input type="hidden" name="action" value="tick"><input type="hidden" name="n" value="10"><button class="btn sm">+10 ticków</button></form>
      <form method="post" class="inline" onsubmit="return confirm('Zresetować bias/vol/nastawienie?')"><input type="hidden" name="action" value="reset"><button class="btn sm ghost">Reset sterowania</button></form>
    </div>
    <h2 style="margin-top:18px">⏱️ Tempo handlu</h2>
    <?php $srV = Engine::one("SELECT v FROM game_state WHERE k='sub_rounds'"); $sr = ($srV === false || $srV === null) ? 2 : (int) $srV; ?>
    <p class="muted" style="margin:4px 0 8px">Dodatkowe rundy botów między tickami crona (transakcje co ~18 s zamiast raz na minutę).
       Czas świata gry (sesje, raporty) płynie bez zmian. 0 = wyłączone.</p>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="tempo">
      <select name="sub_rounds" style="width:220px">
        <?php foreach ([0 => '0 — tylko tick co minutę', 1 => '1 runda (~co 30 s)', 2 => '2 rundy (~co 20 s)', 3 => '3 rundy (~co 15 s)'] as $v => $lbl): ?>
          <option value="<?= $v ?>" <?= $sr === $v ? 'selected' : '' ?>><?= $lbl ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn sm">Ustaw</button>
    </form>

    <h2 style="margin-top:18px">🩺 Zdrowie gry
      <?php if ($lastQa): ?>
        <span class="chg <?= $lastQa['level'] === 'info' ? 'p' : 'n' ?>" style="margin-left:6px"><?= $lastQa['level'] === 'info' ? 'OK' : 'BŁĘDY' ?></span>
      <?php endif; ?>
    </h2>
    <p class="muted" style="margin:4px 0 10px">
      <?php if ($lastQa): ?>Ostatni test QA: <?= h($lastQa['ts']) ?> — <?= h($lastQa['message']) ?>.<?php else: ?>QA jeszcze nie uruchomiony.<?php endif; ?>
      Błędów w dzienniku (24h): <b class="<?= $errors24 > 0 ? 'down' : 'up' ?>"><?= $errors24 ?></b>.
      QA-bot gra przez HTTP jak gracz i sprawdza księgowość co do grosza (auto co 30 ticków z crona).
    </p>
    <div class="row">
      <form method="post" class="inline"><input type="hidden" name="action" value="qa"><button class="btn sm">Testuj teraz (QA)</button></form>
      <a class="btn sm ghost" href="gm_logs.php">📜 Dziennik logów</a>
    </div>
  </section>
</div>

<section class="panel" style="margin-top:16px">
  <h2>🌪️ Wydarzenia specjalne</h2>
  <?php
    $evRaw = Engine::one("SELECT v FROM game_state WHERE k='events_enabled'");
    $evOn = ($evRaw === false || $evRaw === null) ? true : (int) $evRaw === 1;   // brak klucza = włączone
    $lastEv = Engine::row("SELECT ts, message FROM logs WHERE event LIKE 'event.%' ORDER BY id DESC LIMIT 1");
  ?>
  <p class="muted" style="margin:4px 0 10px">
    Krach/hossa uderzają w cały rynek (±12-15% rozłożone na ~20 ticków), wydarzenia sektorowe w jedną branżę.
    Wpływ zanika stopniowo — boty newsowe panikują/kupują, animatorzy podążają za fundamentem.
    <?php if ($lastEv): ?><br>Ostatnie: <b><?= h($lastEv['message']) ?></b> (<?= h($lastEv['ts']) ?>)<?php endif; ?>
  </p>
  <?php
    $cat = EventCatalog::all();
    $byScope = ['MARKET' => [], 'SECTOR' => [], 'COMPANY' => []];
    foreach ($cat as $code => $t) $byScope[$t['scope']][$code] = $t;
    $activeFx = Engine::all("SELECT ae.*, sec.name AS sname, st.ticker FROM active_effects ae
                             LEFT JOIN sectors sec ON ae.target_type='sector' AND sec.id=ae.target_id
                             LEFT JOIN stocks st ON ae.target_type='stock' AND st.id=ae.target_id
                             WHERE ae.expire_tick > (SELECT v FROM game_state WHERE k='tick') ORDER BY ae.expire_tick");
  ?>
  <div class="row" style="margin-bottom:10px;align-items:flex-end">
    <form method="post" class="inline" onsubmit="return confirm('Uruchomić wydarzenie RYNKOWE?')">
      <input type="hidden" name="action" value="world_event">
      <label>Rynkowe (globalne)</label>
      <select name="kind" style="width:250px"><?php foreach ($byScope['MARKET'] as $code => $t): ?><option value="<?= $code ?>"><?= h(mb_substr($t['head'], 0, 46)) ?></option><?php endforeach; ?></select>
      <button class="btn sm">Uruchom</button>
    </form>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="events_toggle">
      <input type="hidden" name="enabled" value="<?= $evOn ? '0' : '1' ?>">
      <button class="btn sm ghost"><?= $evOn ? '⏸ Wyłącz losowe' : '▶ Włącz losowe' ?></button>
    </form>
  </div>
  <form method="post" class="row" style="align-items:flex-end;margin-bottom:10px">
    <input type="hidden" name="action" value="sector_event">
    <div><label>Sektorowe</label>
      <select name="kind" style="width:250px"><?php foreach ($byScope['SECTOR'] as $code => $t): ?><option value="<?= $code ?>"><?= h(mb_substr($t['head'], 0, 46)) ?></option><?php endforeach; ?></select></div>
    <div><label>Sektor</label>
      <select name="sector_id" style="width:170px"><?php foreach ($sectors as $sec): ?><option value="<?= (int) $sec['id'] ?>"><?= h($sec['name']) ?></option><?php endforeach; ?></select></div>
    <button class="btn sm">Uruchom</button>
  </form>
  <form method="post" class="row" style="align-items:flex-end">
    <input type="hidden" name="action" value="company_event">
    <div><label>Spółkowe</label>
      <select name="kind" style="width:250px"><?php foreach ($byScope['COMPANY'] as $code => $t): ?><option value="<?= $code ?>"><?= h(mb_substr($t['head'], 0, 46)) ?></option><?php endforeach; ?></select></div>
    <div><label>Spółka</label>
      <select name="stock_id" style="width:170px"><?php foreach ($stocks as $st): ?><option value="<?= (int) $st['id'] ?>"><?= h($st['ticker']) ?></option><?php endforeach; ?></select></div>
    <button class="btn sm">Uruchom</button>
  </form>
  <?php if ($activeFx): ?>
    <p class="muted" style="margin:12px 0 4px"><b>Aktywne skutki wydarzeń</b> (same wygasną):</p>
    <div style="display:flex;gap:6px;flex-wrap:wrap">
      <?php foreach ($activeFx as $fx): ?>
        <span class="tag" title="źródło: <?= h($fx['source']) ?>"><?= h($fx['target_type'] === 'market' ? 'RYNEK' : ($fx['sname'] ?? $fx['ticker'] ?? '?')) ?>
          · <?= h($fx['field']) ?> <?= (float) $fx['delta'] >= 0 ? '+' : '' ?><?= rtrim(rtrim(number_format((float) $fx['delta'], 3, '.', ''), '0'), '.') ?>
          · do t<?= (int) $fx['expire_tick'] ?></span>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="panel" style="margin-top:16px">
  <h2>Sterowanie spółkami</h2>
  <div class="tbl-scroll">
  <table class="gm-table">
    <thead><tr><th>Spółka</th><th class="num">Kurs</th><th class="num">Trend (bias %/tick)</th><th class="num">Zmienność</th><th class="num">Zysk (trend %/mies)</th><th class="num">Dyw. (% zysku)</th><th>Zapisz</th><th>Event</th></tr></thead>
    <tbody>
    <?php foreach ($stocks as $s): ?>
      <tr>
        <td><b class="tick"><?= h($s['ticker']) ?></b> <span class="muted"><?= h($s['name']) ?></span></td>
        <td class="num"><?= money($s['price']) ?></td>
        <form method="post">
          <input type="hidden" name="action" value="stock">
          <input type="hidden" name="stock_id" value="<?= (int) $s['id'] ?>">
          <td class="num"><input type="number" step="0.05" name="bias" value="<?= $fmt($s['bias']) ?>" style="width:78px"></td>
          <td class="num"><input type="number" step="0.1" min="0.1" name="vol" value="<?= $fmt($s['volatility']) ?>" style="width:66px"></td>
          <td class="num"><input type="number" step="0.5" name="profit_trend" value="<?= $fmt($s['profit_trend']) ?>" style="width:80px"></td>
          <td class="num"><input type="number" step="5" min="0" max="80" name="dividend" value="<?= rtrim(rtrim(number_format((float) ($s['dividend_payout'] ?? 0) * 100, 1, '.', ''), '0'), '.') ?: '0' ?>" style="width:64px"></td>
          <td><button class="btn sm">Zapisz</button></td>
        </form>
        <td class="events">
          <form method="post" class="inline"><input type="hidden" name="action" value="event"><input type="hidden" name="stock_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="pct" value="5"><button class="btn sm up">▲ +5%</button></form>
          <form method="post" class="inline"><input type="hidden" name="action" value="event"><input type="hidden" name="stock_id" value="<?= (int) $s['id'] ?>"><input type="hidden" name="pct" value="-5"><button class="btn sm down">▼ −5%</button></form>
          <form method="post" class="inline"><input type="hidden" name="action" value="report"><input type="hidden" name="stock_id" value="<?= (int) $s['id'] ?>"><button class="btn sm ghost">Raport</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted" style="margin-top:10px"><b>Trend</b> to stały dryf ceny fundamentalnej, za którą podąża market maker. <b>Zmienność</b> skaluje szum i siłę newsów. <b>Zysk (trend)</b> to miernik miesięcznej poprawy/pogorszenia wyników spółki. <b>Event</b> to jednorazowy skok + tick.</p>
</section>

<section class="panel" style="margin-top:16px">
  <h2>Sterowanie sektorami (branże)</h2>
  <div class="tbl-scroll">
  <table class="gm-table">
    <thead><tr><th>Sektor</th><th class="num">Trend branży (%/tick)</th><th class="num">Koniunktura wyników (%/mies)</th><th>Zapisz</th></tr></thead>
    <tbody>
    <?php foreach ($sectors as $sec): ?>
      <tr>
        <form method="post">
          <input type="hidden" name="action" value="sector">
          <input type="hidden" name="sector_id" value="<?= (int) $sec['id'] ?>">
          <td><b class="tick"><?= h($sec['symbol']) ?></b> <span class="muted"><?= h($sec['name']) ?></span></td>
          <td class="num"><input type="number" step="0.05" name="trend" value="<?= $fmt($sec['trend']) ?>" style="width:90px"></td>
          <td class="num"><input type="number" step="0.5" name="profit_climate" value="<?= $fmt($sec['profit_climate']) ?>" style="width:90px"></td>
          <td><button class="btn sm">Zapisz</button></td>
        </form>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p class="muted" style="margin-top:10px"><b>Trend branży</b> to dryf ceny fundamentalnej całego sektora (×beta sektora). <b>Koniunktura wyników</b> przesuwa raporty miesięczne wszystkich spółek z tej branży (poprawa/pogorszenie zysków całej gałęzi).</p>
</section>
<?php layout_footer();
