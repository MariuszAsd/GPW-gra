<?php
require __DIR__ . '/_boot.php';
$user = require_login();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); echo 'Brak dostępu (tylko admin).'; exit; }

$level = in_array($_GET['level'] ?? '', ['info', 'warn', 'error'], true) ? $_GET['level'] : '';
$source = in_array($_GET['source'] ?? '', ['qa', 'engine', 'player', 'auth', 'gm'], true) ? $_GET['source'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$h24 = ($_GET['h24'] ?? '') === '1';
$page = max(0, (int) ($_GET['p'] ?? 0));
$per = 300;

$where = []; $args = [];
if ($level !== '')  { $where[] = 'level = ?';  $args[] = $level; }
if ($source !== '') { $where[] = 'source = ?'; $args[] = $source; }
if ($q !== '')      { $where[] = '(event LIKE ? OR message LIKE ? OR context LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
if ($h24)           { $where[] = 'ts >= ?'; $args[] = date('Y-m-d H:i:s', time() - 86400); }
$wsql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) Engine::one("SELECT COUNT(*) FROM logs$wsql", $args);
$rows = Engine::all("SELECT * FROM logs$wsql ORDER BY id DESC LIMIT $per OFFSET " . ($page * $per), $args);
$counts = Engine::all("SELECT level, COUNT(*) c FROM logs GROUP BY level");
$byLevel = []; foreach ($counts as $c) $byLevel[$c['level']] = (int) $c['c'];
$pages = max(1, (int) ceil($total / $per));
$pgUrl = fn(int $pg) => 'gm_logs.php?' . http_build_query(array_filter(['level' => $level, 'source' => $source, 'q' => $q, 'h24' => $h24 ? '1' : null, 'p' => $pg ?: null]));

layout_header('Dziennik logów', $user, 'gm');
$lvlChip = fn($l) => $l === 'error' ? 'n' : ($l === 'warn' ? '' : 'p');
?>
<div class="page-head">
  <h1>📜 Dziennik logów</h1>
  <span class="muted">info: <?= $byLevel['info'] ?? 0 ?> · warn: <?= $byLevel['warn'] ?? 0 ?> · <b class="down">error: <?= $byLevel['error'] ?? 0 ?></b> · pasuje: <?= $total ?> (strona <?= $page + 1 ?>/<?= $pages ?>)</span>
</div>

<div class="panel" style="margin-bottom:14px">
  <form method="get" class="row" style="align-items:flex-end">
    <div><label>Poziom</label>
      <select name="level" style="width:130px">
        <option value="">wszystkie</option>
        <?php foreach (['error', 'warn', 'info'] as $l): ?><option value="<?= $l ?>" <?= $level === $l ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Źródło</label>
      <select name="source" style="width:130px">
        <option value="">wszystkie</option>
        <?php foreach (['qa', 'engine', 'player', 'auth', 'gm'] as $s): ?><option value="<?= $s ?>" <?= $source === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
      </select></div>
    <div><label>Szukaj (zdarzenie/komunikat)</label><input name="q" value="<?= h($q) ?>" style="width:200px" placeholder="np. ipo.debut, wyzwanie"></div>
    <label style="display:flex;align-items:center;gap:6px;padding-bottom:9px"><input type="checkbox" name="h24" value="1" <?= $h24 ? 'checked' : '' ?>> tylko 24h</label>
    <button class="btn sm">Filtruj</button>
    <a class="btn sm ghost" href="gm_logs.php?level=error&h24=1">🔥 Błędy 24h</a>
    <a class="btn sm ghost" href="gm.php">← Panel GM</a>
  </form>
  <form method="get" action="dziennik.php" class="inline" style="margin-top:8px">
    <label style="display:inline">📜 Dziennik gracza (id):</label>
    <input type="number" name="id" min="1" style="width:90px">
    <button class="btn sm ghost">Otwórz</button>
  </form>
</div>

<div class="panel" style="padding:0;overflow:hidden">
  <div class="tbl-scroll">
    <table>
      <thead><tr><th>Czas</th><th class="num">Tick</th><th>Poziom</th><th>Źródło</th><th>Zdarzenie</th><th>Komunikat / kontekst</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td class="mono muted" style="white-space:nowrap"><?= h($r['ts']) ?></td>
          <td class="num muted"><?= (int) $r['tick'] ?></td>
          <td><span class="chg <?= $lvlChip($r['level']) ?>"><?= h($r['level']) ?></span></td>
          <td class="mono"><?= h($r['source']) ?></td>
          <td class="mono"><?= h($r['event']) ?></td>
          <td><?= h($r['message']) ?><?php if ($r['context']): ?><div class="muted mono" style="font-size:11px;word-break:break-all"><?= h(mb_substr($r['context'], 0, 300)) ?></div><?php endif; ?></td>
        </tr>
      <?php endforeach; if (!$rows) echo "<tr><td class='muted' colspan=6 style='padding:20px'>Dziennik pusty.</td></tr>"; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($pages > 1): ?>
  <p class="muted" style="margin-top:10px">
    <?php if ($page > 0): ?><a href="<?= h($pgUrl($page - 1)) ?>">← nowsze</a><?php endif; ?>
    strona <?= $page + 1 ?> z <?= $pages ?>
    <?php if ($page < $pages - 1): ?><a href="<?= h($pgUrl($page + 1)) ?>">starsze →</a><?php endif; ?>
  </p>
<?php endif; ?>
<?php layout_footer();
