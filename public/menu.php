<?php
/** Konto: profil, premium (Tokeny inwestora), ustawienia konta i pomoc. Grywalność jest w Rynku, Portfelu i Lidze — nie tutaj. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

$me = Engine::row("SELECT username, title, frame, tokens FROM users WHERE id=?", [$uid]);
$equity = user_equity($uid);
$unread = (int) Engine::one("SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL", [$uid]);
$isAdmin = ($user['role'] ?? '') === 'admin';

layout_header('Konto', $user, 'more');
?>
<div class="page-head"><h1>Konto</h1></div>

<section class="panel" style="margin-bottom:14px">
  <a href="gracz.php?id=<?= $uid ?>" style="display:flex;align-items:center;gap:14px">
    <span class="avatar<?= in_array($me['frame'], ['gold', 'silver'], true) ? ' frame-' . h($me['frame']) : '' ?>"><?= mb_strtoupper(mb_substr($me['username'], 0, 1)) ?></span>
    <span style="flex:1;min-width:0">
      <b style="font-size:16px"><?= h($me['username']) ?></b>
      <?php if (trim((string) $me['title']) !== ''): ?><span class="tag" style="color:var(--gold);border-color:var(--gold-border)"><?= h($me['title']) ?></span><?php endif; ?>
      <span class="muted" style="display:block;font-size:12.5px">kapitał: <b class="num" style="color:var(--ink)"><?= money($equity) ?> PLN</b> · 🪙 <?= (int) $me['tokens'] ?> Tokenów</span>
    </span>
    <span class="arr" style="color:var(--faint)">›</span>
  </a>
</section>

<section class="panel menu-list" style="margin-bottom:14px">
  <?php if ($user['role'] === 'player'): ?>
  <a href="sklep.php"><?= icon('shop') ?>Tokeny inwestora — premium i doładowania<span class="sub">🪙 <?= (int) $me['tokens'] ?></span><span class="arr">›</span></a>
  <?php endif; ?>
  <a href="konto.php"><?= icon('user') ?>Ustawienia konta — e-mail i hasło<span class="arr">›</span></a>
  <a href="powiadomienia.php"><?= icon('bell') ?>Powiadomienia<?= $unread > 0 ? "<span class='sub' style='color:var(--down);font-weight:700'>$unread</span>" : '' ?><span class="arr">›</span></a>
  <a href="powiadomienia.php#push"><?= icon('bell') ?>Powiadomienia push na tym urządzeniu<span class="sub">stopy, dywidendy, ligi — także przy zamkniętej grze</span><span class="arr">›</span></a>
  <a href="dziennik.php"><?= icon('book') ?>Dziennik konta<span class="arr">›</span></a>
  <a href="historia.php"><?= icon('book') ?>Historia konta — wpływy i wydatki<span class="arr">›</span></a>
</section>

<section class="panel menu-list" style="margin-bottom:14px">
  <a href="samouczek.php"><?= icon('help') ?>Samouczek — jak grać<span class="arr">›</span></a>
  <a href="pomoc.php"><?= icon('help') ?>Pomoc i infografiki<span class="arr">›</span></a>
  <button onclick="return themeToggle()"><?= icon('theme') ?>Przełącz motyw jasny / ciemny</button>
  <button data-pwa-install style="display:none" onclick="return pwaInstall()"><?= icon('home') ?>📲 Zainstaluj Maklerię jak aplikację<span class="sub">ikona na ekranie, pełny ekran, szybki start</span></button>
  <a href="pomoc.php#pwa"><?= icon('help') ?>Makleria na telefonie — jak zainstalować<span class="arr">›</span></a>
  <?php if ($isAdmin): ?><a href="gm.php" style="color:var(--gold)"><?= icon('gear') ?>Panel GM<span class="arr">›</span></a><?php endif; ?>
</section>

<section class="panel menu-list">
  <a href="logout.php" class="danger"><?= icon('exit') ?>Wyloguj się</a>
</section>
<?php layout_footer();
