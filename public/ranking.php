<?php
require __DIR__ . '/_boot.php';
$user = require_login();

// obserwowanie graczy (znajomi): gwiazdka przy wierszu — obserwowani lądują w widżecie na Pulpicie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['follow_id']) || isset($_POST['unfollow_id']))) {
    $tid = (int) ($_POST['follow_id'] ?? $_POST['unfollow_id']);
    $t = Engine::row("SELECT id FROM users WHERE id=? AND is_bot=0 AND role='player'", [$tid]);
    if ($t && $tid !== (int) $user['id']) {
        if (isset($_POST['follow_id'])) {
            try { Db::pdo()->prepare("INSERT INTO user_follows (user_id, target_id, created_at) VALUES (?,?,?)")->execute([(int) $user['id'], $tid, Db::now()]); } catch (Throwable $e) { /* już obserwuje */ }
        } else {
            Db::pdo()->prepare("DELETE FROM user_follows WHERE user_id=? AND target_id=?")->execute([(int) $user['id'], $tid]);
        }
    }
    redirect('ranking.php');
}

[$sessionNo] = Engine::sessionInfo();

// Trzy tabele, wszystkie w PROCENTACH: od startu (domyślna), liga miesiąca, liga tygodnia.
// Kwota kapitału jest tylko informacją — nie decyduje o miejscu. Nowy gracz może wygrać z weteranem.
$wg = in_array($_GET['wg'] ?? '', ['miesiac', 'tydzien'], true) ? $_GET['wg'] : 'start';
$kind = ['start' => 'all', 'miesiac' => 'month', 'tydzien' => 'week'][$wg];
$players = Engine::leagueTable($kind);
$periods = Engine::periodKeys();
$periodLabel = ['start' => 'od kapitału startowego', 'miesiac' => 'liga miesiąca ' . $periods['month'], 'tydzien' => 'liga tygodnia ' . $periods['week']][$wg];

$following = array_map('intval', Engine::col("SELECT target_id FROM user_follows WHERE user_id=?", [(int) $user['id']]));
// benchmark: od startu — własny punkt odniesienia każdego gracza; w ligach — zmiana indeksu od początku okresu (jedna dla wszystkich)
$idxPeriod = $kind === 'all' ? null : Engine::periodIndexReturn($kind);

layout_header('Ranking', $user, 'ranking');
$medals = ['🥇', '🥈', '🥉'];
?>
<?php explainer('ranking', 'O co gramy', [
    'liczy się PROCENT, nie kwota: stopa zwrotu od startu albo od początku okresu',
    'wynik = gotówka + akcje + lokaty/IPO + zablokowane w wyzwaniu',
    'liga miesiąca i tygodnia startują od zera z każdym nowym okresem',
    'drabinka: +10% … +900% — odznaka i Tokeny za każdy szczebel',
    'vs MAK40: o ile punktów procentowych pobiłeś Indeks MAK40 w tym samym czasie (fundusz indeksowy w Portfelu)',
    'to nie Wyzwania — tam grasz osobnym portfelem z buy-inu']); ?>
<div class="page-head"><h1>Liga</h1><?= session_tag($sessionNo) ?>
  <span class="muted hide-m"><?= h($periodLabel) ?> — całym kapitałem konta (osobne konkursy z wpisowym: zakładka <b>Wyzwania</b>)</span>
</div>
<?php liga_subnav('ranking'); ?>
<?php subnav([['start', 'ranking.php', 'Od startu'], ['miesiac', 'ranking.php?wg=miesiac', 'Liga miesiąca'], ['tydzien', 'ranking.php?wg=tydzien', 'Liga tygodnia']], $wg); ?>

