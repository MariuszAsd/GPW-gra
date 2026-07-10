<?php
require __DIR__ . '/_boot.php';
$user = require_login();
if (($user['role'] ?? '') !== 'admin') { http_response_code(403); echo 'Brak dostępu (tylko admin).'; exit; }

$level = in_array($_GET['level'] ?? '', ['info', 'warn', 'error'], true) ? $_GET['level'] : '';
$source = in_array($_GET['source'] ?? '', ['qa', 'engine', 'player', 'auth', 'gm'], true) ? $_GET['source'] : '';

$where = []; $args = [];
if ($level !== '')  { $where[] = 'level = ?';  $args[] = $level; }
if ($source !== '') { $where[] = 'source = ?'; $args[] = $source; }
$sql = "SELECT * FROM logs" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY id DESC LIMIT 300";
$rows = Engine::all($sql, $args);
$counts = Engine::all("SELECT level, COUNT(*) c FROM logs GROUP BY level");
$byLevel = []; foreach ($counts as $c) $byLevel[$c['level']] = (int) $c['c'];

layout_header('Dziennik logów', $user, 'gm');
$lvlChip = fn($l) => $l === 'error' ? 'n' : ($l === 'warn' ? '' : 'p');
?>
<div class="page-head">
  <h1>📜 Dziennik logów</h1>
  <span class="muted">info: <?= $byLevel['info'] ?? 0 ?> · warn: <?= $byLevel['warn'] ?? 0 ?> · <b class="down">error: <?= $byLevel['error'] ?? 0 ?></b> · pokazuję max 300 najnowszych</span>
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
    <button class="btn sm">Filtruj</button>
    <a class="btn sm ghost" href="gm.php">← Panel GM</a>
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
<?php layout_footer();
