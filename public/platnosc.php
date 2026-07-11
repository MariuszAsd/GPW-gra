<?php
/** Powrót z bramki płatności (continueUrl): status zamówienia + saldo. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

$oid = (int) ($_GET['o'] ?? 0);
$o = Engine::row("SELECT * FROM payment_orders WHERE id=? AND user_id=?", [$oid, $uid]);
$balance = Tokens::balance($uid);

layout_header('Płatność', $user, '');
?>
<div class="page-head"><h1>Płatność</h1></div>

<section class="panel" style="max-width:560px;text-align:center;padding:28px">
  <?php if (!$o): ?>
    <p class="muted">Nie znaleziono zamówienia. Jeśli płatność przeszła, żetony i tak wpadną na konto — sprawdź saldo za minutę.</p>
  <?php elseif ($o['status'] === 'completed'): ?>
    <div style="font-size:40px">✅</div>
    <h2 style="margin:8px 0 4px">Żetony przyznane!</h2>
    <p class="muted" style="margin:0 0 8px"><?= h(Payments::PACKAGES[$o['package']][2] ?? $o['package']) ?> — 🪙 <?= (int) $o['tokens'] ?> Żetonów Maklera dopisane do konta.</p>
  <?php elseif ($o['status'] === 'cancelled'): ?>
    <div style="font-size:40px">✕</div>
    <h2 style="margin:8px 0 4px">Płatność anulowana</h2>
    <p class="muted" style="margin:0 0 8px">Nic nie zostało pobrane. Możesz spróbować ponownie w Sklepie.</p>
  <?php else: ?>
    <div style="font-size:40px">⏳</div>
    <h2 style="margin:8px 0 4px">Przetwarzamy płatność…</h2>
    <p class="muted" style="margin:0 0 8px">Operator potwierdza wpłatę — żetony wpadną na konto zwykle w ciągu minuty. Ta strona odświeży się sama.</p>
    <script>setTimeout(() => location.reload(), 7000);</script>
  <?php endif; ?>
  <p style="margin:14px 0 0">Twoje saldo: <b style="color:var(--gold)">🪙 <?= $balance ?></b></p>
  <a class="btn sm" style="margin-top:14px;display:inline-block" href="sklep.php">← Wróć do Sklepu</a>
</section>
<?php layout_footer();
