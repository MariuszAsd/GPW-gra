<?php
/** Sklep: Żetony Maklera, pakiety premium, kosmetyka i doładowania. Zasada: kupujesz INFORMACJĘ i wygląd, nigdy PLN w grze. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['buy_pass'])) {
        [$ok, $msg] = Tokens::buyPass($uid, (string) $_POST['buy_pass']);
        flash($msg, $ok ? 'ok' : 'err');
    } elseif (isset($_POST['buy_tokens'])) {
        [$ok, $res] = Payments::createOrder($uid, (string) $_POST['buy_tokens'], (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ok) { header("Location: $res"); exit; }   // przekierowanie do bramki (BLIK/karta u operatora)
        flash($res, 'err');
    } elseif (isset($_POST['buy_item'])) {
        [$ok, $msg] = Cosmetics::buy($uid, (string) $_POST['buy_item']);
        flash($msg, $ok ? 'ok' : 'err');
    } elseif (isset($_POST['equip_item'])) {
        [$ok, $msg] = Cosmetics::equip($uid, (string) $_POST['equip_item'], (string) ($_POST['clear_type'] ?? ''));
        flash($msg, $ok ? 'ok' : 'err');
    }
    redirect('sklep.php');
}

$balance = Tokens::balance($uid);
$ledger = Engine::all("SELECT * FROM token_ledger WHERE user_id=? ORDER BY id DESC LIMIT 15", [$uid]);
[$session] = Engine::sessionInfo();
$owned = Cosmetics::owned($uid);
$me = Engine::row("SELECT title, chat_color, frame FROM users WHERE id=?", [$uid]);
$payOn = Payments::enabled();

layout_header('Sklep', $user, '');
?>
<div class="page-head">
  <h1>Żetony Maklera</h1>
  <span class="muted">premium bez psucia gry — żetonów nie wymienisz na PLN, kupują informację i wygląd</span>
</div>

<div class="stats" style="grid-template-columns:repeat(2,1fr);max-width:560px">
  <div class="stat"><div class="k">Twoje saldo</div><div class="v" style="color:var(--gold)">🪙 <?= $balance ?></div></div>
  <div class="stat"><div class="k">Jak zdobywać</div><div class="v" style="font-size:13px;font-weight:500;line-height:1.5;letter-spacing:0">+10 na start · +2 za odznakę<br>+10 wygrana / +5 podium wyzwania<br>+ progi sezonu (karnet w Sezonie)</div></div>
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
  <h2>Kosmetyka <span class="muted" style="text-transform:none;letter-spacing:0;font-size:12px">— tytuł w rankingu, kolor nicka na czacie, ramka profilu (bez wpływu na grę)</span></h2>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px;margin-top:10px">
    <?php foreach (Cosmetics::ITEMS as $key => [$type, $price, $name, $value]):
        $have = in_array($key, $owned, true);
        $on = $have && (string) $me[$type] === (string) $value;
        if ($price <= 0 && !$have) continue;   // nagrody specjalne widać dopiero po zdobyciu ?>
      <div style="border:1px solid var(--line);border-radius:10px;padding:11px 12px<?= $on ? ';border-color:var(--up-border);background:var(--up-bg)' : '' ?>">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
          <?php if ($type === 'chat_color'): ?><span style="width:14px;height:14px;border-radius:50%;background:<?= h($value) ?>;display:inline-block"></span><?php endif; ?>
          <?php if ($type === 'frame'): ?><span class="avatar sm frame-<?= h($value) ?>"><?= mb_strtoupper(mb_substr($user['username'], 0, 1)) ?></span><?php endif; ?>
          <?php if ($type === 'title'): ?><span style="font-size:15px">🏷️</span><?php endif; ?>
          <b style="font-size:13px;flex:1"><?= h($name) ?></b>
        </div>
        <?php if ($on): ?>
          <form method="post" class="inline"><input type="hidden" name="equip_item" value=""><input type="hidden" name="clear_type" value="<?= h($type) ?>"><button class="btn sm ghost">✓ Założone — zdejmij</button></form>
        <?php elseif ($have): ?>
          <form method="post" class="inline"><button class="btn sm ghost" name="equip_item" value="<?= h($key) ?>">Załóż</button></form>
        <?php else: ?>
          <form method="post" class="inline"><button class="btn sm" name="buy_item" value="<?= h($key) ?>" <?= $balance < $price ? 'disabled title="Za mało żetonów"' : '' ?>>Kup — 🪙 <?= $price ?></button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<section class="panel" style="margin-bottom:16px">
  <h2>Doładowanie żetonów</h2>
  <div class="ch-grid">
    <?php foreach (Payments::PACKAGES as $pkg => [$tk, $grosz, $name, $bonus]): ?>
      <div class="ch-stat">
        <small><?= h(mb_strtoupper($name)) ?></small><b>🪙 <?= $tk ?></b>
        <span class="muted" style="font-size:12px;display:block"><?= number_format($grosz / 100, 2, ',', ' ') ?> zł<?= $bonus !== '' ? ' · ' . h($bonus) : '' ?></span>
        <?php if ($payOn): ?>
          <form method="post" style="margin-top:8px"><button class="btn sm" name="buy_tokens" value="<?= h($pkg) ?>">Kup (BLIK / karta)</button></form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($payOn): ?>
    <p class="muted" style="margin:10px 0 0">Płatność obsługuje operator (BLIK, karta, przelew). Żetony wpadają na konto automatycznie w ciągu minuty od opłacenia.</p>
  <?php else: ?>
    <p class="muted" style="margin:10px 0 0">Płatności online <b>wkrótce</b> — na czas testów żetony przyznaje administrator gry (napisz na czacie).</p>
  <?php endif; ?>
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
