<?php
/**
 * Katalog wydarzeń świata — DANE, nie kod. Każde wydarzenie ma PROFIL SKUTKÓW
 * w trzech warstwach (rynek / branża / spółka) i może uruchamiać kolejne.
 *
 * Pola szablonu:
 *   scope    MARKET | SECTOR | COMPANY (co jest celem nagłówka [T])
 *   type     POS | NEG | NEU
 *   impact   natychmiastowy wpływ na fundament %/tick w szczycie (zanika przez duration)
 *   duration czas zanikania wpływu newsowego (ticki)
 *   weight   waga losowania (0 = tylko jako follow-up / ręcznie z GM)
 *   effects  lista modyfikatorów CZASOWYCH [cel, pole, delta, czas_ticków]:
 *            cel: market | all_stocks(volatility) | sector | sector:SYM | stock
 *            pole: sentiment | trend | profit_climate | volatility | profit_trend | dividend_pause
 *            (nakładają się na wartości bazowe i SAME wygasają — nie psują ustawień GM)
 *   follow_ups  [[code, szansa%, delayMin, delayMax], ...] — niezależne rzuty (kaskady)
 *   resolve     [[szansa%, code], ...] — WYKLUCZAJĄCE rozstrzygnięcie plotki (dziedziczy cel)
 */
