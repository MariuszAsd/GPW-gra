<?php
/** Sezon: punkty za wyzwania serii, ścieżki nagród (darmowa/premium) i karnet sezonowy. */
require __DIR__ . '/_boot.php';
$user = require_login();
$uid = (int) $user['id'];

$season = Seasons::active();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_season_pass']) && $season) {
    [$ok, $msg] = Seasons::buyPass($uid, (int) $season['id']);
    flash($msg, $ok ? 'ok' : 'err');
    redirect('sezon.php');
}

layout_header('Sezon', $user, 'season');
?>
<div class="page-head">
  <h1>Liga</h1>
  <?php if ($season): ?><span class="tag" style="color:var(--accent);border-color:var(--accent)"><?= h($season['name']) ?></span><?php endif; ?>
  <span class="muted hide-m">punkty za miejsca w edycjach serii wyzwań · nagrody na dwóch ścieżkach</span>
</div>
<?php liga_subnav('season'); ?>
<?php explainer('sezon', 'Jak działa Sezon', [
    'graj w edycjach ligi (Wyzwania)', 'miejsca dają punkty sezonowe',
    'punkty odblokowują progi nagród', 'karnet premium = druga ścieżka nagród']); ?>

<?php if (!$season): ?>
  <section class="panel" style="text-align:center;padding:30px">
    <div style="font-size:34px">🏁</div>
    <h2 style="margin:8px 0 6px">Brak aktywnego sezonu</h2>
    <p class="muted" style="margin:0">Sezon rusza, gdy GM utworzy serię wyzwań (ligę). Wypatruj ogłoszenia w Newsach!</p>
  </section>
<?php else: ?>
  <?php
    $prog = Seasons::progress((int) $season['id'], $uid);
    $pts = (int) ($prog['points'] ?? 0);
    $premium = (int) ($prog['premium'] ?? 0) === 1;
    $granted = (int) ($prog['granted_upto'] ?? 0);
    $maxPts = Seasons::MILESTONES[count(Seasons::MILESTONES) - 1][0];
    $standings = Seasons::standings((int) $season['id']);
  ?>
  <div class="stats" style="grid-template-columns:repeat(4,1fr)">
    <div class="stat"><div class="k">Twoje punkty</div><div class="v">🏁 <?= $pts ?></div></div>
    <div class="stat"><div class="k">Karnet premium</div><div class="v" style="<?= $premium ? 'color:var(--gold)' : '' ?>"><?= $premium ? 'AKTYWNY' : 'brak' ?></div></div>
    <div class="stat"><div class="k">Edycji wydano</div><div class="v"><?= (int) $season['editions'] ?></div></div>
    <div class="stat"><div class="k">Następna edycja</div><div class="v" style="font-size:16px">sesja #<?= (int) $season['next_session'] ?></div></div>
  </div>

  <section class="panel" style="margin-bottom:16px">
    <h2>Ścieżki nagród</h2>
    <div style="background:var(--bg3);border-radius:20px;height:8px;margin:12px 0 4px;overflow:hidden">
      <div style="background:var(--accent);height:100%;width:<?= min(100, max(1, $pts / $maxPts * 100)) ?>%"></div>
    </div>
    <p class="muted" style="margin:0 0 12px;font-size:12px"><?= $pts ?> / <?= $maxPts ?> pkt — punkty: 1. miejsce 25 · 2. miejsce 18 · 3. miejsce 14 · płatne miejsca 10 · udział 5</p>
    <table>
      <thead><tr><th>Próg</th><th>Ścieżka darmowa</th><th>Ścieżka premium 🏁</th><th class="num">Status</th></tr></thead>
      <tbody>
      <?php foreach (Seasons::MILESTONES as $i => [$need, $free, $prem]): $hit = $pts >= $need; $last = $i === count(Seasons::MILESTONES) - 1; ?>
        <tr style="<?= $hit ? 'background:var(--up-bg)' : '' ?>">
          <td><b><?= $need ?> pkt</b></td>
          <td>🪙 <?= $free ?></td>
          <td class="<?= $premium ? '' : 'muted' ?>">🪙 <?= $prem ?><?= $last ? ' + tytuł „Legenda Sezonu"' : '' ?></td>
          <td class="num"><?= $hit ? '<span class="chg p">✓ osiągnięty</span>' : '<span class="muted">' . ($need - $pts) . ' pkt brakuje</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php if (!$premium): ?>
      <form method="post" style="margin-top:12px">
        <button class="btn sm" name="buy_season_pass" value="1" <?= Tokens::balance($uid) < Seasons::PASS_PRICE ? 'disabled title="Za mało tokenów"' : '' ?>>
          Kup karnet premium — 🪙 <?= Seasons::PASS_PRICE ?>
        </button>
        <span class="muted" style="font-size:12px;margin-left:8px">Karnet kupiony później wypłaca nagrody premium wstecz za osiągnięte progi.</span>
      </form>
    <?php endif; ?>
    <p class="muted" style="margin:10px 0 0;font-size:12px">Nagrody wypłacają się same po rozstrzygnięciu każdej edycji. Wypłacone progi: <?= $granted ?>/<?= count(Seasons::MILESTONES) ?>.</p>
  </section>

  <section class="panel">
    <h2>Tabela sezonu</h2>
    <table>
      <thead><tr><th style="width:50px">#</th><th>Gracz</th><th class="num">Punkty</th><th class="num">Karnet</th></tr></thead>
      <tbody>
      <?php $medals = ['🥇', '🥈', '🥉']; foreach ($standings as $i => $st): ?>
        <tr <?= (int) $st['uid'] === $uid ? 'style="background:var(--info-bg)"' : '' ?>>
          <td class="mono"><?= $medals[$i] ?? $i + 1 ?></td>
          <td><a href="gracz.php?id=<?= (int) $st['uid'] ?>" style="font-weight:700;color:var(--accent)"><?= h($st['username']) ?></a><?= trim((string) $st['title']) !== '' ? ' <span class="tag" style="color:var(--gold);border-color:var(--gold-border)">' . h($st['title']) . '</span>' : '' ?></td>
          <td class="num mono"><?= (int) $st['points'] ?></td>
          <td class="num"><?= (int) $st['premium'] === 1 ? '🏁' : '<span class="muted">—</span>' ?></td>
        </tr>
      <?php endforeach; if (!$standings) echo "<tr><td class='muted' colspan=4 style='padding:18px'>Jeszcze nikt nie zdobył punktów — rozstrzygnięcie pierwszej edycji je przyzna.</td></tr>"; ?>
      </tbody>
    </table>
  </section>
<?php endif; ?>
<?php layout_footer();
