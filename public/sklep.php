<?php
/** Sklep: Żetony Maklera i pakiety premium. Zasada: kupujesz INFORMACJĘ i wygodę, nigdy PLN w grze. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_pass'])) {
    [$ok, $msg] = Tokens::buyPass($uid, (string) $_POST['buy_pass']);
    flash($msg, $ok ? 'ok' : 'err');
    redirect('sklep.php');
}

$balance = Tokens::balance($uid);
$ledger = Engine::all("SELECT * FROM token_ledger WHERE user_id=? ORDER BY id DESC LIMIT 15", [$uid]);
[$session] = Engine::sessionInfo();

layout_header('Sklep', $user, '');
?>
<div class="page-head">
  <h1>Żetony Maklera</h1>
  <span class="muted">premium bez psucia gry — żetonów nie wymienisz na PLN, kupują informację i wygodę</span>
</div>

<div class="stats" style="grid-template-columns:repeat(2,1fr);max-width:560px">
  <div class="stat"><div class="k">Twoje saldo</div><div class="v" style="color:var(--gold)">🪙 <?= $balance ?></div></div>
  <div class="stat"><div class="k">Jak zdobywać</div><div class="v" style="font-size:13px;font-weight:500;line-height:1.5;letter-spacing:0">+10 na start · +2 za odznakę<br>+10 wygrana / +5 podium wyzwania</div></div>
</div>

<section class="panel" style="margin-bottom:16px">
  <h2>Pakiety premium</h2>
  <?php foreach (Tokens::PASSES as $kind => [$days, $price, $name, $desc]): $until = Tokens::passUntil($uid, $kind); ?>
    <div style="display:flex;gap:16px;align-items:center;flex-wrap:wrap;padding:14px;border:1px solid var(--line);border-radius:12px;margin-bottom:10px<?= $until ? ';border-color:var(--up-border);background:var(--up-bg)' : '' ?>">
      <div style="flex:1;min-width:260px">
        <b style="font-size:15px"><?= h($name) ?></b>
        <?php if ($until): ?><span class="chg p" style="margin-left:8px">aktywny do sesji #<?= $until ?></span><?php endif; ?>
        <p class="muted" style="margin:5px 0 0;font-size:13px"><?= h($desc) ?></p>
      </div>
      <form method="post" style="min-width:180px">
        <button class="btn sm" name="buy_pass" value="<?= h($kind) ?>" <?= $balance < $price ? 'disabled title="Za mało żetonów"' : '' ?>>
          <?= $until ? 'Przedłuż' : 'Aktywuj' ?>: <?= $days ?> dni — 🪙 <?= $price ?>
        </button>
      </form>
    </div>
  <?php endforeach; ?>
  <p class="muted" style="font-size:12.5px;margin:8px 0 0">Pakiet liczony w sesjach giełdowych (1 sesja = 1 dzień handlu). Przedłużenie dokleja dni do końca obecnego pakietu.</p>
</section>

<section class="panel" style="margin-bottom:16px">
  <h2>Doładowanie żetonów</h2>
  <div class="ch-grid">
    <div class="ch-stat"><small>PAKIET STARTOWY</small><b>🪙 20</b><span class="muted" style="font-size:12px;display:block">9,99 zł</span></div>
    <div class="ch-stat"><small>PAKIET INWESTORA</small><b>🪙 55</b><span class="muted" style="font-size:12px;display:block">19,99 zł · +10% gratis</span></div>
    <div class="ch-stat"><small>PAKIET REKINA</small><b>🪙 150</b><span class="muted" style="font-size:12px;display:block">49,99 zł · +25% gratis</span></div>
  </div>
  <p class="muted" style="margin:10px 0 0">Płatności online <b>wkrótce</b> — na czas testów żetony przyznaje administrator gry (napisz na czacie).</p>
</section>

<?php if ($ledger): ?>
<section class="panel">
  <h2>Historia operacji</h2>
  <table>
    <thead><tr><th>Kiedy</th><th>Operacja</th><th class="num">Zmiana</th><th class="num">Saldo</th></tr></thead>
    <tbody>
    <?php foreach ($ledger as $l): ?>
      <tr>
        <td class="mono muted" style="white-space:nowrap"><?= h($l['created_at']) ?></td>
        <td><?= h($l['note'] ?: $l['reason']) ?></td>
        <td class="num"><span class="<?= $l['delta'] >= 0 ? 'up' : 'down' ?>"><?= ($l['delta'] >= 0 ? '+' : '') . (int) $l['delta'] ?></span></td>
        <td class="num muted"><?= (int) $l['balance'] ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</section>
<?php endif; ?>
<?php layout_footer();