final class EventCatalog
{
    public static function all(): array
    {
        return [
            // ================= RYNEK (globalne) =================
            'krach' => [
                'scope' => 'MARKET', 'type' => 'NEG', 'impact' => -1.5, 'duration' => 20, 'weight' => 4,
                'head' => '🚨 KRACH NA GIEŁDZIE! Panika — inwestorzy masowo uciekają od akcji',
                'body' => 'Gwałtowna wyprzedaż na całym rynku. Fundamenty spółek pod silną presją przez najbliższe sesje.',
                'effects' => [['all_stocks', 'volatility', 0.35, 40]],
                'follow_ups' => [['kryzys_zaufania_fin', 35, 10, 25]],
            ],
            'hossa' => [
                'scope' => 'MARKET', 'type' => 'POS', 'impact' => 1.2, 'duration' => 20, 'weight' => 4,
                'head' => '🚀 HOSSA! Euforia zakupów rozlewa się po całym rynku',
                'body' => 'Kapitał płynie na giełdę szerokim strumieniem. Wyceny rosną na wszystkich parkietach.',
                'effects' => [['all_stocks', 'volatility', 0.2, 30]],
            ],
            'stopy_gora' => [
                'scope' => 'MARKET', 'type' => 'NEG', 'impact' => -0.4, 'duration' => 15, 'weight' => 6,
                'head' => '🏦 Bank centralny podnosi stopy procentowe',
                'body' => 'Droższy kredyt: banki zarabiają na odsetkach, cierpią dobra luksusowe i przemysł.',
                'effects' => [['market', 'sentiment', -0.02, 40], ['sector:FIN', 'profit_climate', 2.5, 100],
                              ['sector:LUX', 'profit_climate', -2.0, 100], ['sector:IND', 'profit_climate', -1.0, 100]],
            ],
            'stopy_dol' => [
                'scope' => 'MARKET', 'type' => 'POS', 'impact' => 0.4, 'duration' => 15, 'weight' => 6,
                'head' => '🏦 Bank centralny tnie stopy procentowe',
                'body' => 'Tani kredyt napędza konsumpcję i inwestycje; bankom kurczy się marża odsetkowa.',
                'effects' => [['market', 'sentiment', 0.02, 40], ['sector:FIN', 'profit_climate', -1.5, 100],
                              ['sector:LUX', 'profit_climate', 2.0, 100], ['sector:TECH', 'trend', 0.05, 40]],
            ],
            'inflacja_gora' => [
                'scope' => 'MARKET', 'type' => 'NEG', 'impact' => -0.35, 'duration' => 15, 'weight' => 6,
                'head' => '📈 Inflacja znacznie powyżej prognoz',
                'body' => 'Rosnące koszty zjadają marże handlu; drożejąca energia sprzyja jej wytwórcom.',
                'effects' => [['market', 'sentiment', -0.015, 30], ['sector:HAN', 'profit_climate', -2.0, 100],
                              ['sector:ENE', 'profit_climate', 1.5, 100]],
            ],
            'pmi_rekord' => [
                'scope' => 'MARKET', 'type' => 'POS', 'impact' => 0.35, 'duration' => 15, 'weight' => 6,
                'head' => '🏭 Rekordowy odczyt PMI — gospodarka rozpędzona',
                'body' => 'Fabryki pracują pełną parą. Przemysł i handel łapią wiatr w żagle.',
                'effects' => [['market', 'sentiment', 0.015, 30], ['sector:IND', 'profit_climate', 2.0, 100],
                              ['sector:HAN', 'profit_climate', 1.0, 100]],
            ],
            'kryzys_energetyczny' => [
                'scope' => 'MARKET', 'type' => 'NEG', 'impact' => -0.5, 'duration' => 18, 'weight' => 4,
                'head' => '⚡ Kryzys energetyczny! Ceny prądu i gazu szybują',
                'body' => 'Energetyka zarabia krocie, energochłonny przemysł i handel liczą straty.',
                'effects' => [['market', 'sentiment', -0.01, 50], ['sector:ENE', 'profit_climate', 3.0, 120],
                              ['sector:IND', 'profit_climate', -2.0, 120], ['sector:HAN', 'profit_climate', -1.0, 120]],
            ],
            'pakiet_stymulacyjny' => [
                'scope' => 'MARKET', 'type' => 'POS', 'impact' => 0.5, 'duration' => 18, 'weight' => 4,
                'head' => '🏗️ Rząd ogłasza wielki pakiet inwestycyjny',
                'body' => 'Miliardy popłyną w infrastrukturę — najwięcej skorzysta przemysł i finansujące go banki.',
                'effects' => [['market', 'sentiment', 0.012, 50], ['sector:IND', 'profit_climate', 2.5, 120],
                              ['sector:FIN', 'profit_climate', 1.0, 120]],
            ],

            // ================= SEKTOR (cel: branża) =================
            'sector_panic' => [
                'scope' => 'SECTOR', 'type' => 'NEG', 'impact' => -1.5, 'duration' => 15, 'weight' => 5,
                'head' => '🔥 Kryzys w sektorze [T]! Afera i odpływ kapitału',
                'body' => 'Branża w ogniu krytyki — inwestorzy wycofują się z całego sektora.',
                'effects' => [['sector', 'profit_climate', -2.0, 80], ['sector', 'volatility', 0.3, 60]],
            ],
            'sector_boom' => [
                'scope' => 'SECTOR', 'type' => 'POS', 'impact' => 1.3, 'duration' => 15, 'weight' => 5,
                'head' => '⭐ Boom w sektorze [T]! Rynek wierzy w branżę',
                'body' => 'Przełomowe perspektywy dla całej branży przyciągają kapitał.',
                'effects' => [['sector', 'profit_climate', 2.0, 80]],
            ],
            'regulacje' => [
                'scope' => 'SECTOR', 'type' => 'NEG', 'impact' => -0.9, 'duration' => 15, 'weight' => 6,
                'head' => '⚖️ Nowe regulacje uderzają w sektor [T]',
                'body' => 'Dodatkowe wymogi i opłaty obniżą zyski całej branży na miesiące.',
                'effects' => [['sector', 'profit_climate', -2.5, 120], ['sector', 'volatility', 0.2, 40]],
            ],
            'przelom_w_branzy' => [
                'scope' => 'SECTOR', 'type' => 'POS', 'impact' => 0.9, 'duration' => 15, 'weight' => 6,
                'head' => '🧪 Przełom technologiczny w branży [T]',
                'body' => 'Nowa technologia obniża koszty w całym sektorze — zyski wzrosną.',
                'effects' => [['sector', 'profit_climate', 2.5, 120]],
            ],
            'moda_inwestorow' => [
                'scope' => 'SECTOR', 'type' => 'POS', 'impact' => 1.4, 'duration' => 12, 'weight' => 4,
                'head' => '🔥 Inwestorzy pokochali sektor [T] — kursy szybują',
                'body' => 'Moda na branżę winduje wyceny szybciej, niż rosną zyski. To pachnie bańką…',
                'effects' => [['sector', 'trend', 0.1, 25], ['sector', 'volatility', 0.35, 50]],
                'follow_ups' => [['peka_banka', 45, 30, 70]],
            ],
            'peka_banka' => [
                'scope' => 'SECTOR', 'type' => 'NEG', 'impact' => -1.8, 'duration' => 12, 'weight' => 0,
                'head' => '💥 Pęka bańka w sektorze [T]! Gwałtowny odwrót',
                'body' => 'Wyceny oderwały się od zysków — teraz spadają szybciej, niż rosły.',
                'effects' => [['sector', 'volatility', 0.4, 40]],
            ],
            'kryzys_zaufania_fin' => [
                'scope' => 'SECTOR', 'type' => 'NEG', 'impact' => -1.2, 'duration' => 15, 'weight' => 0,
                'head' => '🏦 Kryzys zaufania do sektora [T] po krachu',
                'body' => 'Po rynkowej panice inwestorzy boją się o kondycję instytucji finansowych.',
                'effects' => [['sector', 'profit_climate', -2.0, 80], ['sector', 'volatility', 0.3, 50]],
                'fixed_sector' => 'FIN',
            ],

            // ================= SPÓŁKA (cel: konkretna firma) =================
            'kontrakt' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 1.2, 'duration' => 10, 'weight' => 8,
                'head' => '📜 [T] zdobywa ogromny kontrakt',
                'body' => 'Umowa zapewni spółce wyraźnie wyższe zyski przez najbliższe miesiące.',
                'effects' => [['stock', 'profit_trend', 3.0, 200]],
            ],
            'nowy_produkt' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 1.0, 'duration' => 10, 'weight' => 7,
                'head' => '🚀 [T] pokazuje przełomowy produkt',
                'body' => 'Rynek wróży sukces sprzedażowy — prognozy zysków w górę.',
                'effects' => [['stock', 'profit_trend', 2.0, 150]],
            ],
            'afera_ksiegowa' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -2.0, 'duration' => 12, 'weight' => 4,
                'head' => '🕳️ Afera księgowa w [T]! Audyt wykrył nieprawidłowości',
                'body' => 'Zyski były zawyżane. Spółka zawiesza dywidendę do wyjaśnienia sprawy.',
                'effects' => [['stock', 'profit_trend', -4.0, 200], ['stock', 'volatility', 0.5, 100],
                              ['stock', 'dividend_pause', 1, 200]],
                'follow_ups' => [['sector_panic', 25, 5, 15]],
            ],
            'cyberatak' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -1.0, 'duration' => 8, 'weight' => 5,
                'head' => '🖥️ Cyberatak sparaliżował systemy [T]',
                'body' => 'Przestoje i koszty odbudowy odbiją się na najbliższych wynikach.',
                'effects' => [['stock', 'profit_trend', -2.0, 100], ['stock', 'volatility', 0.3, 50]],
            ],
            'rekomendacja_kupuj' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.8, 'duration' => 8, 'weight' => 8,
                'head' => '📈 Analitycy rekomendują: KUPUJ [T]',
                'body' => 'Znane biuro maklerskie podnosi wycenę spółki.',
                'effects' => [],
            ],
            'rekomendacja_sprzedaj' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.8, 'duration' => 8, 'weight' => 8,
                'head' => '📉 Analitycy tną wycenę [T]: SPRZEDAJ',
                'body' => 'Biuro maklerskie widzi ryzyka, których rynek nie wycenia.',
                'effects' => [],
            ],
            'plotka_przejecie' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 1.0, 'duration' => 10, 'weight' => 5,
                'head' => '🤝 Plotka: [T] celem przejęcia przez giganta',
                'body' => 'Nieoficjalnie: trwają rozmowy o wykupie akcji z premią. Czekamy na potwierdzenie…',
                'effects' => [['stock', 'volatility', 0.4, 40]],
                'resolve' => [[55, 'przejecie_tak'], [45, 'przejecie_nie']],
                'resolve_delay' => [15, 35],
            ],
            'przejecie_tak' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 2.5, 'duration' => 10, 'weight' => 0,
                'head' => '✅ Potwierdzone: inwestor przejmuje [T] z premią!',
                'body' => 'Wezwanie na akcje powyżej kursu — plotka okazała się prawdą.',
                'effects' => [],
            ],
            'przejecie_nie' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -1.8, 'duration' => 8, 'weight' => 0,
                'head' => '❌ [T]: spółka dementuje plotki o przejęciu',
                'body' => 'Rozmów nie było. Spekulacyjny kapitał ucieka, kurs wraca na ziemię.',
                'effects' => [],
            ],
            'prezes_odchodzi' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.7, 'duration' => 8, 'weight' => 5,
                'head' => '🚪 Prezes [T] niespodziewanie rezygnuje',
                'body' => 'Rynek nie lubi niepewności u sterów.',
                'effects' => [['stock', 'volatility', 0.3, 60]],
            ],
            'ekspansja_zagranica' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.9, 'duration' => 10, 'weight' => 6,
                'head' => '🌍 [T] wchodzi na rynki zagraniczne',
                'body' => 'Ekspansja zwiększy skalę biznesu, choć początkowo podniesie koszty.',
                'effects' => [['stock', 'profit_trend', 1.5, 150], ['stock', 'volatility', 0.15, 60]],
            ],

            // ===== SPÓŁKA — drobiazg fabularny (mały wpływ, wysoka częstotliwość) =====
            'insider_kupuje' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.5, 'duration' => 6, 'weight' => 10,
                'head' => '💼 Członek zarządu [T] kupuje akcje za własne pieniądze',
                'body' => 'Insiderzy najlepiej znają spółkę — taki zakup rynek odbiera jako sygnał zaufania.',
                'effects' => [],
            ],
            'insider_sprzedaje' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.4, 'duration' => 6, 'weight' => 9,
                'head' => '💼 Insider sprzedaje duży pakiet akcji [T]',
                'body' => 'Sprzedaż przez osobę z wewnątrz zawsze rodzi pytania.',
                'effects' => [],
            ],
            'wygrany_przetarg' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.5, 'duration' => 8, 'weight' => 8,
                'head' => '🏆 [T] wygrywa ważny przetarg',
                'body' => 'Zamówienie poprawi wyniki w kolejnych miesiącach.',
                'effects' => [['stock', 'profit_trend', 1.0, 100]],
            ],
            'pozew' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.5, 'duration' => 10, 'weight' => 6,
                'head' => '⚖️ Pozew zbiorowy przeciwko [T]',
                'body' => 'Koszty prawne i ryzyko odszkodowań zaciążą na wynikach.',
                'effects' => [['stock', 'profit_trend', -0.8, 80]],
            ],
            'opoznienie_projektu' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.6, 'duration' => 8, 'weight' => 6,
                'head' => '⏱️ [T] opóźnia premierę flagowego projektu',
                'body' => 'Przychody przesuwają się w czasie, koszty zostają.',
                'effects' => [['stock', 'profit_trend', -1.0, 80]],
            ],
            'awaria' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.4, 'duration' => 6, 'weight' => 7,
                'head' => '🔧 Awaria w zakładzie [T] wstrzymuje pracę',
                'body' => 'Przestój potrwa kilka dni — jednorazowy koszt.',
                'effects' => [],
            ],
            'partnerstwo' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.5, 'duration' => 8, 'weight' => 7,
                'head' => '🤝 [T] ogłasza strategiczne partnerstwo',
                'body' => 'Współpraca otwiera nowe kanały sprzedaży.',
                'effects' => [],
            ],
            'patent' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.4, 'duration' => 8, 'weight' => 6,
                'head' => '📄 [T] uzyskuje kluczowy patent',
                'body' => 'Ochrona technologii wzmacnia pozycję konkurencyjną.',
                'effects' => [['stock', 'profit_trend', 0.8, 100]],
            ],
            'restrukturyzacja' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.4, 'duration' => 8, 'weight' => 5,
                'head' => '✂️ [T] tnie koszty — plan restrukturyzacji',
                'body' => 'Bolesne, ale marże powinny się poprawić.',
                'effects' => [['stock', 'profit_trend', 1.2, 100], ['stock', 'volatility', 0.1, 40]],
            ],
            'strajk' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.5, 'duration' => 8, 'weight' => 4,
                'head' => '✊ Strajk załogi w [T]',
                'body' => 'Negocjacje płacowe trwają, produkcja stoi.',
                'effects' => [['stock', 'profit_trend', -1.0, 60]],
            ],
            'viral_kampania' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.35, 'duration' => 6, 'weight' => 6,
                'head' => '📣 Kampania [T] podbija internet',
                'body' => 'Marka zyskuje rozgłos — sprzedaż powinna drgnąć.',
                'effects' => [],
            ],
            'wyciek_danych' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.5, 'duration' => 8, 'weight' => 5,
                'head' => '🔓 Wyciek danych klientów [T]',
                'body' => 'Kary i utrata zaufania mogą kosztować.',
                'effects' => [],
            ],
            'dotacja' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.4, 'duration' => 8, 'weight' => 6,
                'head' => '💶 [T] otrzymuje dotację na rozwój',
                'body' => 'Zewnętrzne finansowanie przyspieszy inwestycje.',
                'effects' => [],
            ],
            'gra_na_spadki' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.5, 'duration' => 8, 'weight' => 4,
                'head' => '🐻 Fundusz gra na spadki [T]',
                'body' => 'Znany fundusz ujawnił krótką pozycję — rynek nerwowo reaguje.',
                'effects' => [['stock', 'volatility', 0.2, 40]],
            ],
            'podwyzka_prognoz' => [
                'scope' => 'COMPANY', 'type' => 'POS', 'impact' => 0.6, 'duration' => 8, 'weight' => 5,
                'head' => '📊 [T] podnosi prognozy roczne',
                'body' => 'Zarząd widzi lepszą koniunkturę, niż zakładał.',
                'effects' => [['stock', 'profit_trend', 1.5, 100]],
            ],
            'obnizka_prognoz' => [
                'scope' => 'COMPANY', 'type' => 'NEG', 'impact' => -0.6, 'duration' => 8, 'weight' => 5,
                'head' => '📊 [T] obniża prognozy roczne',
                'body' => 'Zarząd studzi oczekiwania co do wyników.',
                'effects' => [['stock', 'profit_trend', -1.5, 100]],
            ],

            // ===== SEKTOR — drobiazg branżowy (mały wpływ) =====
            'targi_branzowe' => [
                'scope' => 'SECTOR', 'type' => 'POS', 'impact' => 0.3, 'duration' => 8, 'weight' => 7,
                'head' => '🎪 Udane targi branży [T] — pełne portfele zamówień',
                'body' => 'Firmy z sektora wracają z targów z kontraktami.',
                'effects' => [],
            ],
            'raport_branzowy_pos' => [
                'scope' => 'SECTOR', 'type' => 'POS', 'impact' => 0.4, 'duration' => 10, 'weight' => 6,
                'head' => '📈 Raport analityków: przed branżą [T] dobre lata',
                'body' => 'Prognozy długoterminowe dla sektora w górę.',
                'effects' => [['sector', 'profit_climate', 0.8, 80]],
            ],
            'raport_branzowy_neg' => [
                'scope' => 'SECTOR', 'type' => 'NEG', 'impact' => -0.4, 'duration' => 10, 'weight' => 6,
                'head' => '📉 Raport analityków: branża [T] wytraca tempo',
                'body' => 'Prognozy długoterminowe dla sektora w dół.',
                'effects' => [['sector', 'profit_climate', -0.8, 80]],
            ],
            'niedobor_kadr' => [
                'scope' => 'SECTOR', 'type' => 'NEG', 'impact' => -0.3, 'duration' => 10, 'weight' => 5,
                'head' => '👷 W branży [T] brakuje rąk do pracy',
                'body' => 'Rosnące płace podniosą koszty całego sektora.',
                'effects' => [['sector', 'profit_climate', -0.6, 80]],
            ],
            'tansze_surowce' => [
                'scope' => 'SECTOR', 'type' => 'POS', 'impact' => 0.35, 'duration' => 10, 'weight' => 5,
                'head' => '📦 Tanieją surowce kluczowe dla branży [T]',
                'body' => 'Niższe koszty produkcji poprawią marże sektora.',
                'effects' => [['sector', 'profit_climate', 0.8, 80]],
            ],
            'konsolidacja_branzy' => [
                'scope' => 'SECTOR', 'type' => 'POS', 'impact' => 0.4, 'duration' => 10, 'weight' => 4,
                'head' => '🔗 Fala fuzji w branży [T] — rynek wyczekuje przejęć',
                'body' => 'Konsolidacja zwykle oznacza premie za przejmowane spółki.',
                'effects' => [['sector', 'volatility', 0.15, 50]],
            ],
        ];
    }

    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }

    /** Losowy szablon z puli (ważony), z filtrem zakresu. */
    public static function pickRandom(?string $scope = null): ?string
    {
        $pool = [];
        foreach (self::all() as $code => $t) {
            if ((int) $t['weight'] <= 0) continue;
            if ($scope !== null && $t['scope'] !== $scope) continue;
            $pool[$code] = (int) $t['weight'];
        }
        if (!$pool) return null;
        $r = mt_rand(1, array_sum($pool)); $acc = 0;
        foreach ($pool as $code => $w) { $acc += $w; if ($r <= $acc) return $code; }
        return array_key_first($pool);
    }
}