<?php if ($kind !== 'all'):
    $last = Engine::all("SELECT l.period, l.rank, l.ret_pct, l.prize, l.tokens, u.username FROM league_results l JOIN users u ON u.id=l.user_id
                         WHERE l.kind=? AND l.period=(SELECT MAX(period) FROM league_results WHERE kind=?) ORDER BY l.rank LIMIT 3", [$kind, $kind]);
    $pz = Engine::leaguePrizes()[$kind]; ?>
  <div class="panel" style="margin-bottom:10px;padding:10px 14px">
    <b><?= $kind === 'week' ? '🏆 Nagrody ligi tygodnia' : '🪙 Nagrody ligi miesiąca' ?>:</b>
    <?php if ($kind === 'week'): ?>
      <?= money($pz[0]) ?> / <?= money($pz[1]) ?> / <?= money($pz[2]) ?> PLN <span class="muted">— ze skarbca gry (zebrane prowizje), po zamknięciu tygodnia; liczą się gracze z choć jedną transakcją w tygodniu.</span>
    <?php else: ?>
      <?= (int) $pz[0] ?> / <?= (int) $pz[1] ?> / <?= (int) $pz[2] ?> Tokenów <span class="muted">— po zamknięciu miesiąca; liczą się gracze z choć jedną transakcją w miesiącu.</span>
    <?php endif; ?>
    <?php if ($idxPeriod !== null): ?><br><span class="muted">Indeks MAK40 w tym okresie:</span> <b class="<?= $idxPeriod >= 0 ? 'up' : 'down' ?>"><?= ($idxPeriod >= 0 ? '+' : '') . number_format($idxPeriod, 1, ',', ' ') ?>%</b> <span class="muted">— kolumna „vs MAK40” pokazuje, kto pobił rynek.</span><?php endif; ?>
    <?php if ($last): ?><br><span class="muted">Ostatnio rozliczony okres <?= h($last[0]['period']) ?>:</span>
      <?php foreach ($last as $r): ?><span class="tag"><?= ['🥇','🥈','🥉'][$r['rank']-1] ?? $r['rank'] ?> <?= h($r['username']) ?> <?= ($r['ret_pct'] >= 0 ? '+' : '') . number_format((float) $r['ret_pct'], 1, ',', ' ') ?>%<?= (float) $r['prize'] > 0 ? ' · ' . money($r['prize']) . ' PLN' : ((int) $r['tokens'] > 0 ? ' · ' . (int) $r['tokens'] . ' Tokenów' : '') ?></span> <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<div class="panel" style="padding:0;overflow:hidden">
  <div class="tbl-scroll">
    <table>
      <thead><tr><th style="width:52px">#</th><th>Gracz</th><th class="num">Stopa zwrotu</th><th class="num hide-m">vs MAK40</th><th>Drabinka</th><th class="num hide-m">Kapitał</th><th class="num hide-m">Dołączył</th></tr></thead>
      <tbody>
      <?php foreach ($players as $i => $p):
          $isMeRow = (int) $p['id'] === (int) $user['id']; ?>
        <tr <?= $isMeRow ? 'style="background:var(--info-bg)"' : '' ?>>
          <td class="mono" style="font-size:16px"><?= $medals[$i] ?? ($i + 1) ?></td>
          <td><a href="gracz.php?id=<?= (int) $p['id'] ?>" style="font-weight:700;color:var(--accent)"><?= h($p['username']) ?></a><?= trim((string) $p['title']) !== '' ? ' <span class="tag" style="color:var(--gold);border-color:var(--gold-border)">' . h($p['title']) . '</span>' : '' ?><?php $bn = (int) Engine::one("SELECT COUNT(*) FROM achievements WHERE user_id=?", [$p['id']]); ?><?= $bn > 0 ? " <span class='tag' title='zdobyte odznaki: $bn z " . count(Achievements::all()) . "'>🎖️$bn</span>" : '' ?><?= $isMeRow ? ' <span class="tag" style="color:var(--accent);border-color:var(--accent)">Ty</span>' : '' ?><?= $p['partial'] ? ' <span class="tag" title="Dołączył w trakcie okresu — liczony od swojego startu">od dołączenia</span>' : '' ?><?php if (!$isMeRow): $isFollowed = in_array((int) $p['id'], $following, true); ?>
            <form method="post" style="display:inline;margin-left:4px"><input type="hidden" name="<?= $isFollowed ? 'unfollow_id' : 'follow_id' ?>" value="<?= (int) $p['id'] ?>"><button class="linklike" title="<?= $isFollowed ? 'Przestań obserwować — zniknie z widżetu Znajomi na Pulpicie' : 'Obserwuj gracza — jego wynik zobaczysz w widżecie Znajomi na Pulpicie' ?>" style="background:none;border:0;cursor:pointer;font-size:14px;padding:0;vertical-align:middle"><?= $isFollowed ? '★' : '☆' ?></button></form><?php endif; ?></td>
          <td class="num"><?php if ($p['ret'] === null): ?><span class="muted">—</span>
            <?php else: ?><span class="chg <?= $p['ret'] >= 0 ? 'p' : 'n' ?>"><span class="ar"><?= $p['ret'] >= 0 ? '▲' : '▼' ?></span><?= number_format(abs($p['ret']), 1, ',', ' ') ?>%</span><?php endif; ?></td>
          <td class="num hide-m"><?php $alpha = $kind === 'all' ? Engine::benchmark((int) $p['id'])['alpha'] : ($idxPeriod !== null && $p['ret'] !== null ? $p['ret'] - $idxPeriod : null);
            if ($alpha === null): ?><span class="muted">—</span><?php else: ?><span class="mono <?= $alpha >= 0 ? 'up' : 'down' ?>" title="<?= $alpha >= 0 ? 'pobija indeks' : 'indeks wygrywa' ?>"><?= ($alpha >= 0 ? '+' : '') . number_format($alpha, 1, ',', ' ') ?> pp</span><?php endif; ?></td>
          <td><?php if ($p['rung']): $ra = Achievements::get($p['rung'][2]); ?><span class="soft" title="najwyższy zdobyty szczebel drabinki (od startu)"><?= h(($ra[0] ?? '📈') . ' ' . ($ra[1] ?? '+' . $p['rung'][0] . '%')) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td class="num mono hide-m"><?= money($p['equity']) ?></td>
          <td class="num muted hide-m">#<?= (int) $p['joined_session'] ?></td>
        </tr>
      <?php endforeach; if (!$players) echo "<tr><td class='muted' colspan=7 style='padding:20px'>Brak graczy.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>

<?php layout_footer();
