<?php
/** Strona główna makleria.pl — landing dla niezalogowanych (rejestracja/logowanie). */
require __DIR__ . '/_boot.php';
if (current_user()) redirect('pulpit.php');

$cfg = require __DIR__ . '/../config.php';
$startCash = (float) ($cfg['starting_cash'] ?? 100000);
[$sessionNo] = Engine::sessionInfo();
$stocks  = (int) Engine::one("SELECT COUNT(*) FROM stocks");
$players = (int) Engine::one("SELECT COUNT(*) FROM users WHERE is_bot=0 AND role='player'");

$features = [
    ['📈', 'Prawdziwy handel', 'Arkusz zleceń, LIMIT i PKC, Stop-Loss, Take-Profit i SL kroczący, widełki i zawieszenia notowań — jak na dużym parkiecie.'],
    ['📰', 'Żywy rynek', 'Spółki publikują raporty i dywidendy, wychodzą newsy i plotki, a boty-inwestorzy reagują różnie na fundamenty, nastroje i technikę.'],
    ['⚔️', 'Liga i wyzwania', 'Rywalizuj o pulę nagród, wspinaj się w rankingu, zbieraj punkty sezonu, karnet i odznaki.'],
    ['🏦', 'Więcej niż akcje', 'Lokaty na procent, oferty publiczne (IPO) z zapisami i redukcją, analiza techniczna z 10 wskaźników.'],
    ['🎓', 'Nauczysz się grać', '3-minutowy samouczek, podpowiedzi przy każdym polu i pełna Pomoc z infografikami — od zera do pierwszej transakcji.'],
    ['💬', 'Nie grasz sam', 'Czat rynkowy i forum każdej spółki, profile graczy, powiadomienia — a kultury pilnuje moderacja.'],
];
$steps = [
    ['Zakładasz konto', 'Wybierasz login, opcjonalnie e-mail — i już jesteś na parkiecie. Za darmo.'],
    ['Dostajesz ' . money_short($startCash) . ' PLN', 'Wirtualny kapitał startowy plus 10 Tokenów Maklera na start.'],
    ['Budujesz pierwszy milion', 'Kupuj tanio, sprzedawaj drogo, chroń zyski i wspinaj się w rankingu.'],
];
?><!doctype html>
<html lang="pl"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<script>(function(){var t=null;try{t=localStorage.getItem('theme')}catch(e){}if(t!=='dark'&&t!=='light')t='light';document.documentElement.setAttribute('data-theme',t);})();</script>
<title>Makleria — giełdowa gra treningowa. Zagraj bez ryzyka.</title>
<meta name="description" content="Makleria to darmowy symulator giełdy. Handluj akcjami fikcyjnych spółek, walcz w wyzwaniach o pulę i ucz się inwestować bez ryzyka — na wirtualnych pieniądzach, na żywym rynku.">
<link rel="stylesheet" href="assets/app.css">
<style>
.lp-wrap{max-width:1040px;margin:0 auto;padding:0 20px}
.lp-top{position:sticky;top:0;z-index:20;background:color-mix(in srgb,var(--bg) 86%,transparent);backdrop-filter:blur(10px);border-bottom:1px solid var(--line)}
.lp-top .lp-wrap{display:flex;align-items:center;gap:12px;height:60px}
.lp-top .brand{font-size:18px}
.lp-top nav{margin-left:auto;display:flex;gap:8px;align-items:center}
.lp-hero{position:relative;overflow:hidden;text-align:center;padding:56px 0 40px;
  background:radial-gradient(120% 80% at 50% -10%,var(--accent-bg),transparent 70%)}
