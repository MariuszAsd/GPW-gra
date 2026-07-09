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
    } elseif ($a === 'stock') {
        $pdo->prepare("UPDATE stocks SET bias=?, volatility=?, profit_trend=? WHERE id=?")->execute([
            (float) str_replace(',', '.', $_POST['bias'] ?? '0'),
            max(0.1, (float) str_replace(',', '.', $_POST['vol'] ?? '1')),
            (float) str_replace(',', '.', $_POST['profit_trend'] ?? '0'),
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
        for ($i = 0; $i < $n; $i++) Engine::runTick();
        flash("Wykonano {$n} ticków.");
    } elseif ($a === 'reset') {
        $pdo->exec("UPDATE stocks SET bias=0, volatility=1, profit_trend=0");
        $pdo->exec("UPDATE sectors SET trend=0, profit_climate=0");
        Engine::setState('sentiment', '0');
        flash('Zresetowano sterowanie.');
    }
    redirect('gm.php');
}

$sentiment = (float) (Engine::one("SELECT v FROM game_state WHERE k='sentiment'") ?: 0);
$tick = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
$stocks = Engine::all("SELECT * FROM stocks ORDER BY ticker");
$sectors = Engine::all("SELECT * FROM sectors ORDER BY symbol");
$fmt = fn($v) => rtrim(rtrim((string) $v, '0'), '.') ?: '0';

layout_header('Panel GM', $user, 'gm');
?>
<div class="page-head">
  <h1>Panel GM — sterowanie rynkiem</h1>
  <span class="muted">tick #<?= $tick ?></span>
</div>

<div class="gm-grid">
  <section class="panel">
    <h2>Nastawienie rynku (globalne)</h2>
    <p class="muted">Dryf wszystkich spółek w %/tick. Dodatnie = hossa, ujemne = bessa.</p>
    <form method="post" class="inline">
      <input type="hidden" name="action" value="sentiment">
      <input type="number" step="0.05" name="sentiment" value="<?= $sentiment ?>" style="width:120px">
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
  </section>
</div>

<section class="panel" style="margin-top:16px">
  <h2>Sterowanie spółkami</h2>
  <div class="tbl-scroll">
  <table class="gm-table">
    <thead><tr><th>Spółka</th><th class="num">Kurs</th><th class="num">Trend (bias %/tick)</th><th class="num">Zmienność</th><th class="num">Zysk (trend %/mies)</th><th>Zapisz</th><th>Event</th></tr></thead>
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
