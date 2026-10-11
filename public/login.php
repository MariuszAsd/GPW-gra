<?php
require __DIR__ . '/_boot.php';
if (current_user()) redirect('pulpit.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ochrona przed atakiem słownikowym: limit nieudanych prób z jednego IP w oknie 15 min.
    // Liczymy z dziennika logów (bez nowej tabeli); bcrypt spowalnia pojedynczą próbę, throttle blokuje masę.
    // Adres IP siedzi w kolumnie context (JSON budowany wyłącznie z REMOTE_ADDR), a NIE w wolnym tekście
    // komunikatu: dawniej login z POST lądował w message przed markerem „|ip=…|”, więc napastnik mógł wpisać
    // cudzy marker jako login i doliczyć fałszywe porażki dowolnemu adresowi (blokada biura/NAT na 15 min).
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ipMark = json_encode(['ip' => $ip]);                       // {"ip":"1.2.3.4"} — dokładnie taki fragment pisze Log::write
    $ipMark = substr($ipMark, 1, -1);                            // bez nawiasów klamrowych: pasuje też, gdy context ma więcej pól
    $since = date('Y-m-d H:i:s', time() - 900);
    $fails = (int) Engine::one("SELECT COUNT(*) FROM logs WHERE source='auth' AND event='login_fail' AND ts >= ? AND context LIKE ?", [$since, '%' . $ipMark . '%']);
    if ($ip !== '' && $fails >= 8) {
        Log::write('warn', 'auth', 'login_block', 'zablokowano (za dużo prób)', ['ip' => $ip]);
        flash('Za dużo nieudanych prób logowania z tego adresu. Odczekaj kilka minut i spróbuj ponownie.', 'err');
        redirect('login.php');
    }
    $u = Engine::row("SELECT id, password_hash FROM users WHERE username=? AND is_bot=0", [$_POST['username'] ?? '']);
    if ($u && password_verify($_POST['password'] ?? '', $u['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $u['id'];
        redirect('pulpit.php');
    }
    // do komunikatu trafia tylko login oczyszczony do znaków dozwolonych przy rejestracji — żadnych separatorów ani cudzysłowów
    $safeLogin = preg_replace('/[^A-Za-z0-9_\-]/', '', mb_substr(trim((string) ($_POST['username'] ?? '')), 0, 30));
    Log::write('warn', 'auth', 'login_fail', 'nieudane logowanie: ' . $safeLogin, ['ip' => $ip]);
    flash('Błędny login lub hasło.', 'err');
    redirect('login.php');
}

layout_header('Logowanie', null);
?>
<div class="auth">
  <div style="display:flex;justify-content:center;margin-bottom:6px"><?= brand_logo(38, 'index.php') ?></div>
  <p class="muted" style="text-align:center;margin:0 0 18px">Giełdowa gra treningowa — zacznij ze 100 000 wirtualnych PLN</p>
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
  </div>
</div>
<?php layout_footer();
