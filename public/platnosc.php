<?php
/** Powrót z bramki płatności (continueUrl): status zamówienia + saldo. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

$oid = (int) ($_GET['o'] ?? 0);
Payments::expireStale($uid);   // zamówienia bez końcowego statusu od operatora > 24 h = wygasłe (nie wiszą jako „przetwarzamy”)
$o = Engine::row("SELECT * FROM payment_orders WHERE id=? AND user_id=?", [$oid, $uid]);
$balance = Tokens::balance($uid);
// auto-odświeżanie ma limit prób: po ~70 s bez statusu końcowego gracz dostaje jasny komunikat zamiast pętli w nieskończoność
$tries = max(0, min(99, (int) ($_GET['r'] ?? 0)));
$maxTries = 10;

layout_header('Płatność', $user, '');
?>
<div class="page-head"><h1>Płatność</h1></div>

<section class="panel" style="max-width:560px;text-align:center;padding:28px">
  <?php if (!$o): ?>
    <p class="muted">Nie znaleziono zamówienia. Jeśli płatność przeszła, tokeny i tak wpadną na konto — sprawdź saldo za minutę.</p>
  <?php elseif ($o['status'] === 'completed'): ?>
    <div style="font-size:40px">✅</div>
    <h2 style="margin:8px 0 4px">Tokeny przyznane!</h2>
    <p class="muted" style="margin:0 0 8px"><?= h(Payments::PACKAGES[$o['package']][2] ?? $o['package']) ?> — 🪙 <?= (int) $o['tokens'] ?> Tokenów inwestora dopisane do konta.</p>
  <?php elseif ($o['status'] === 'cancelled'): ?>
    <div style="font-size:40px">✕</div>
    <h2 style="margin:8px 0 4px">Płatność anulowana lub odrzucona</h2>
    <p class="muted" style="margin:0 0 8px">Nic nie zostało pobrane. Możesz spróbować ponownie w sekcji Tokeny inwestora.</p>
  <?php elseif ($o['status'] === 'expired'): ?>
    <div style="font-size:40px">⌛</div>
    <h2 style="margin:8px 0 4px">Zamówienie wygasło</h2>
    <p class="muted" style="margin:0 0 8px">Operator nie potwierdził tej płatności w ciągu doby. Jeśli pieniądze zeszły z Twojego konta, napisz do administratora — tokeny zostaną dopisane po weryfikacji.</p>
  <?php elseif ($tries >= $maxTries): ?>
    <div style="font-size:40px">⏳</div>
    <h2 style="margin:8px 0 4px">Operator wciąż nie potwierdził płatności</h2>
    <p class="muted" style="margin:0 0 8px">To trwa dłużej niż zwykle. Jeśli płatność przeszła, tokeny wpadną na konto automatycznie — sprawdź saldo za kilka minut.
      Jeśli pieniądze zeszły, a tokenów nie ma po godzinie, napisz do administratora (zamówienie #<?= (int) $o['id'] ?>).</p>
    <a class="btn sm ghost" href="platnosc.php?o=<?= (int) $o['id'] ?>">Sprawdź ponownie</a>
  <?php else: ?>
    <div style="font-size:40px">⏳</div>
    <h2 style="margin:8px 0 4px">Przetwarzamy płatność…</h2>
    <p class="muted" style="margin:0 0 8px">Operator potwierdza wpłatę — tokeny wpadną na konto zwykle w ciągu minuty. Ta strona odświeży się sama.</p>
    <script>setTimeout(() => location.replace('platnosc.php?o=<?= (int) $o['id'] ?>&r=<?= $tries + 1 ?>'), 7000);</script>
  <?php endif; ?>
  <p style="margin:14px 0 0">Twoje saldo: <b style="color:var(--gold)">🪙 <?= $balance ?></b></p>
  <a class="btn sm" style="margin-top:14px;display:inline-block" href="sklep.php">← Wróć do Tokenów</a>
</section>
<?php layout_footer();