.lp-hero h1{font-size:clamp(28px,5.5vw,46px);line-height:1.08;letter-spacing:-.03em;margin:20px auto 0;max-width:16ch;font-weight:800}
.lp-hero .sub{color:var(--soft);font-size:clamp(15px,2.2vw,18px);line-height:1.55;max-width:56ch;margin:16px auto 0}
.lp-cta{display:flex;gap:12px;justify-content:center;flex-wrap:wrap;margin:26px 0 0}
.lp-cta .btn{width:auto;padding:13px 26px;font-size:15px}
.lp-stats{display:flex;gap:8px 26px;justify-content:center;flex-wrap:wrap;margin:26px 0 0;color:var(--faint);font-size:13px}
.lp-stats b{color:var(--ink);font-variant-numeric:tabular-nums}
.lp-preview{margin:34px auto 0;max-width:560px;background:var(--bg2);border:1px solid var(--line);border-radius:16px;
  padding:16px;box-shadow:var(--panel-shadow);text-align:left}
.lp-preview .pv-head{display:flex;align-items:baseline;gap:10px;margin-bottom:8px}
.lp-preview .pv-idx{font-size:22px;font-weight:800;letter-spacing:-.02em}
.lp-sec{padding:48px 0}
.lp-sec h2{text-align:center;font-size:clamp(22px,3.5vw,30px);letter-spacing:-.02em;margin:0 0 6px}
.lp-sec .lead{text-align:center;color:var(--soft);margin:0 auto 30px;max-width:52ch}
.lp-steps{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;counter-reset:step}
.lp-step{background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:22px 18px;position:relative}
.lp-step::before{counter-increment:step;content:counter(step);position:absolute;top:-13px;left:18px;width:28px;height:28px;
  border-radius:50%;background:linear-gradient(140deg,var(--accent),var(--up-soft,#17b26a));color:#fff;font-weight:800;
  display:flex;align-items:center;justify-content:center;font-size:14px;box-shadow:0 2px 6px rgba(51,85,232,.3)}
.lp-step b{display:block;font-size:16px;margin:4px 0 6px}
.lp-step span{color:var(--soft);font-size:13.5px;line-height:1.5}
.lp-feat{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.lp-card{background:var(--bg2);border:1px solid var(--line);border-radius:14px;padding:22px 18px;transition:transform .12s,box-shadow .12s}
.lp-card:hover{transform:translateY(-2px);box-shadow:var(--panel-shadow)}
.lp-card .ic{font-size:26px;display:block;margin-bottom:10px}
.lp-card b{display:block;font-size:16px;margin-bottom:5px}
.lp-card span{color:var(--soft);font-size:13.5px;line-height:1.55}
.lp-final{text-align:center;background:radial-gradient(120% 100% at 50% 120%,var(--accent-bg),transparent 70%);border-radius:20px;padding:44px 20px;border:1px solid var(--line)}
.lp-final h2{margin:0 0 8px}
.lp-final p{color:var(--soft);margin:0 auto 22px;max-width:46ch}
.lp-foot{border-top:1px solid var(--line);padding:26px 0;color:var(--faint);font-size:12.5px;text-align:center;line-height:1.7}
.lp-foot a{color:var(--soft);font-weight:600}
.lp-foot .disc{max-width:60ch;margin:8px auto 0;font-size:11.5px}
.lp-foot .brand{justify-content:center;display:inline-flex}
@media(max-width:760px){
  .lp-steps,.lp-feat{grid-template-columns:1fr}
  .lp-hero{padding:38px 0 28px}
  .lp-sec{padding:36px 0}
  .lp-cta .btn{width:100%}
}
</style>
</head><body>

<header class="lp-top"><div class="lp-wrap">
  <?= brand_logo(30, 'index.php') ?>
  <nav>
    <a class="btn sm ghost" style="width:auto" href="login.php">Zaloguj</a>
    <a class="btn sm" style="width:auto" href="register.php">Załóż konto</a>
  </nav>
</div></header>

<section class="lp-hero"><div class="lp-wrap">
  <div style="display:flex;justify-content:center"><?= brand_mark(66) ?></div>
  <h1>Zagraj na giełdzie. Zero ryzyka, prawdziwe emocje.</h1>
  <p class="sub">Makleria to darmowy symulator giełdy. Kupujesz i sprzedajesz akcje fikcyjnych spółek,
     walczysz w wyzwaniach o pulę i uczysz się inwestować — na wirtualnych pieniądzach, ale na żywym, tętniącym rynku.</p>
  <div class="lp-cta">
    <a class="btn" href="register.php">Załóż darmowe konto →</a>
    <a class="btn ghost" href="login.php">Mam już konto</a>
  </div>
  <div class="lp-stats">
    <span><b><?= $stocks ?></b> spółek na parkiecie</span>
    <span>sesja <b>#<?= $sessionNo ?></b></span>
    <span><b><?= $players ?></b> <?= $players === 1 ? 'inwestor' : 'inwestorów' ?></span>
    <span>start: <b><?= money_short($startCash) ?> PLN</b></span>
  </div>
  <div class="lp-preview">
    <div class="pv-head"><span class="pv-idx">1 188,41</span><span class="chg p"><span class="ar">▲</span>0,42%</span>
      <span class="muted" style="font-size:12px;margin-left:auto">Indeks Makleria · na żywo</span></div>
    <svg viewBox="0 0 520 120" preserveAspectRatio="none" style="width:100%;height:96px">
      <polygon points="0,120 0,84 52,78 104,88 156,66 208,72 260,52 312,60 364,40 416,48 468,28 520,22 520,120"
        fill="var(--up)" opacity="0.10"/>
      <polyline points="0,84 52,78 104,88 156,66 208,72 260,52 312,60 364,40 416,48 468,28 520,22"
        fill="none" stroke="var(--up)" stroke-width="2.2" stroke-linejoin="round"/>
    </svg>
  </div>
</div></section>

<section class="lp-sec"><div class="lp-wrap">
  <h2>Jak zacząć — w 60 sekund</h2>
  <p class="lead">Bez pobierania, bez opłat, bez ryzyka. Zakładasz konto i od razu jesteś na parkiecie.</p>
  <div class="lp-steps">
    <?php foreach ($steps as [$t, $d]): ?>
      <div class="lp-step"><b><?= h($t) ?></b><span><?= h($d) ?></span></div>
    <?php endforeach; ?>
  </div>
  <div class="lp-cta" style="margin-top:28px"><a class="btn" href="register.php">Zaczynam grać →</a></div>
</div></section>

<section class="lp-sec" style="background:var(--bg3)"><div class="lp-wrap">
  <h2>Co czeka na parkiecie</h2>
  <p class="lead">Głębia prawdziwej giełdy, podana tak, żeby dało się w to grać.</p>
  <div class="lp-feat">
    <?php foreach ($features as [$ic, $t, $d]): ?>
      <div class="lp-card"><span class="ic"><?= $ic ?></span><b><?= h($t) ?></b><span><?= h($d) ?></span></div>
    <?php endforeach; ?>
  </div>
</div></section>

<section class="lp-sec"><div class="lp-wrap">
  <div class="lp-final">
    <h2>Gotowy na pierwszą transakcję?</h2>
    <p>Załóż konto — to darmowe i zajmuje minutę. Dostajesz <?= money_short($startCash) ?> PLN wirtualnego kapitału i cały rynek do ogrania.</p>
    <a class="btn" style="width:auto;display:inline-block;padding:13px 30px" href="register.php">Załóż darmowe konto</a>
  </div>
</div></section>

<footer class="lp-foot"><div class="lp-wrap">
  <?= brand_logo(22, 'index.php') ?>
  <div style="margin-top:10px"><a href="login.php">Zaloguj</a> · <a href="register.php">Załóż konto</a></div>
  <p class="disc">Kursy i spółki są fikcyjne. Makleria to gra edukacyjno-rozrywkowa, nie platforma inwestycyjna —
     nie obracasz w niej prawdziwymi pieniędzmi, a Tokeny Maklera nigdy nie zamieniają się na złotówki.</p>
</div></footer>

</body></html>
