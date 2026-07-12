<?php
require __DIR__ . '/_boot.php';
if (current_user()) redirect('pulpit.php');

$cfg = require __DIR__ . '/../config.php';
$inviteRequired = (string) (Engine::one("SELECT v FROM game_state WHERE k='invite_code'") ?: '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    $invite = trim($_POST['invite'] ?? '');

    $err = null;
    if (!preg_match('/^[A-Za-z0-9_-]{3,20}$/', $username)) $err = 'Login: 3–20 znaków (litery, cyfry, _ i -).';
    elseif (stripos($username, 'bot_') === 0)               $err = 'Ten login jest zarezerwowany.';
    elseif (strlen($pass1) < 6)                              $err = 'Hasło musi mieć co najmniej 6 znaków.';
    elseif ($pass1 !== $pass2)                               $err = 'Hasła nie są identyczne.';
    elseif ($inviteRequired !== '' && !hash_equals($inviteRequired, $invite)) $err = 'Nieprawidłowy kod zaproszenia.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))     $err = 'To nie wygląda na poprawny e-mail.';
    elseif ($email !== '' && Engine::one("SELECT id FROM users WHERE email=?", [$email])) $err = 'Ten e-mail jest już przypisany do konta.';
    elseif (Engine::one("SELECT id FROM users WHERE LOWER(username)=LOWER(?)", [$username])) $err = 'Ten login jest już zajęty.';

    if ($err === null) {
        try {
            [$sessionNo] = Engine::sessionInfo();
            Db::pdo()->prepare("INSERT INTO users (username, password_hash, email, is_bot, role, cash, joined_session, start_equity) VALUES (?,?,?,0,'player',?,?,?)")
                ->execute([$username, password_hash($pass1, PASSWORD_DEFAULT), $email !== '' ? $email : null, $cfg['starting_cash'], $sessionNo, $cfg['starting_cash']]);
            $uid = (int) Db::pdo()->lastInsertId();
            Log::write('info', 'auth', 'register', "nowe konto: $username", ['uid' => $uid]);
            Tokens::grant($uid, 10, 'welcome', 'Tokeny powitalne — zajrzyj do Sklepu');
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            $goal = (float) (Engine::one("SELECT v FROM game_state WHERE k='goal_target'") ?: 0);
            flash('Witaj na giełdzie! Masz ' . money($cfg['starting_cash']) . ' PLN startowego kapitału' .
                  ($goal > 0 ? ' — cel: ' . money($goal) . ' PLN.' : '.'));
            redirect('pulpit.php');
        } catch (Throwable $e) {
            $err = 'Nie udało się utworzyć konta (login zajęty?).';
        }
    }
    flash($err, 'err');
    redirect('register.php');
}

layout_header('Rejestracja', null);
?>
<div class="auth">
  <h1 style="text-align:center">Załóż konto</h1>
  <p class="muted" style="text-align:center;margin:0 0 18px">Start: <?= money($cfg['starting_cash']) ?> PLN wirtualnego kapitału</p>
  <div class="panel">
    <form method="post">
      <label for="username">Login</label>
      <input id="username" name="username" autofocus required minlength="3" maxlength="20" pattern="[A-Za-z0-9_\-]{3,20}">
      <label for="email">E-mail <span class="muted">(opcjonalny — do odzyskiwania hasła)</span></label>
      <input id="email" name="email" type="email" placeholder="twoj@email.pl">
      <label for="password">Hasło (min. 6 znaków)</label>
      <input id="password" name="password" type="password" required minlength="6">
      <label for="password2">Powtórz hasło</label>
      <input id="password2" name="password2" type="password" required minlength="6">
      <?php if ($inviteRequired !== ''): ?>
        <label for="invite">Kod zaproszenia</label>
        <input id="invite" name="invite" required>
      <?php endif; ?>
      <button class="btn">Utwórz konto i graj</button>
    </form>
    <p class="muted" style="margin-top:14px;font-size:13px">Masz już konto? <a href="login.php" style="color:var(--accent)">Zaloguj się</a></p>
  </div>
</div>
<?php layout_footer();
