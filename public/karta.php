<?php
/**
 * PUBLICZNA karta wyniku gracza (bez logowania): link z tokenu z e-maila / Konta / Pulpitu.
 * Pokazuje tylko dane widoczne w rankingu i zaprasza do gry linkiem polecającym właściciela karty.
 * ?off=1 pokazuje potwierdzenie wypisu z tygodniowych e-maili; sam wypis to POST z osobnym tokenem HMAC
 * z linku w e-mailu (patrz niżej) — publiczny token karty do tego nie wystarcza.
 */
require_once __DIR__ . '/../src/Db.php';
require_once __DIR__ . '/../src/Schema.php';
require_once __DIR__ . '/../src/Migrator.php';
require_once __DIR__ . '/../src/Engine.php';
require_once __DIR__ . '/../src/Log.php';
require_once __DIR__ . '/../src/Achievements.php';
require_once __DIR__ . '/../src/Weekly.php';
try { Migrator::ensure(); } catch (Throwable $e) { /* strona publiczna nie migruje na siłę */ }

$tok = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['u'] ?? ''));
$u = strlen($tok) === 32 ? Engine::row("SELECT id, username, start_equity, ref_code, title, weekly_mail, email FROM users WHERE share_token=? AND is_bot=0 AND role IN ('player','qa')", [$tok]) : null;
if (!$u) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo "Nie ma takiej karty."; exit; }
$uid = (int) $u['id'];
$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$pct = fn(?float $v) => $v === null ? '—' : (($v >= 0 ? '+' : '') . number_format($v, 1, ',', ' ') . '%');

$page = function (string $title, string $body) {
    header('Content-Type: text/html; charset=UTF-8');
    echo "<!doctype html><html lang='pl'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Makleria — $title</title></head>"
       . "<body style='font-family:system-ui,sans-serif;background:#0f172a;color:#e2e8f0;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0'>"
       . "<div style='max-width:460px;padding:28px;background:#1e293b;border-radius:16px'><h1 style='margin:0 0 10px;font-size:20px'>$title</h1>$body</div></body></html>";
    exit;
};
// WYPIS Z E-MAILI: tylko POST z osobnym tokenem (HMAC z sekretu aplikacji — Weekly::offToken), nigdy samym tokenem
// karty. Token karty jest publiczny z założenia (gracz podsyła go znajomym), więc „&off=1” na nim pozwalał każdemu,
// kto zna link — także filtrom poczty, które otwierają adresy z e-maili — wyłączyć komuś tygodniowy raport po cichu.
// GET niczego nie zmienia: pokazuje stronę potwierdzenia, a stary link bez tokenu odsyła do Konta.
$offTok = preg_replace('/[^a-f0-9]/', '', (string) ($_REQUEST['t'] ?? ''));
$offValid = $offTok !== '' && hash_equals(Weekly::offToken($uid), $offTok);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['off'])) {
    if (!$offValid) { http_response_code(403); $page('Link wypisu jest nieaktualny', "<p style='color:#94a3b8'>Ten link nie pozwala zmienić ustawień. Tygodniowe e-maile wyłączysz w grze: Konto → Ustawienia konta.</p><a href='konto.php' style='color:#38bdf8'>Przejdź do Konta →</a>"); }
    Db::pdo()->prepare("UPDATE users SET weekly_mail=0 WHERE id=?")->execute([$uid]);
    Log::write('info', 'auth', 'weekly.off', "gracz #$uid wypisał się z tygodniowych e-maili");
    try { Engine::notify($uid, 'system', '📭 Tygodniowe e-maile „Twój tydzień w Maklerii” wyłączone — włączysz je ponownie w Koncie.', 'konto.php'); } catch (Throwable $e) { /* powiadomienie nie blokuje wypisu */ }
    $page('Tygodniowe raporty wyłączone', "<p style='color:#94a3b8'>Nie wyślemy już e-maila „Twój tydzień w Maklerii” na Twój adres. Podsumowania dalej zobaczysz w powiadomieniach w grze. Włączysz je ponownie w Koncie.</p><a href='konto.php' style='color:#38bdf8'>Przejdź do gry →</a>");
}
if (isset($_GET['off'])) {
    if (!$offValid) $page('Link wypisu jest nieaktualny', "<p style='color:#94a3b8'>Ten link nie pozwala zmienić ustawień (pochodzi ze starszego e-maila albo z publicznej karty wyniku). Tygodniowe e-maile wyłączysz w grze: Konto → Ustawienia konta.</p><a href='konto.php' style='color:#38bdf8'>Przejdź do Konta →</a>");
    $page('Wyłączyć tygodniowe e-maile?', "<p style='color:#94a3b8'>Konto <b>" . $h($u['username']) . "</b>: przestaniemy wysyłać e-mail „Twój tydzień w Maklerii”. Podsumowania zostaną w powiadomieniach w grze, a e-maile włączysz ponownie w Koncie.</p>"
        . "<form method='post' action='karta.php?u=" . $h($tok) . "'><input type='hidden' name='t' value='" . $h($offTok) . "'><button name='off' value='1' style='background:#38bdf8;color:#0b1220;border:0;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer'>Tak, wyłącz e-maile</button> "
        . "<a href='karta.php?u=" . $h($tok) . "' style='color:#94a3b8;margin-left:10px'>Nie, zostaw</a></form>");
}

