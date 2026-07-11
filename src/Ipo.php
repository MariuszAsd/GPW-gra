<?php
/**
 * Debiuty giełdowe (IPO): nowe spółki wchodzą na ŻYWY rynek — bez resetu świata.
 *
 * Generator (nazwy, tickery, DNA) jest spójny z seed.php. Debiut:
 *  - tworzy spółkę z pełnym DNA i polityką dywidendy,
 *  - rozdaje pakiety botom (animatorzy 3000 szt., reszta mniejsze) po cenie debiutu,
 *    z korektą ich start_equity (ranking strategii botów zostaje uczciwy),
 *  - koryguje bazę indeksu (jak dzielnik WIG) — debiut NIE zawyża indeksu,
 *  - dokłada płaską historię świec, raport startowy, news 📈 i powiadomienia.
 *
 * OFERTA PUBLICZNA Z ZAPISAMI (jak na prawdziwej GPW): automat najpierw OGŁASZA
 * ofertę (cena emisyjna, pula akcji, okno zapisów ~2 sesje), gracze zapisują się
 * po cenie emisyjnej (gotówka schodzi od razu, liczy się do kapitału), a na
 * zamknięciu okna dochodzi popyt instytucji (boty), przy nadsubskrypcji działa
 * REDUKCJA proporcjonalna (nadpłata wraca), spółka debiutuje, przydzielone akcje
 * lądują w portfelach. Duża nadsubskrypcja = gorący debiut (sentyment w górę),
 * słaby popyt = zimny. Rytm: co ipo_every_sessions, aż do ipo_target spółek.
 * GM może też debiutować ręcznie od razu (bez zapisów) i zmieniać rytm/cel.
 */
final class Ipo
{
    public const DEFAULT_EVERY  = 3;    // co ile sesji jeden debiut
    public const DEFAULT_TARGET = 90;   // docelowa liczba spółek na rynku

    /** Człony nazw per branża (spójne z seed.php). */
    private const PARTS = [
        'TECH' => [['Cyber','Data','Pixel','Quant','Nano','Neo','Soft','Byte','Cloud','Astra','Vertex','Hexa','Nova','Synte','Digi','Logi','Orbit','Kwark'],
                   ['Soft','Tech','Sys','Logic','Ware','Net','Lab','Code','Core','Link','Works','Dynamics']],
        'BIO'  => [['Gen','Bio','Medi','Vita','Neuro','Cell','Immuno','Helio','Onko','Kardio','Derma','Proteo','Sana','Farma'],
                   ['Pharm','Gen','Med','Lab','Cure','Plex','Zyme','Vita','Tech','Care']],
        'GAM'  => [['Game','Play','Story','Dream','Epic','Retro','Mega','Dark','Star','Wolf','Forge','Hype','Piksel','Runo'],
                   ['Studio','Games','Play','Media','Works','Arts','Interactive','Soft','Vision','Box']],
        'LUX'  => [['Aurum','Velvet','Prestige','Royal','Diament','Opal','Grand','Eleganza','Platyna','Karat','Szafir','Amber'],
                   [' Moda',' Motors',' Brands',' Estates',' Jewels',' Design',' Fashion',' House']],
        'IND'  => [['Stal','Mega','Poli','Ferro','Beton','Chemo','Metalo','Techno','Kruszo','Silo','Turbo','Prefa','Odlew','Walc'],
                   ['Bud','Chem','Stal','Metal','Prod','Mont','Plast','Mech','Tech','Mix']],
        'FIN'  => [['Kapital','Inwest','Kredo','Fides','Skarb','Certus','Solid','Meritum','Aureus','Lokata','Profit','Salda'],
                   [' Bank',' Invest',' Capital',' Finanse',' Leasing',' Fundusz',' Broker',' Ubezpieczenia']],
        'ENE'  => [['Energo','Solar','Wiatro','Atomo','Gazo','Hydro','Termo','Volt','Grid','Helio','Foto','Reaktor'],
                   ['Energia','Power','Gaz','Grid','Term','Watt','Volt','Net']],
        'HAN'  => [['Marko','Delika','Panda','Bazar','Kupiec','Galeria','Frux','Smako','Argo','Vendo','Prima','Cena','Kosz','Spichlerz'],
                   ['Pol','Market','Trade',' Retail','Shop','Express','Hurt','Dystrybucja']],
    ];
    private const DESC = [
        'TECH' => 'Tworzy oprogramowanie i rozwiązania chmurowe dla biznesu.',
        'BIO'  => 'Prowadzi badania nad nowymi terapiami i lekami.',
        'GAM'  => 'Produkuje gry wideo i treści rozrywkowe.',
        'LUX'  => 'Projektuje i sprzedaje dobra luksusowe z najwyższej półki.',
        'IND'  => 'Produkuje komponenty i materiały dla przemysłu.',
        'FIN'  => 'Świadczy usługi bankowe, inwestycyjne i ubezpieczeniowe.',
        'ENE'  => 'Wytwarza i dystrybuuje energię dla firm i gospodarstw.',
        'HAN'  => 'Prowadzi sieć sprzedaży detalicznej i e-commerce.',
    ];
    /** zakres C/Z per branża (jak w seed.php) */
    private const PE = ['TECH' => [18, 28], 'BIO' => [14, 24], 'GAM' => [16, 30], 'LUX' => [14, 22],
                        'IND' => [8, 14], 'FIN' => [8, 13], 'ENE' => [10, 16], 'HAN' => [10, 18]];
    private const CITIES = ['Warszawie', 'Krakowie', 'Wrocławiu', 'Poznaniu', 'Gdańsku', 'Katowicach', 'Łodzi', 'Szczecinie', 'Lublinie', 'Rzeszowie'];

