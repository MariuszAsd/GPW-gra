<?php
require __DIR__ . '/_boot.php';
if (current_user()) redirect('pulpit.php');

$cfg = require __DIR__ . '/../config.php';
$inviteRequired = (string) (Engine::one("SELECT v FROM game_state WHERE k='invite_code'") ?: '');
const REGISTER_PER_IP_HOUR = 10;   // maks. nowych kont z jednego IP na godzinę (QA-bot zakłada najwyżej jedno na przebieg)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email = mb_strtolower(trim($_POST['email'] ?? ''));
    $pass1 = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    $invite = trim($_POST['invite'] ?? '');

    // Limit nowych kont z jednego adresu IP (okno 1 h), liczony z dziennika jak throttle logowania.
    // Bez tego skrypt zakładał dowolnie wiele kont, które śmiecą na czacie i w rankingu. Próg jest luźny,
    // bo za jednym NAT-em (szkoła, biuro) rejestruje się czasem kilka osób naraz; kod zaproszenia zostaje
    // twardą blokadą na czas wzmożonego spamu. Adres siedzi w context (JSON z REMOTE_ADDR), nie w tekście.
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $ipMark = substr(json_encode(['ip' => $ip]), 1, -1);
    $recent = (int) Engine::one("SELECT COUNT(*) FROM logs WHERE source='auth' AND event='register' AND ts >= ? AND context LIKE ?",
        [date('Y-m-d H:i:s', time() - 3600), '%' . $ipMark . '%']);

    $err = null;
    if ($ip !== '' && $recent >= REGISTER_PER_IP_HOUR) {
        Log::write('warn', 'auth', 'register_block', 'zablokowano rejestrację (za dużo kont z jednego adresu)', ['ip' => $ip]);
        $err = 'Z tego adresu założono już wiele kont w krótkim czasie. Spróbuj ponownie za godzinę.';
    }
    elseif (!preg_match('/^[A-Za-z0-9_-]{3,20}$/', $username)) $err = 'Login: 3–20 znaków (litery, cyfry, _ i -).';
    elseif (stripos($username, 'bot_') === 0)               $err = 'Ten login jest zarezerwowany.';
    elseif (strlen($pass1) < 6)                              $err = 'Hasło musi mieć co najmniej 6 znaków.';
    elseif ($pass1 !== $pass2)                               $err = 'Hasła nie są identyczne.';
    elseif ($inviteRequired !== '' && !hash_equals($inviteRequired, $invite)) $err = 'Nieprawidłowy kod zaproszenia.';
    elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))     $err = 'To nie wygląda na poprawny e-mail.';
    elseif ($email !== '' && Engine::one("SELECT id FROM users WHERE email=?", [$email])) $err = 'Ten e-mail jest już przypisany do konta.';
    elseif (Engine::one("SELECT id FROM users WHERE LOWER(username)=LOWER(?)", [$username])) $err = 'Ten login jest już zajęty.';

    // link polecający (?ref=Rxxx → ukryte pole formularza): polecony dostaje bonus tokenów od razu,
    // polecający nagrodę dopiero gdy polecony realnie zacznie handlować (Tokens::grantReferrals)
    $refBy = null;
    $refCode = trim((string) ($_POST['ref'] ?? ''));
    if ($err === null && $refCode !== '' && preg_match('/^R\d+[A-Z0-9]{1,10}$/i', $refCode)) {
        $refBy = Engine::one("SELECT id FROM users WHERE ref_code=? AND is_bot=0", [$refCode]);
        $refBy = $refBy !== false && $refBy !== null ? (int) $refBy : null;
    }

    if ($err === null) {
        try {
            [$sessionNo] = Engine::sessionInfo();
            Db::pdo()->prepare("INSERT INTO users (username, password_hash, email, is_bot, role, cash, joined_session, start_equity, referred_by) VALUES (?,?,?,0,'player',?,?,?,?)")
                ->execute([$username, password_hash($pass1, PASSWORD_DEFAULT), $email !== '' ? $email : null, $cfg['starting_cash'], $sessionNo, $cfg['starting_cash'], $refBy]);
            $uid = (int) Db::pdo()->lastInsertId();
            Engine::worldCashAdjust((float) $cfg['starting_cash'], "rejestracja $username");   // legalna kreacja — przesuń kotwicę sumy pieniądza
            // „czy pobiłeś indeks?”: punkt odniesienia z chwili rejestracji (kapitał startowy i indeks z tej samej chwili)
            try { Db::pdo()->prepare("UPDATE users SET bench_tick=?, bench_index=?, bench_equity=? WHERE id=?")
                    ->execute([(int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0), Engine::indexValue(), (float) $cfg['starting_cash'], $uid]); } catch (Throwable $e) { /* ustawi się leniwie */ }
            // baza ligi tygodnia i miesiąca od momentu dołączenia — nowy gracz liczy się w procentach od dziś
            try { Engine::snapshotUser($uid, (float) $cfg['starting_cash']); } catch (Throwable $e) { /* migawka nie blokuje rejestracji */ }
            Log::write('info', 'auth', 'register', "nowe konto: $username" . ($refBy ? " (z polecenia #$refBy)" : ''), ['uid' => $uid, 'ip' => $ip]);
            Tokens::grant($uid, 10, 'welcome', 'Tokeny powitalne — zajrzyj do sekcji Tokeny inwestora');
            // bonus z polecenia wypłaca się, gdy nowy gracz realnie zacznie handlować (Tokens::grantReferrals)
            session_regenerate_id(true);
            $_SESSION['uid'] = $uid;
            flash('Witaj na giełdzie! Masz ' . money($cfg['starting_cash']) . ' PLN startowego kapitału. Liczy się stopa zwrotu — powodzenia!');
            redirect('pulpit.php');
        } catch (Throwable $e) {
            $err = 'Nie udało się utworzyć konta (login zajęty?).';
        }
    }
    flash($err, 'err');
    redirect('register.php');
}

$refGet = trim((string) ($_GET['ref'] ?? ''));
$refValid = $refGet !== '' && preg_match('/^R\d+[A-Z0-9]{1,10}$/i', $refGet)
    && Engine::one("SELECT id FROM users WHERE ref_code=? AND is_bot=0", [$refGet]);

layout_header('Rejestracja', null);
?>
<div class="auth">
  <div style="display:flex;justify-content:center;margin-bottom:6px"><?= brand_logo(38, 'index.php') ?></div>
  <h1 style="text-align:center;font-size:20px;margin:2px 0 2px">Załóż darmowe konto</h1>
  <p class="muted" style="text-align:center;margin:0 0 18px">Start: <?= money($cfg['starting_cash']) ?> PLN wirtualnego kapitału + 10 Tokenów</p>
  <?php if ($refValid): ?><p class="flash ok" style="text-align:center">🤝 Rejestrujesz się z polecenia — dostaniesz dodatkowo +<?= Tokens::REF_BONUS ?> Tokenów inwestora, gdy zrobisz 5 transakcji w 3 różne dni (bonus za realną grę, nie za samo konto).</p><?php endif; ?>
  <div class="panel">
    <form method="post">
      <?php if ($refValid): ?><input type="hidden" name="ref" value="<?= h($refGet) ?>"><?php endif; ?>
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