$equity = Engine::playerEquity($uid);
$allRet = (float) $u['start_equity'] > 0 ? ($equity / (float) $u['start_equity'] - 1) * 100 : 0.0;
$bm = Engine::benchmark($uid);
$rung = Engine::ladderRung($allRet);
$rungA = $rung ? Achievements::get($rung[2]) : null;
$badges = (int) Engine::one("SELECT COUNT(*) FROM achievements WHERE user_id=?", [$uid]);
$lastWeek = Engine::row("SELECT period, rank, ret_pct FROM league_results WHERE kind='week' AND user_id=? ORDER BY period DESC LIMIT 1", [$uid]);
$players = $lastWeek ? (int) Engine::one("SELECT COUNT(*) FROM league_results WHERE kind='week' AND period=?", [$lastWeek['period']]) : 0;
$joinUrl = 'register.php' . (!empty($u['ref_code']) ? '?ref=' . rawurlencode((string) $u['ref_code']) : '');
$title = $u['username'] . ' — ' . $pct($allRet) . ' w Maklerii';
$desc = 'Symulator giełdy: ' . $u['username'] . ' ma ' . $pct($allRet) . ' od startu' . ($bm['alpha'] >= 0 ? ' i pobija indeks MAK40 o ' : ', indeks MAK40 wygrywa o ') . number_format(abs($bm['alpha']), 1, ',', ' ') . ' pkt proc. Dołącz i zagraj o lepszy wynik — 100 000 PLN na start.';
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $h($title) ?></title>
<meta property="og:title" content="<?= $h($title) ?>">
<meta property="og:description" content="<?= $h($desc) ?>">
<meta property="og:type" content="website">
<meta name="description" content="<?= $h($desc) ?>">
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:radial-gradient(1200px 600px at 20% 0%,#1e3a5f,#0b1220 60%);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#e2e8f0;padding:16px;box-sizing:border-box}
  .card{width:100%;max-width:560px;background:linear-gradient(160deg,#111c31,#0f172a);border:1px solid #334155;border-radius:20px;padding:26px 28px;box-shadow:0 20px 60px rgba(0,0,0,.45)}
  .brand{display:flex;justify-content:space-between;align-items:center;color:#94a3b8;font-size:12px;letter-spacing:.08em;text-transform:uppercase}
  .name{font-size:28px;font-weight:800;margin:14px 0 2px}
  .title{color:#fbbf24;font-size:13px;margin-bottom:14px}
  .big{font-size:56px;font-weight:900;letter-spacing:-.03em;line-height:1}
  .up{color:#4ade80}.down{color:#f87171}
  .sub{color:#94a3b8;font-size:13px;margin-top:6px}
  .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:20px 0 6px}
  .stat{background:#0b1220;border:1px solid #1f2a44;border-radius:12px;padding:10px 12px}
  .stat small{display:block;color:#94a3b8;font-size:11px;text-transform:uppercase;letter-spacing:.06em}
  .stat b{font-size:17px}
  .cta{display:block;margin-top:20px;background:#38bdf8;color:#0b1220;text-decoration:none;font-weight:800;text-align:center;padding:13px 16px;border-radius:12px;font-size:15px}
  .foot{color:#64748b;font-size:11.5px;text-align:center;margin-top:12px}
  @media (max-width:420px){.big{font-size:44px}.grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>
<div class="card">
  <div class="brand"><span>📈 Makleria · symulator giełdy</span><span>karta wyniku</span></div>
  <div class="name"><?= $h($u['username']) ?></div>
  <?php if (trim((string) $u['title']) !== ''): ?><div class="title">🏷 <?= $h($u['title']) ?></div><?php endif; ?>
  <div class="big <?= $allRet >= 0 ? 'up' : 'down' ?>"><?= $pct($allRet) ?></div>
  <div class="sub">stopa zwrotu od startu · kapitał <?= number_format($equity, 0, ',', ' ') ?> PLN</div>
  <div class="grid">
    <div class="stat"><small>vs MAK40</small><b class="<?= $bm['alpha'] >= 0 ? 'up' : 'down' ?>"><?= ($bm['alpha'] >= 0 ? '+' : '') . number_format($bm['alpha'], 1, ',', ' ') ?> pp</b></div>
    <div class="stat"><small>Drabinka</small><b><?= $rungA ? $h($rungA[0] . ' ' . $rungA[1]) : '— jeszcze pod +10%' ?></b></div>
    <div class="stat"><small>Odznaki</small><b>🎖️ <?= $badges ?> z <?= count(Achievements::all()) ?></b></div>
    <?php if ($lastWeek): ?>
    <div class="stat"><small>Tydzień <?= $h($lastWeek['period']) ?></small><b class="<?= (float) $lastWeek['ret_pct'] >= 0 ? 'up' : 'down' ?>"><?= $pct((float) $lastWeek['ret_pct']) ?></b></div>
    <div class="stat"><small>Liga tygodnia</small><b><?= (int) $lastWeek['rank'] ?>. / <?= $players ?></b></div>
    <?php endif; ?>
    <div class="stat"><small>Indeks MAK40</small><b><?= number_format($bm['index_now'], 0, ',', ' ') ?> pkt</b></div>
  </div>
  <a class="cta" href="<?= $h($joinUrl) ?>">Zagraj i spróbuj pobić <?= $h($u['username']) ?> — 100 000 PLN na start →</a>
  <div class="foot">Wirtualne pieniądze, prawdziwe emocje: 76+ spółek, boty, raporty, dywidendy, krachy i hossy. Handel 7:50–22:00.</div>
</div>
</body>
</html>
