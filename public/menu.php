<?php
/** Więcej: mobilne menu (5. zakładka dolnej nawigacji) — profil, pozostałe działy, motyw, wyjście. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

$me = Engine::row("SELECT username, title, frame, tokens FROM users WHERE id=?", [$uid]);
$stockVal = (float) (Engine::one("SELECT COALESCE(SUM((w.qty + w.qty_reserved) * s.price), 0) FROM wallets w JOIN stocks s ON s.id=w.stock_id WHERE w.user_id=?", [$uid]) ?: 0);
$equity = (float) $user['cash'] + (float) $user['cash_reserved'] + $stockVal + Engine::lockedFunds($uid);
$unread = (int) Engine::one("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid]);
$isAdmin = ($user['role'] ?? '') === 'admin';
$seasonOn = (bool) Seasons::active();

layout_header('Więcej', $user, 'more');
?>
<div class="page-head"><h1>Więcej</h1></div>

<section class="panel" style="margin-bottom:14px">
  <a href="gracz.php?id=<?= $uid ?>" style="display:flex;align-items:center;gap:14px">
    <span class="avatar<?= in_array($me['frame'], ['gold', 'silver'], true) ? ' frame-' . h($me['frame']) : '' ?>"><?= mb_strtoupper(mb_substr($me['username'], 0, 1)) ?></span>
    <span style="flex:1;min-width:0">
      <b style="font-size:16px"><?= h($me['username']) ?></b>
      <?php if (trim((string) $me['title']) !== ''): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)"><?= h($me['title']) ?></span><?php endif; ?>
      <span class="muted" style="display:block;font-size:12.5px">kapitał: <b class="num" style="color:var(--ink)"><?= money($equity) ?> PLN</b> · 🪙 <?= (int) $me['tokens'] ?></span>
    </span>
    <span class="arr" style="color:var(--faint)">›</span>
  </a>
</section>

<section class="panel menu-list" style="margin-bottom:14px">
  <a href="ranking.php"><?= icon('trophy') ?>Ranking<span class="arr">›</span></a>
  <a href="wiadomosci.php"><?= icon('news') ?>Newsy i ESPI<span class="arr">›</span></a>
  <a href="branze.php"><?= icon('chart') ?>Trendy branżowe<span class="arr">›</span></a>
  <a href="rekomendacje.php"><?= icon('case') ?>Rekomendacje i skaner AT<span class="arr">›</span></a>
  <a href="ipo.php"><?= icon('shop') ?>Oferty publiczne (IPO)<span class="arr">›</span></a>
  <a href="sklep.php"><?= icon('shop') ?>Sklep — Żetony Maklera<span class="sub">🪙 <?= (int) $me['tokens'] ?></span><span class="arr">›</span></a>
  <?php if ($seasonOn): ?><a href="sezon.php"><?= icon('flag') ?>Sezon i karnet<span class="arr">›</span></a><?php endif; ?>
  <a href="powiadomienia.php"><?= icon('bell') ?>Powiadomienia<?= $unread > 0 ? "<span class='sub' style='color:var(--down);font-weight:700'>$unread</span>" : '' ?><span class="arr">›</span></a>
  <a href="dziennik.php"><?= icon('book') ?>Dziennik konta<span class="arr">›</span></a>
</section>

<section class="panel menu-list" style="margin-bottom:14px">
  <a href="konto.php"><?= icon('user') ?>Ustawienia konta — e-mail i hasło<span class="arr">›</span></a>
  <a href="samouczek.php"><?= icon('help') ?>Samouczek<span class="arr">›</span></a>
  <a href="pomoc.php"><?= icon('help') ?>Pomoc i infografiki<span class="arr">›</span></a>
  <button onclick="return themeToggle()"><?= icon('theme') ?>Przełącz motyw jasny / ciemny</button>
  <?php if ($isAdmin): ?><a href="gm.php" style="color:var(--gold)"><?= icon('gear') ?>Panel GM<span class="arr">›</span></a><?php endif; ?>
</section>

<section class="panel menu-list">
  <a href="logout.php" class="danger"><?= icon('exit') ?>Wyloguj się</a>
</section>
<?php layout_footer();
