<?php
// Diagnoza ruchów rynku z publicznych API (tylko odczyt). Wypisuje: przebieg sesji (średnia zmiana spółek,
// ile spadło/wzrosło mocno), anomalie cen (kurs <= 0, skoki ponad regułę widełek, luki otwarcia) i newsy
// rynkowe/sektorowe z ostatnich sesji — żeby odróżnić krach z silnika zdarzeń od błędu.
$base = rtrim(getenv('APP_URL'), '/');
$get = function (string $p) use ($base) {
    for ($i = 0; $i < 3; $i++) { $r = @file_get_contents($base . '/' . $p); if ($r !== false) return json_decode($r, true); usleep(500000); }
    return null;
};
$m = $get('api_market.php');
if (!$m || empty($m['ok'])) { echo "::error::api_market nie odpowiada\n"; exit(1); }
echo "Sesja {$m['session']}, indeks {$m['index']['value']} ({$m['index']['chg']}% dziś), spółek: " . count($m['data']) . "\n";
$ser = $m['index']['series']; echo "Indeks, ostatnie 120 ticków: min " . min($ser) . ", max " . max($ser) . ", pierwszy {$ser[0]}, ostatni " . end($ser) . "\n\n";

$bySess = []; $anom = []; $news = [];
foreach (array_keys($m['data']) as $id) {
    $d = $get("api_chart.php?id=$id&range=m&iv=d");
    $cs = $d['candles'] ?? [];
    $prev = null;
    foreach ($cs as $c) {
        $s = (int) $c['s'];
        if ($c['o'] <= 0 || $c['c'] <= 0 || $c['l'] <= 0) $anom[] = "spółka #$id sesja $s: cena <= 0 (o={$c['o']} l={$c['l']} c={$c['c']})";
        $chg = $c['o'] > 0 ? ($c['c'] / $c['o'] - 1) * 100 : 0;
        $bySess[$s][] = $chg;
        if (abs($chg) > 95) $anom[] = sprintf("spółka #%d sesja %d: zmiana w sesji %+.1f%% (ponad regułę widełek)", $id, $s, $chg);
        if ($prev && $prev['c'] > 0) {
            $gap = ($c['o'] / $prev['c'] - 1) * 100;
            if (abs($gap) > 25) $anom[] = sprintf("spółka #%d sesja %d: luka otwarcia %+.1f%% względem zamknięcia poprzedniej sesji", $id, $s, $gap);
        }
        $prev = $c;
    }
    // newsy z ostatnich ~7 sesji (tryb tickowy niesie znaczniki)
    $t = $get("api_chart.php?id=$id&range=t");
    foreach (($t['news'] ?? []) as $n) if ($n['scope'] !== 'COMPANY' || abs($n['imp']) >= 0.3) $news[$n['scope'] . '|' . $n['head']] = $n;
    usleep(150000);
}
ksort($bySess);
echo "PRZEBIEG SESJI (zmiana od otwarcia do zamknięcia, wszystkie spółki):\n";
printf("%-7s %8s %8s %6s %6s %6s\n", 'sesja', 'średnio', 'mediana', '<-10%', '>+10%', 'spółek');
foreach ($bySess as $s => $v) {
    sort($v); $n = count($v); $avg = array_sum($v) / $n; $med = $v[intdiv($n, 2)];
    $dn = count(array_filter($v, fn($x) => $x < -10)); $up = count(array_filter($v, fn($x) => $x > 10));
    printf("%-7d %+7.2f%% %+7.2f%% %6d %6d %6d\n", $s, $avg, $med, $dn, $up, $n);
}
echo "\nANOMALIE (" . count($anom) . "):\n" . ($anom ? implode("\n", array_slice($anom, 0, 40)) : "brak") . "\n";
echo "\nNEWSY RYNKOWE/SEKTOROWE i mocne spółkowe z ostatnich sesji (" . count($news) . "):\n";
foreach ($news as $n) printf("[%s %s%s imp %+.2f] %s\n", $n['scope'], $n['type'], $n['espi'] ? ' ESPI' : '', $n['imp'], $n['head']);
