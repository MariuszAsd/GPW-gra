<?php
/** Reset hasła: prośba o link (e-mail) i ustawienie nowego hasła tokenem z maila. */
require __DIR__ . '/_boot.php';
if (current_user()) redirect('konto.php');

$cfg = require __DIR__ . '/../config.php';
$token = trim((string) ($_GET['t'] ?? $_POST['t'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['email'])) {
        [$ok, $tok, $uid] = PasswordReset::request((string) $_POST['email']);
        if ($ok && $tok !== null && $uid !== null) {
            $base = rtrim((string) ($cfg['app_url'] ?? ''), '/');
            $link = "$base/reset.php?t=$tok";
            $name = (string) Engine::one("SELECT username FROM users WHERE id=?", [$uid]);
            Mailer::send((string) $_POST['email'], 'Makleria — reset hasła',
                "Cześć $name!\n\nKtoś (mamy nadzieję, że Ty) poprosił o reset hasła w Maklerii.\n"
                . "Ustaw nowe hasło klikając w link (ważny " . PasswordReset::TTL_MIN . " minut):\n\n$link\n\n"
                . "Jeśli to nie Ty — zignoruj tę wiadomość, hasło pozostaje bez zmian.\n\n— Makleria");
        }
        // zawsze ta sama odpowiedź — nie zdradzamy, czy konto istnieje
        flash('Jeśli konto z tym adresem istnieje, wysłaliśmy link do resetu (sprawdź też spam).', 'ok');
        redirect('reset.php');
    }
    if (isset($_POST['password'])) {
        if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) {
            flash('Hasła nie są identyczne.', 'err');
            redirect('reset.php?t=' . urlencode($token));
        }
        [$ok, $msg] = PasswordReset::consume($token, (string) $_POST['password']);
        flash($msg, $ok ? 'ok' : 'err');
        redirect($ok ? 'login.php' : 'reset.php');
    }
}

$validToken = $token !== '' && PasswordReset::validate($token) !== null;
layout_header('Reset hasła', null);
?>
<div class="auth">
  <h1 style="text-align:center">Reset hasła</h1>
  <p class="muted" style="text-align:center;margin:0 0 18px"><?= $validToken ? 'Ustaw nowe hasło do konta' : 'Podaj e-mail przypisany do konta' ?></p>
  <div class="panel">
    <?php if ($validToken): ?>
      <form method="post">
        <input type="hidden" name="t" value="<?= h($token) ?>">
        <label for="password">Nowe hasło (min. 6 znaków)</label>
        <input id="password" name="password" type="password" required minlength="6" autofocus>
        <label for="password2">Powtórz nowe hasło</label>
        <input id="password2" name="password2" type="password" required minlength="6">
        <button class="btn">Zmień hasło</button>
      </form>
    <?php else: ?>
      <?php if ($token !== ''): ?><p class="flash err">Link wygasł albo został już użyty — poproś o nowy poniżej.</p><?php endif; ?>
      <form method="post">
        <label for="email">E-mail</label>
        <input id="email" name="email" type="email" required autofocus placeholder="adres podany w ustawieniach konta">
        <button class="btn">Wyślij link do resetu</button>
      </form>
      <p class="muted" style="margin-top:14px;font-size:12.5px">Nie podałeś e-maila w grze? Napisz do administratora na czacie z innego konta albo załóż nowe konto.</p>
    <?php endif; ?>
    <p class="muted" style="margin-top:14px;font-size:13px"><a href="login.php" style="color:var(--accent)">← Wróć do logowania</a></p>
  </div>
</div>
<?php layout_footer();
