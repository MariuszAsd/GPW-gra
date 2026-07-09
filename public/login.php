<?php
require __DIR__ . '/_boot.php';
if (current_user()) redirect('market.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = Engine::row("SELECT id, password_hash FROM users WHERE username=? AND is_bot=0", [$_POST['username'] ?? '']);
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        redirect('market.php');
    }
    flash('Błędny login lub hasło.', 'err');
    redirect('login.php');
}

layout_header('Logowanie', null);
?>
<div class="login">
  <h1>Zaloguj się</h1>
  <div class="panel">
    <form method="post">
      <label for="username">Login</label>
      <input id="username" name="username" autofocus required value="gracz">
      <label for="password">Hasło</label>
      <input id="password" name="password" type="password" required value="haslo123">
      <button class="btn">Wejdź na giełdę</button>
    </form>
    <p class="muted" style="margin-top:14px">Konto demo wypełnione — po prostu kliknij „Wejdź".</p>
  </div>
</div>
<?php layout_footer();
