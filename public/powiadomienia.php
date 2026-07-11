<?php
/** Powiadomienia gracza: dywidendy, SL/TP, raporty spółek z portfela, realizacje zleceń, cel gry. */
require __DIR__ . '/_boot.php';
$user = require_login();

$rows = Engine::all("SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 50", [$user['id']]);
Db::pdo()->prepare("UPDATE notifications SET read_at=? WHERE user_id=? AND read_at IS NULL")->execute([Db::now(), $user['id']]);

$icon = ['dividend' => '💰', 'stop' => '🛡️', 'report' => '📊', 'order' => '✅', 'goal' => '🏆', 'event' => '🌪️', 'system' => 'ℹ️'];
layout_header('Powiadomienia', $user, 'notif');
?>
<div class="page-head"><h1>Powiadomienia</h1><span class="muted">50 ostatnich · nieprzeczytane są podświetlone</span></div>

<div class="panel" style="padding:0;overflow:hidden">
  <?php foreach ($rows as $r): $fresh = $r['read_at'] === null; ?>
    <a href="<?= h($r['link'] ?: 'portfolio.php') ?>" style="display:flex;gap:12px;align-items:flex-start;padding:13px 16px;border-bottom:1px solid var(--line);<?= $fresh ? 'background:var(--info-bg)' : '' ?>">
      <span style="font-size:18px;line-height:1.3"><?= $icon[$r['type']] ?? 'ℹ️' ?></span>
      <span style="flex:1">
        <span style="display:block;font-size:14px;<?= $fresh ? 'font-weight:600' : '' ?>"><?= h($r['message']) ?></span>
        <span class="muted mono" style="font-size:11px"><?= h($r['created_at']) ?></span>
      </span>
      <?php if ($fresh): ?><span class="chg p" style="font-size:10px">NOWE</span><?php endif; ?>
    </a>
  <?php endforeach; if (!$rows) echo "<p class='muted' style='padding:22px'>Cisza w eterze — powiadomienia pojawią się, gdy coś się wydarzy: dywidenda, raport Twojej spółki, realizacja zlecenia, SL/TP…</p>"; ?>
</div>
<?php layout_footer();
