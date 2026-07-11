<?php
require __DIR__ . '/_boot.php';
if (current_user()) redirect('pulpit.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = Engine::row("SELECT id, password_hash FROM users WHERE username=? AND is_bot=0", [$_POST['username'] ?? '']);
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        redirect('pulpit.php');
    }
    Log::write('warn', 'auth', 'login_fail', 'nieudane logowanie: ' . mb_substr(trim($_POST['username'] ?? ''), 0, 30));
    flash('Błędny login lub hasło.', 'err');
    redirect('login.php');
}

layout_header('Logowanie', null);
?>
<div class="auth">
  <h1 style="text-align:center">GPW-gra</h1>
  <p class="muted" style="text-align:center;margin:0 0 18px">Symulator giełdy</p>
  <div class="panel">
    <form method="post">
      <label for="username">Login</label>
      <input id="username" name="username" autofocus required>
      <label for="password">Hasło</label>
      <input id="password" name="password" type="password" required>
      <button class="btn">Zaloguj</button>
    </form>
    <p class="muted" style="margin-top:14px;font-size:13px">Nie masz konta? <a href="register.php" style="color:var(--accent)">Załóż konto — start 100 000 PLN</a></p>
    <p class="muted" style="margin-top:6px;font-size:13px"><a href="reset.php" style="color:var(--accent)">Nie pamiętasz hasła?</a></p>
    <p class="muted" style="margin-top:8px;font-size:12px">Konto demo: <b>gracz / haslo123</b></p>
  </div>
</div>
<?php layout_footer();
