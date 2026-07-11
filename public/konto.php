<?php
/** Ustawienia konta: e-mail (odzyskiwanie hasła) i zmiana hasła. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $me = Engine::row("SELECT password_hash FROM users WHERE id=?", [$uid]);
    if (!password_verify((string) ($_POST['current'] ?? ''), $me['password_hash'])) {
        flash('Błędne obecne hasło.', 'err');
        redirect('konto.php');
    }
    if (isset($_POST['email'])) {
        $email = mb_strtolower(trim((string) $_POST['email']));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('To nie wygląda na poprawny adres e-mail.', 'err');
        } else {
            try {
                Db::pdo()->prepare("UPDATE users SET email=? WHERE id=?")->execute([$email !== '' ? $email : null, $uid]);
                Engine::journal($uid, 'system', $email !== '' ? '✉️ Ustawiono e-mail do odzyskiwania hasła.' : '✉️ Usunięto e-mail z konta.');
                flash($email !== '' ? 'E-mail zapisany — od teraz możesz odzyskać hasło.' : 'E-mail usunięty.', 'ok');
            } catch (Throwable $e) {
                flash('Ten e-mail jest już przypisany do innego konta.', 'err');
            }
        }
    } elseif (isset($_POST['password'])) {
        $p1 = (string) $_POST['password']; $p2 = (string) ($_POST['password2'] ?? '');
        if (strlen($p1) < 6) flash('Nowe hasło musi mieć co najmniej 6 znaków.', 'err');
        elseif ($p1 !== $p2) flash('Nowe hasła nie są identyczne.', 'err');
        else {
            Db::pdo()->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([password_hash($p1, PASSWORD_DEFAULT), $uid]);
            Engine::journal($uid, 'system', '🔑 Hasło zostało zmienione.');
            Log::write('info', 'auth', 'password.change', "zmiana hasła #$uid");
            flash('Hasło zmienione.', 'ok');
        }
    }
    redirect('konto.php');
}

$email = (string) (Engine::one("SELECT email FROM users WHERE id=?", [$uid]) ?: '');
layout_header('Ustawienia konta', $user, 'more');
?>
<div class="page-head">
  <h1>Ustawienia konta</h1>
  <a class="btn sm ghost" style="margin-left:auto" href="menu.php">← Więcej</a>
</div>

<section class="panel" style="max-width:520px;margin-bottom:14px">
  <h2>E-mail do odzyskiwania hasła</h2>
  <?php if ($email === ''): ?>
    <p class="flash info" style="margin:0 0 12px">Bez e-maila nie odzyskasz hasła, gdy je zapomnisz — warto podać. Używamy go wyłącznie do resetu hasła.</p>
  <?php endif; ?>
  <form method="post">
    <label for="email">E-mail <span class="muted">(puste pole = usuń)</span></label>
    <input id="email" name="email" type="email" value="<?= h($email) ?>" placeholder="twoj@email.pl">
    <label for="cur1">Obecne hasło (potwierdzenie)</label>
    <input id="cur1" name="current" type="password" required>
    <button class="btn" style="margin-top:12px">Zapisz e-mail</button>
  </form>
</section>

<section class="panel" style="max-width:520px">
  <h2>Zmiana hasła</h2>
  <form method="post">
    <label for="cur2">Obecne hasło</label>
    <input id="cur2" name="current" type="password" required>
    <label for="password">Nowe hasło (min. 6 znaków)</label>
    <input id="password" name="password" type="password" required minlength="6">
    <label for="password2">Powtórz nowe hasło</label>
    <input id="password2" name="password2" type="password" required minlength="6">
    <button class="btn" style="margin-top:12px">Zmień hasło</button>
  </form>
</section>
<?php layout_footer();
