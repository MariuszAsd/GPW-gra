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
 * Rytm: Ipo::onRoll() na granicy sesji — co ipo_every_sessions debiutuje jedna
 * spółka w najmniej licznym sektorze, aż rynek osiągnie ipo_target spółek.
 * GM może też debiutować ręcznie (wybrany sektor) i zmieniać rytm/cel w panelu.
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

    /** Hak na granicy sesji: automatyczny debiut co N sesji, aż do celu. */
    public static function onRoll(int $session, int $tick): void
    {
        $everyV = Engine::one("SELECT v FROM game_state WHERE k='ipo_every_sessions'");
        // brak ustawienia: przy sesjach dziennych (godziny handlu) debiut co 1 dzień, przy tickowych co 3 sesje
        $every = ($everyV === false || $everyV === null)
            ? (Engine::marketHours()[0] ? 1 : self::DEFAULT_EVERY)
            : (int) $everyV;
        if ($every <= 0) return;   // 0 = automat wyłączony
        $target = (int) (Engine::one("SELECT v FROM game_state WHERE k='ipo_target'") ?: self::DEFAULT_TARGET);
        $count = (int) Engine::one("SELECT COUNT(*) FROM stocks");
        if ($count >= $target) return;
        $last = (int) (Engine::one("SELECT v FROM game_state WHERE k='ipo_last_session'") ?: 0);
        if ($session - $last < $every) return;
        Engine::setState('ipo_last_session', (string) $session);
        self::debut(null, $tick);
    }

    /** Debiut jednej spółki. $sectorSym = null -> najmniej liczny sektor. Zwraca [ticker, nazwa] albo null. */
    public static function debut(?string $sectorSym, int $tick): ?array
    {
        $pdo = Db::pdo();
        if ($sectorSym === null) {
            $row = Engine::row("SELECT se.symbol FROM sectors se
                                LEFT JOIN stocks st ON st.sector_id = se.id
                                GROUP BY se.id ORDER BY COUNT(st.id) ASC, se.id ASC LIMIT 1");
            $sectorSym = $row['symbol'] ?? 'TECH';
        }
        if (!isset(self::PARTS[$sectorSym])) return null;
        $secId = (int) (Engine::one("SELECT id FROM sectors WHERE symbol=?", [$sectorSym]) ?: 0);
        if ($secId <= 0) return null;

        // unikalność nazw/tickerów względem CAŁEJ bazy (nie tylko seeda)
        $usedNames = array_fill_keys(array_map('strtolower', Engine::col("SELECT name FROM stocks")), 1);
        $usedTickers = array_fill_keys(Engine::col("SELECT ticker FROM stocks"), 1);
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

        // DNA i wycena — jak przy zasiewie świata
        $sec = Engine::row("SELECT market_beta, volatility FROM sectors WHERE id=?", [$secId]);
        $r = mt_rand(1, 100);
        $price = $r <= 40 ? self::rf(8, 60) : ($r <= 70 ? self::rf(60, 200) : ($r <= 90 ? self::rf(200, 500) : self::rf(500, 900)));
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
             pe_target,base_profit,last_profit,last_eps,report_period,next_report_tick,dividend_payout)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute([
            $ticker, $name, $secId, self::DESC[$sectorSym] . ' Siedziba w ' . self::CITIES[array_rand(self::CITIES)] . '. Debiut giełdowy.',
            $price, $price, $price, $shares, self::rf(30, 95, 0),
            self::rf((float) $sec['market_beta'] - 0.3, (float) $sec['market_beta'] + 0.3),
            self::rf((float) $sec['volatility'] * 0.7, (float) $sec['volatility'] * 1.3),
            self::rf(0.7, 1.5), self::rf(0.8, 1.8), self::rf(0.5, 2.0), self::rf(0.6, 1.4), $growth, self::rf(0.7, 2.0),
            $pe, $base, $base, $eps, $period, $tick + $period, $payout,
        ]);
        $sid = (int) $pdo->lastInsertId();

        // korekta bazy indeksu (dzielnik): debiut nie zmienia wartości indeksu
        $baseMcap = (float) (Engine::one("SELECT v FROM game_state WHERE k='index_base_mcap'") ?: 0);
        if ($baseMcap > 0 && $mcapBefore > 0) {
            Engine::setState('index_base_mcap', (string) ($baseMcap * (($mcapBefore + $price * $shares) / $mcapBefore)));
        }

        // pakiety dla botów po cenie debiutu (animatorzy najwięcej) + korekta ich kapitału startowego
        $packet = ['mm' => 3000, 'trend' => 300, 'rsi' => 300, 'fundamental' => 300, 'news' => 200];
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

        // ogłoszenie: news z lekkim pozytywnym sentymentem debiutu + powiadomienia dla graczy
        $secName = (string) Engine::one("SELECT name FROM sectors WHERE id=?", [$secId]);
        $pdo->prepare("INSERT INTO news (headline,body,type,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                       VALUES (?,?,'POS','COMPANY',?,1,0.2,?,?,?)")->execute([
            "📈 Debiut na GPW-gra: $name ($ticker)",
            "Nowa spółka z sektora $secName weszła na giełdę. Cena debiutu: " . number_format($price, 2, ',', ' ')
            . " PLN, kapitalizacja " . number_format($price * $shares / 1e6, 0, ',', ' ') . " mln PLN.",
            $sid, $tick, $tick + 15, Db::now(),
        ]);
        foreach (Engine::all("SELECT id FROM users WHERE is_bot=0 AND role='player'") as $p) {
            Engine::notify((int) $p['id'], 'ipo', "📈 Debiut: $name ($ticker), sektor $secName, cena "
                . number_format($price, 2, ',', ' ') . " PLN. Nowa okazja na rynku!", 'stock.php?id=' . $sid);
        }
        Log::write('info', 'engine', 'ipo.debut', "$name ($ticker) debiutuje po " . number_format($price, 2, ',', ' ') . " PLN", ['sector' => $sectorSym, 'shares' => $shares]);
        return [$ticker, $name];
    }
}