    private static function rf(float $a, float $b, int $dec = 2): float
    {
        return round($a + mt_rand() / mt_getrandmax() * ($b - $a), $dec);
    }

    /** Hak na granicy sesji: rozlicz zamknięte oferty, potem ewentualnie ogłoś nową. */
    public static function onRoll(int $session, int $tick): void
    {
        // 1) oferty z zamkniętym oknem zapisów: redukcja, przydział, zwroty, debiut
        foreach (Engine::all("SELECT * FROM ipo_offers WHERE status='open' AND close_session <= ?", [$session]) as $o) {
            try { self::settleOffer($o, $tick); }
            catch (\Throwable $e) { Log::write('error', 'engine', 'ipo.settle', $e->getMessage(), ['offer' => $o['id']]); }
        }
        // pas i szelki: sierocy zapis (wyścig z rozliczeniem) dostaje pełny zwrot
        foreach (Engine::all("SELECT s.* FROM ipo_subs s JOIN ipo_offers o ON o.id = s.offer_id
                              WHERE s.allotted IS NULL AND o.status <> 'open'") as $orphan) {
            $st = Db::pdo()->prepare("UPDATE ipo_subs SET allotted=0, refund=? WHERE id=? AND allotted IS NULL");
            $st->execute([(float) $orphan['paid'], (int) $orphan['id']]);
            if ($st->rowCount() === 0) continue;
            Db::pdo()->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([(float) $orphan['paid'], (int) $orphan['user_id']]);
            Engine::notify((int) $orphan['user_id'], 'ipo', '📢 Twój zapis wpadł już po rozliczeniu oferty — pełny zwrot ' . number_format((float) $orphan['paid'], 2, ',', ' ') . ' PLN.', 'ipo.php');
            Log::write('warn', 'engine', 'ipo.orphan', 'sierocy zapis #' . $orphan['id'] . ' zwrócony', []);
        }
        // 2) nowa oferta wg rytmu (tylko gdy żadna nie jest otwarta)
        $everyV = Engine::one("SELECT v FROM game_state WHERE k='ipo_every_sessions'");
        // brak ustawienia: przy sesjach dziennych (godziny handlu) oferta co 1 dzień, przy tickowych co 3 sesje
        $every = ($everyV === false || $everyV === null)
            ? (Engine::marketHours()[0] ? 1 : self::DEFAULT_EVERY)
            : (int) $everyV;
        if ($every <= 0) return;   // 0 = automat wyłączony
        $target = (int) (Engine::one("SELECT v FROM game_state WHERE k='ipo_target'") ?: self::DEFAULT_TARGET);
        $count = (int) Engine::one("SELECT COUNT(*) FROM stocks");
        if ($count >= $target) return;
        if (Engine::one("SELECT id FROM ipo_offers WHERE status='open'")) return;
        $last = (int) (Engine::one("SELECT v FROM game_state WHERE k='ipo_last_session'") ?: 0);
        if ($session - $last < $every) return;
        Engine::setState('ipo_last_session', (string) $session);
        self::createOffer($session, $tick);
    }

    /** Ogłoszenie oferty publicznej: spółka, cena emisyjna, pula, okno zapisów ~2 sesje. */
    public static function createOffer(int $session, int $tick): ?int
    {
        $c = self::pickCompany(null);
        if ($c === null) return null;
        // pula dla inwestorów: oferta warta ~300-800 tys. PLN (odczuwalna, ale nie przewraca rynku)
        $sharesOffered = max(200, (int) round(mt_rand(300000, 800000) / $c['price']));
        $perPlayerMax  = max(1, intdiv($sharesOffered, 3));
        $close = $session + 2;   // zapisy przez ~2 sesje, rozliczenie na otwarciu sesji #close
        $pdo = Db::pdo();
        $pdo->prepare("INSERT INTO ipo_offers (name, ticker, sector_id, sector_symbol, price, shares_offered, per_player_max, close_session, status, created_at)
                       VALUES (?,?,?,?,?,?,?,?, 'open', ?)")
            ->execute([$c['name'], $c['ticker'], $c['sector_id'], $c['sector_symbol'], $c['price'], $sharesOffered, $perPlayerMax, $close, Db::now()]);
        $oid = (int) $pdo->lastInsertId();
        $secName = (string) Engine::one("SELECT name FROM sectors WHERE id=?", [$c['sector_id']]);
        $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,kind,publish_tick,expire_tick,published_at)
                       VALUES (?,?,'NEU','MARKET',NULL,1,0,'fundamental',?,?,?)")->execute([
            "📢 Oferta publiczna: {$c['name']} ({$c['ticker']}) — ruszają zapisy",
            "Spółka z sektora $secName idzie na giełdę. Cena emisyjna " . number_format($c['price'], 2, ',', ' ')
            . " PLN, pula dla inwestorów " . number_format($sharesOffered, 0, ',', ' ')
            . " akcji. Zapisy w zakładce IPO do końca sesji #" . ($close - 1) . ". Przy nadsubskrypcji zapisy są redukowane proporcjonalnie.",
            $tick, $tick + 40, Db::now(),
        ]);
        foreach (Engine::all("SELECT id FROM users WHERE is_bot=0 AND role='player'") as $p) {
            Engine::notify((int) $p['id'], 'ipo', "📢 Zapisy na IPO: {$c['name']} ({$c['ticker']}) po "
                . number_format($c['price'], 2, ',', ' ') . " PLN. Okno zamyka się z końcem sesji #" . ($close - 1) . '.', 'ipo.php');
        }
        Log::write('info', 'engine', 'ipo.offer', "{$c['name']} ({$c['ticker']}): zapisy do sesji " . ($close - 1), ['price' => $c['price'], 'shares' => $sharesOffered]);
        return $oid;
    }

    /** Zapis gracza na otwartą ofertę (gotówka schodzi od razu; jeden zapis na ofertę). */
    public static function subscribe(int $userId, int $offerId, int $qty): array
    {
        $o = Engine::row("SELECT * FROM ipo_offers WHERE id=? AND status='open'", [$offerId]);
        if (!$o) return [false, 'Ta oferta jest już zamknięta.'];
        [$session] = Engine::sessionInfo();
        if ($session >= (int) $o['close_session']) return [false, 'Okno zapisów właśnie się zamknęło — czekaj na przydział.'];
        $u = Engine::row("SELECT role FROM users WHERE id=?", [$userId]);
        if (!$u || $u['role'] !== 'player') return [false, 'Zapisy tylko dla graczy (konto główne).'];
        if ($qty < 1 || $qty > (int) $o['per_player_max']) {
            return [false, 'Ilość: 1–' . number_format((int) $o['per_player_max'], 0, ',', ' ') . ' akcji na gracza.'];
        }
        $cost = round($qty * (float) $o['price'], 2);
        $pdo = Db::pdo();
        $st = $pdo->prepare("UPDATE users SET cash = cash - ? WHERE id = ? AND cash >= ?");
        $st->execute([$cost, $userId, $cost]);
        if ($st->rowCount() === 0) return [false, 'Za mało gotówki: zapis kosztuje ' . number_format($cost, 2, ',', ' ') . ' PLN.'];
        try {
            // INSERT warunkowy: oferta musi być NADAL otwarta (wyścig z rozliczeniem na rollu
            // zostawiałby sierocy zapis — gotówka pobrana, nigdy nierozliczona)
            $ins = $pdo->prepare("INSERT INTO ipo_subs (offer_id, user_id, qty, paid, created_at)
                                  SELECT ?,?,?,?,? FROM ipo_offers WHERE id=? AND status='open'");
            $ins->execute([$offerId, $userId, $qty, $cost, Db::now(), $offerId]);
            if ($ins->rowCount() === 0) {
                $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$cost, $userId]);
                return [false, 'Oferta właśnie została rozliczona — zapis niemożliwy, środki wróciły.'];
            }
        } catch (\Throwable $e) {
            $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$cost, $userId]);
            return [false, 'Masz już zapis na tę ofertę (zapis jest wiążący do przydziału).'];
        }
        Engine::journal($userId, 'ipo', "📢 Zapis na IPO {$o['name']}: $qty akcji po " . number_format((float) $o['price'], 2, ',', ' ')
            . ' PLN (' . number_format($cost, 2, ',', ' ') . ' PLN). Przydział w sesji #' . (int) $o['close_session'] . '.', 'ipo.php');
        Log::write('info', 'player', 'ipo.subscribe', "zapis #$offerId: $qty szt.", ['user_id' => $userId, 'cost' => $cost]);
        return [true, "Zapisano: $qty akcji za " . number_format($cost, 2, ',', ' ')
            . ' PLN. Przy nadsubskrypcji dostaniesz mniej, a różnica wróci na konto. Kwota zapisu liczy się do Twojego kapitału.'];
    }

    /** Rozliczenie oferty: popyt instytucji, redukcja, przydział, zwroty, debiut z temperaturą. */
    private static function settleOffer(array $o, int $tick): void
    {
        $pdo = Db::pdo();
        // oznacz atomowo — powtórny roll nie rozliczy drugi raz
        $st = $pdo->prepare("UPDATE ipo_offers SET status='done' WHERE id=? AND status='open'");
        $st->execute([(int) $o['id']]);
        if ($st->rowCount() === 0) return;

        $subs = Engine::all("SELECT * FROM ipo_subs WHERE offer_id=?", [(int) $o['id']]);
        $demandPlayers = array_sum(array_map(fn($s2) => (int) $s2['qty'], $subs));
        $demandBots = (int) round((int) $o['shares_offered'] * self::rf(0.5, 2.6));   // instytucje: 0.5x-2.6x puli
        $total = $demandPlayers + $demandBots;
        $offered = max(1, (int) $o['shares_offered']);
        $ratio = $total > $offered ? $offered / $total : 1.0;
        $reduction = round((1 - $ratio) * 100, 2);
        $heat = $total / $offered;   // temperatura debiutu: >1 nadsubskrypcja

        // debiut z ceną emisyjną i sentymentem zależnym od popytu
        $impact = max(-0.35, min(0.9, 0.4 * ($heat - 1)));
        $preset = ['name' => $o['name'], 'ticker' => $o['ticker'], 'sector_symbol' => $o['sector_symbol'],
                   'sector_id' => (int) $o['sector_id'], 'price' => (float) $o['price']];
        $extra = $reduction > 0
            ? 'Zapisy zredukowano o ' . number_format($reduction, 1, ',', ' ') . '% — popyt ' . number_format($heat, 1, ',', ' ') . 'x przekroczył pulę.'
            : 'Zapisy pokryły ' . number_format($heat * 100, 0, ',', ' ') . '% puli — bez redukcji.';
        $res = self::debut(null, $tick, $preset, $impact, $extra);

        if ($res === null) {   // awaryjnie (kolizja nazwy): pełne zwroty
            foreach ($subs as $s2) {
                $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([(float) $s2['paid'], (int) $s2['user_id']]);
                $pdo->prepare("UPDATE ipo_subs SET allotted=0, refund=? WHERE id=?")->execute([(float) $s2['paid'], (int) $s2['id']]);
                Engine::notify((int) $s2['user_id'], 'ipo', '📢 Oferta ' . $o['name'] . ' odwołana — pełny zwrot zapisu.', 'ipo.php');
            }
            $pdo->prepare("UPDATE ipo_offers SET status='cancelled' WHERE id=?")->execute([(int) $o['id']]);
            Log::write('warn', 'engine', 'ipo.cancel', $o['name'] . ': debiut nieudany, zwroty', []);
            return;
        }
        $sid = (int) Engine::one("SELECT id FROM stocks WHERE ticker=?", [$o['ticker']]);

        // przydział + zwroty dla graczy (akcje po cenie emisyjnej prosto do portfela)
        foreach ($subs as $s2) {
            $uid = (int) $s2['user_id'];
            $allot = (int) floor((int) $s2['qty'] * $ratio);
            $refund = round((float) $s2['paid'] - $allot * (float) $o['price'], 2);
            $pdo->prepare("UPDATE ipo_subs SET allotted=?, refund=? WHERE id=?")->execute([$allot, $refund, (int) $s2['id']]);
            if ($refund > 0) $pdo->prepare("UPDATE users SET cash = cash + ? WHERE id = ?")->execute([$refund, $uid]);
            if ($allot > 0 && $sid > 0) {
                Engine::ensureWallet($uid, $sid);
                $pdo->prepare("UPDATE wallets SET qty = qty + ?, avg_price = ? WHERE user_id=? AND stock_id=?")
                    ->execute([$allot, (float) $o['price'], $uid, $sid]);   // świeża spółka: avg = cena emisyjna
            }
            $msg = $allot > 0
                ? "📢 Przydział IPO {$o['name']}: $allot z " . (int) $s2['qty'] . ' akcji po ' . number_format((float) $o['price'], 2, ',', ' ') . ' PLN'
                  . ($refund > 0 ? ' (redukcja — zwrot ' . number_format($refund, 2, ',', ' ') . ' PLN)' : '') . '. Powodzenia na debiucie!'
                : "📢 IPO {$o['name']}: redukcja zjadła cały Twój zapis — pełny zwrot " . number_format($refund, 2, ',', ' ') . ' PLN.';
            Engine::notify($uid, 'ipo', $msg, $sid > 0 ? 'stock.php?id=' . $sid : 'ipo.php');
            Engine::journal($uid, 'ipo', $msg, $sid > 0 ? 'stock.php?id=' . $sid : 'ipo.php');
        }
        $pdo->prepare("UPDATE ipo_offers SET stock_id=?, demand_bots=?, reduction_pct=? WHERE id=?")
            ->execute([$sid ?: null, $demandBots, $reduction, (int) $o['id']]);
        Log::write('info', 'engine', 'ipo.allot', $o['name'] . ": popyt {$heat}x, redukcja $reduction%", ['players' => $demandPlayers, 'bots' => $demandBots]);
    }

    /** Losuje spółkę do debiutu/oferty: sektor, nazwa, ticker, cena emisyjna. Null = pula nazw wyczerpana. */
    public static function pickCompany(?string $sectorSym): ?array
    {
        if ($sectorSym === null) {
            $row = Engine::row("SELECT se.symbol FROM sectors se
                                LEFT JOIN stocks st ON st.sector_id = se.id
                                GROUP BY se.id ORDER BY COUNT(st.id) ASC, se.id ASC LIMIT 1");
            $sectorSym = $row['symbol'] ?? 'TECH';
        }
        if (!isset(self::PARTS[$sectorSym])) return null;
        $secId = (int) (Engine::one("SELECT id FROM sectors WHERE symbol=?", [$sectorSym]) ?: 0);
        if ($secId <= 0) return null;

        // unikalność nazw/tickerów względem CAŁEJ bazy (nie tylko seeda) + otwartych ofert
        $usedNames = array_fill_keys(array_map('strtolower', array_merge(
            Engine::col("SELECT name FROM stocks"), Engine::col("SELECT name FROM ipo_offers WHERE status='open'"))), 1);
        $usedTickers = array_fill_keys(array_merge(
            Engine::col("SELECT ticker FROM stocks"), Engine::col("SELECT ticker FROM ipo_offers WHERE status='open'")), 1);
        [$pre, $suf] = self::PARTS[$sectorSym];
        $name = null;
        for ($try = 0; $try < 300; $try++) {
            $cand = $pre[array_rand($pre)] . $suf[array_rand($suf)];
            if (strtolower(substr($cand, 0, 4)) === strtolower(trim(substr($cand, -4)))) continue;
            if (!isset($usedNames[strtolower($cand)])) { $name = $cand; break; }
        }
        if ($name === null) return null;   // pula nazw branży wyczerpana
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT', $name) ?: $name;
        $letters = strtoupper(preg_replace('/[^A-Za-z]/', '', $ascii));
        if (strlen($letters) < 2) $letters = 'X' . strtoupper(substr(md5($name), 0, 3));
        $ticker = null;
        foreach ([substr($letters, 0, 3), substr($letters, 0, 2) . substr($letters, -1), substr($letters, 0, 4)] as $t) {
            if (strlen($t) >= 2 && !isset($usedTickers[$t])) { $ticker = $t; break; }
        }
        if ($ticker === null) for ($i = 2; $i < 100; $i++) { $t = substr($letters, 0, 3) . $i; if (!isset($usedTickers[$t])) { $ticker = $t; break; } }
        if ($ticker === null) return null;

        $r = mt_rand(1, 100);
        $price = $r <= 40 ? self::rf(8, 60) : ($r <= 70 ? self::rf(60, 200) : ($r <= 90 ? self::rf(200, 500) : self::rf(500, 900)));
        return ['sector_symbol' => $sectorSym, 'sector_id' => $secId, 'name' => $name, 'ticker' => $ticker, 'price' => $price];
    }

    /**
     * Debiut jednej spółki. $sectorSym = null -> najmniej liczny sektor.
     * $preset (z oferty IPO): gotowe name/ticker/sector/price zamiast losowania.
     * $impact: sentyment newsa debiutu (temperatura po zapisach). Zwraca [ticker, nazwa] albo null.
     */
    public static function debut(?string $sectorSym, int $tick, ?array $preset = null, float $impact = 0.2, string $extraBody = ''): ?array
    {
        $pdo = Db::pdo();
        $c = $preset ?? self::pickCompany($sectorSym);
        if ($c === null) return null;
        $sectorSym = $c['sector_symbol'];
        $secId = (int) $c['sector_id'];
        $name = $c['name']; $ticker = $c['ticker']; $price = (float) $c['price'];
        if ((int) Engine::one("SELECT COUNT(*) FROM stocks WHERE ticker=? OR LOWER(name)=LOWER(?)", [$ticker, $name]) > 0) return null;
        if (!isset(self::PARTS[$sectorSym]) || $secId <= 0 || $price <= 0) return null;

        // DNA i wycena — jak przy zasiewie świata
        $sec = Engine::row("SELECT market_beta, volatility FROM sectors WHERE id=?", [$secId]);
        $shares = max(200000, min(20000000, (int) round(mt_rand(30, 900) * 1e6 / $price)));
        $pe = self::rf(self::PE[$sectorSym][0], self::PE[$sectorSym][1], 1);
        $base = round($price * $shares / ($pe * 12), 2);
        $eps = round($base * 12 / $shares, 4);
        $growth = self::rf(0, 0.04, 3);
        $payout = $growth >= 0.025 ? self::rf(0, 0.25) : self::rf(0.30, 0.65);
        if (mt_rand(1, 100) <= 15) $payout = 0;
        $period = (int) (Engine::one("SELECT v FROM game_state WHERE k='ticks_per_month'") ?: 100);

        $mcapBefore = (float) Engine::one("SELECT COALESCE(SUM(price * total_shares), 0) FROM stocks");

        $pdo->prepare("INSERT INTO stocks
            (ticker,name,sector_id,description,price,fundamental,day_open_price,total_shares,free_float,
             beta,volatility,liquidity,news_impact,news_frequency,financial_resilience,growth_potential,aggressiveness,
             pe_target,base_profit,last_profit,last_eps,report_period,next_report_tick,dividend_payout,tech_affinity)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $ticker, $name, $secId, self::DESC[$sectorSym] . ' Siedziba w ' . self::CITIES[array_rand(self::CITIES)] . '. Debiut giełdowy.',
            $price, $price, $price, $shares, self::rf(30, 95, 0),
            self::rf((float) $sec['market_beta'] - 0.3, (float) $sec['market_beta'] + 0.3),
            self::rf((float) $sec['volatility'] * 0.7, (float) $sec['volatility'] * 1.3),
            self::rf(0.7, 1.5), self::rf(0.8, 1.8), self::rf(0.5, 2.0), self::rf(0.6, 1.4), $growth, self::rf(0.7, 2.0),
            $pe, $base, $base, $eps, $period, $tick + $period, $payout, self::rf(0.15, 0.85),
        ]);
        $sid = (int) $pdo->lastInsertId();

        // korekta bazy indeksu (dzielnik): debiut nie zmienia wartości indeksu
        $baseMcap = (float) (Engine::one("SELECT v FROM game_state WHERE k='index_base_mcap'") ?: 0);
        if ($baseMcap > 0 && $mcapBefore > 0) {
            Engine::setState('index_base_mcap', (string) ($baseMcap * (($mcapBefore + $price * $shares) / $mcapBefore)));
        }

        // pakiety dla botów po cenie debiutu (animatorzy najwięcej) + korekta ich kapitału startowego
        $packet = ['mm' => 3000, 'trend' => 300, 'rsi' => 300, 'fundamental' => 300, 'news' => 200, 'tech' => 300];
        $wIns = $pdo->prepare("INSERT INTO wallets (user_id, stock_id, qty, avg_price) VALUES (?,?,?,?)");
        $uUp  = $pdo->prepare("UPDATE users SET start_equity = start_equity + ? WHERE id=?");
        foreach (Engine::all("SELECT u.id, b.strategy FROM users u JOIN bots b ON b.user_id = u.id WHERE u.is_bot = 1") as $b) {
            $q = $packet[$b['strategy']] ?? 0;
            if ($q <= 0) continue;
            $wIns->execute([(int) $b['id'], $sid, $q, $price]);
            $uUp->execute([round($q * $price, 2), (int) $b['id']]);
        }

        // płaska historia świec (żeby wykres miał punkt zaczepienia) + raport startowy
        $cIns = $pdo->prepare("INSERT INTO candles (stock_id,t,o,h,l,c,v) VALUES (?,?,?,?,?,?,0)");
        for ($t = $tick - 5; $t <= $tick; $t++) $cIns->execute([$sid, $t, $price, $price, $price, $price]);
        $rev = round($base / 0.15, 2);
        $pdo->prepare("INSERT INTO financial_reports (stock_id,tick,period,report_date,revenue,costs,net_profit,eps,expected_eps,surprise_pct)
                       VALUES (?,?,'Prospekt emisyjny',?,?,?,?,?,?,0)")
            ->execute([$sid, $tick, Db::now(), $rev, round($rev - $base, 2), $base, $eps, $eps]);

        // ogłoszenie: news z sentymentem zależnym od temperatury zapisów + powiadomienia
        $secName = (string) Engine::one("SELECT name FROM sectors WHERE id=?", [$secId]);
        $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,kind,publish_tick,expire_tick,published_at)
                       VALUES (?,?,?,'COMPANY',?,1,?,'sentiment',?,?,?)")->execute([
            ($impact >= 0.35 ? '🔥 Gorący debiut' : ($impact < 0 ? '🥶 Chłodny debiut' : '📈 Debiut')) . " na GPW-gra: $name ($ticker)",
            "Nowa spółka z sektora $secName weszła na giełdę. Cena debiutu: " . number_format($price, 2, ',', ' ')
            . " PLN, kapitalizacja " . number_format($price * $shares / 1e6, 0, ',', ' ') . " mln PLN."
            . ($extraBody !== '' ? ' ' . $extraBody : ''),
            $impact >= 0 ? 'POS' : 'NEG', $sid, round($impact, 2), $tick, $tick + 15, Db::now(),
        ]);
        foreach (Engine::all("SELECT id FROM users WHERE is_bot=0 AND role='player'") as $p) {
            Engine::notify((int) $p['id'], 'ipo', "📈 Debiut: $name ($ticker), sektor $secName, cena "
                . number_format($price, 2, ',', ' ') . " PLN. Nowa okazja na rynku!", 'stock.php?id=' . $sid);
        }
        Log::write('info', 'engine', 'ipo.debut', "$name ($ticker) debiutuje po " . number_format($price, 2, ',', ' ') . " PLN", ['sector' => $sectorSym, 'shares' => $shares]);
        return [$ticker, $name];
    }
}
