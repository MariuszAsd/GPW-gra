<?php
/**
 * NEWSROOM — redakcja finansowa świata gry. Generuje strumień informacji,
 * którym żyje rynek: komunikaty ESPI, doniesienia medialne, komentarze
 * techniczne i lekkie dane makro. Zastępuje stare szablony z bazy
 * (news_templates) — treść mieszka w kodzie i jest wypełniana PRAWDZIWYMI
 * danymi spółki (kwoty liczone z jej zysków, procenty z kontekstu).
 *
 * KLASY INFORMACJI (kolumna news.kind) — fundament mechaniki "różne boty
 * słuchają różnych wiadomości":
 *   fundamental  twarde fakty o pieniądzach (kontrakty, prognozy, kary, emisje).
 *                Pełny wpływ na wartość fundamentalną; boty FUNDAMENTALNE
 *                przeliczają na nich swoją wycenę — tym mocniej, im bardziej
 *                "fundamentalny" charakter ma spółka (niska tech_affinity).
 *   sentiment    miękkie: plotki, wywiady, fundusze, nastroje forów. Wpływ na
 *                fundament OSŁABIONY (~połowa) — resztę ruchu robią boty
 *                NEWSOWE, które żywią się narracją (najmocniej na spółkach
 *                "technicznych"/spekulacyjnych). Po wygaśnięciu kurs ciąży
 *                z powrotem do wyceny — jak w realu po modzie.
 *   technical    komentarz rynkowy pisany Z DANYCH wykresu (wybicia, wolumen,
 *                serie, szczyty). ZERO wpływu na fundament — ale boty AT
 *                dostają na tej spółce chwilowy dopalacz czułości, więc
 *                komentarz bywa samospełniającą się przepowiednią.
 *
 * Duże wydarzenia STRUKTURALNE (krachy, regulacje, kaskady, plotki
 * z rozstrzygnięciem) pozostają w EventCatalog — Newsroom to codzienny
 * szum informacyjny, na którym uczą się czytać gracze.
 */
final class Newsroom
{
    /** Pule fabularne do wypełniania szablonów. */
    private const KLIENCI = [
        'koncern motoryzacyjny z Niemiec', 'skandynawska sieć handlowa', 'operator logistyczny z Beneluksu',
        'resort infrastruktury', 'globalny integrator IT', 'francuska grupa energetyczna',
        'amerykański fundusz nieruchomości', 'czeska grupa przemysłowa', 'sieć klinik prywatnych',
        'lider e-commerce z regionu CEE', 'konsorcjum samorządowe', 'azjatycki producent elektroniki',
    ];
    private const KRAJE = ['Niemcy', 'Czechy', 'Rumunia', 'kraje nordyckie', 'Hiszpania', 'kraje bałtyckie', 'Włochy', 'Beneluks'];
    private const OSOBY = [
        'Adam Wierzbicki', 'Ewa Malinowska', 'Tomasz Grabski', 'Joanna Cieślak', 'Marek Zawadzki',
        'Anna Sokołowska', 'Paweł Krajewski', 'Magdalena Urbaniak', 'Krzysztof Lis', 'Beata Czerwińska',
    ];
    private const DOMY = ['DM Meridian', 'Atlas Securities', 'BM Fortis', 'DM Kwarc', 'Helvet Capital'];
    private const FUNDUSZE = ['OFE Piast', 'TFI Bursztyn', 'fundusz Northgate', 'TFI Skala', 'fundusz Vistula Capital'];

    /**
     * Szablony spółkowe: kod => [kind, type, espi, impact, dur, weight, head, body, topic].
     * topic grupuje sprzeczne narracje (prognozy/finanse/kontrakty...) — ten sam temat
     * nie wraca na spółce przez TOPIC_COOLDOWN ticków, więc nie ma absurdów typu
     * "podnosi prognozę" i chwilę później "obniża prognozę".
     * impact = %/tick w szczycie (zanika przez dur ticków), skalowany wrażliwością sektora.
     * Placeholdery: {ticker} {name} {sector} {mln} {mln2} {pct} {pct2} {pctrev} {klient} {kraj} {osoba} {dm} {fundusz}
     */
    public const COMPANY = [
        // ===== FUNDAMENTY — pozytywne (twarde pieniądze) =====
        'kontrakt_eksport' => ['fundamental', 'POS', 1, 0.55, 12, 9,
            'ESPI: {ticker} podpisuje kontrakt o wartości {mln} mln PLN z {klient}',
            'Umowa odpowiada ok. {pctrev}% rocznych przychodów {name} i będzie realizowana przez najbliższe miesiące. Zarząd deklaruje, że rentowność kontraktu jest zbliżona do średniej marży grupy, więc podpis powinien niemal wprost przełożyć się na wynik netto.', 'kontrakty'],
        'umowa_ramowa' => ['fundamental', 'POS', 1, 0.35, 12, 7,
            'ESPI: {ticker} zawiera umowę ramową z {klient} — do {mln} mln PLN w 3 lata',
            'Umowa ramowa nie gwarantuje całej kwoty, ale otwiera drzwi do regularnych zamówień. Pierwsze zlecenie za {mln2} mln PLN trafi do realizacji jeszcze w tym kwartale — analitycy lubią takie kontrakty za przewidywalność przychodów.', 'kontrakty'],
        'buyback' => ['fundamental', 'POS', 1, 0.4, 14, 6,
            'ESPI: {ticker} ogłasza skup akcji własnych do {pct2}% kapitału',
            'Skup finansowany z nadwyżek gotówki zmniejszy liczbę akcji w obrocie — ten sam zysk rozłoży się na mniej walorów, więc zysk na akcję (EPS) mechanicznie wzrośnie. Rynek odbiera buyback także jako sygnał, że zarząd uważa kurs za zbyt niski.', 'finanse'],
        'prognoza_gora' => ['fundamental', 'POS', 1, 0.5, 12, 6,
            'ESPI: zarząd {ticker} podnosi prognozę rocznego zysku o {pct}%',
            'Powodem jest lepszy od planu portfel zamówień i niższe koszty finansowania. Podniesiona prognoza to jedna z najsilniejszych informacji fundamentalnych — zarząd rzadko ryzykuje własną wiarygodność bez pokrycia w liczbach.', 'prognozy'],
        'duzy_klient' => ['fundamental', 'POS', 1, 0.4, 10, 7,
            '{ticker} pozyskuje strategicznego klienta — {klient}',
            'Nowy klient ma docelowo odpowiadać za kilka procent przychodów {name}. Dywersyfikacja bazy klientów obniża ryzyko biznesu: spółka będzie mniej zależna od pojedynczych kontraktów.', 'kontrakty'],
        'patent_wdrozenie' => ['fundamental', 'POS', 1, 0.35, 12, 5,
            '{ticker} wdraża własną technologię — koszty jednostkowe spadną o ok. {pct2}%',
            'Po dwóch latach testów spółka uruchamia rozwiązanie chronione patentem. Niższy koszt wytworzenia przy tych samych cenach sprzedaży to wprost wyższa marża — efekty mają być widoczne w wynikach już od przyszłego kwartału.', 'produkt'],
        'dotacja_ue' => ['fundamental', 'POS', 1, 0.25, 10, 5,
            '{ticker} z grantem {mln2} mln PLN na prace badawczo-rozwojowe',
            'Zewnętrzne finansowanie pokryje większość budżetu R&D na najbliższy rok, odciążając własną gotówkę spółki. Projekt dotyczy rozwoju nowej linii produktowej dla sektora {sector}.', 'finanse'],
        'sprzedaz_aktywow' => ['fundamental', 'POS', 1, 0.3, 10, 4,
            'ESPI: {ticker} sprzedaje niestrategiczne aktywa za {mln2} mln PLN',
            'Transakcja z zyskiem księgowym jednorazowo podbije najbliższy wynik, a uwolniona gotówka ma sfinansować rozwój podstawowego biznesu. Uwaga inwestorów: to zysk JEDNORAZOWY — nie poprawia trwałej rentowności.', 'finanse'],
        'rating_gora' => ['fundamental', 'POS', 0, 0.3, 10, 4,
            'Agencja podnosi ocenę wiarygodności kredytowej {ticker}',
            'Lepszy rating to tańszy dług: przy refinansowaniu obligacji spółka zaoszczędzi na odsetkach. Agencja doceniła spadek zadłużenia i stabilność przepływów operacyjnych {name}.', 'finanse'],
        'moce_produkcyjne' => ['fundamental', 'POS', 1, 0.3, 12, 5,
            '{ticker} uruchamia nową linię — moce produkcyjne w górę o {pct}%',
            'Inwestycja pozwoli obsłużyć rosnący portfel zamówień bez kosztownych nadgodzin i podwykonawców. Pełne wykorzystanie nowych mocy planowane jest w ciągu dwóch–trzech kwartałów.', 'produkt'],
        'wejscie_kraj' => ['fundamental', 'POS', 1, 0.3, 12, 6,
            '{ticker} otwiera sprzedaż na rynku: {kraj}',
            'Ekspansja zagraniczna zwiększa adresowalny rynek {name}, choć na starcie dołoży kosztów marketingu i logistyki. Zarząd celuje w rentowność nowego rynku po czterech kwartałach.', 'ekspansja'],
        'rekord_operacyjny' => ['fundamental', 'POS', 0, 0.3, 8, 6,
            'Dane operacyjne {ticker}: rekordowa miesięczna sprzedaż',
            'Opublikowane dane sprzedażowe wyprzedzają raport finansowy i sugerują, że nadchodzące wyniki mogą pozytywnie zaskoczyć. To tzw. twardy sygnał wyprzedzający — rynek zwykle nie czeka z reakcją do dnia raportu.', 'prognozy'],
        'redukcja_dlugu' => ['fundamental', 'POS', 1, 0.25, 10, 4,
            'ESPI: {ticker} spłaca obligacje przed terminem',
            'Przedterminowy wykup obniży koszty odsetkowe w kolejnych kwartałach i poprawi strukturę bilansu. Spółka finansuje operację z bieżących przepływów — bez nowej emisji.', 'finanse'],
        'przejecie_male' => ['fundamental', 'POS', 1, 0.4, 12, 4,
            'ESPI: {ticker} przejmuje mniejszego konkurenta za {mln2} mln PLN',
            'Przejęcie powiększy udziały rynkowe i portfel klientów {name}; synergie kosztowe mają pokryć cenę zakupu w ok. trzy lata. Ryzykiem pozostaje integracja zespołów — historia zna fuzje, które zjadły więcej, niż obiecywały.', 'ekspansja'],

        // ===== FUNDAMENTY — negatywne =====
        'utrata_klienta' => ['fundamental', 'NEG', 1, -0.5, 12, 6,
            'ESPI: kluczowy klient {ticker} nie przedłuża umowy',
            'Wygasający kontrakt odpowiadał za ok. {pctrev}% przychodów {name}. Zarząd zapewnia, że trwają rozmowy z nowymi odbiorcami, ale luki w przychodach nie da się wypełnić z miesiąca na miesiąc.', 'kontrakty'],
        'kara_regulatora' => ['fundamental', 'NEG', 1, -0.45, 12, 5,
            'Regulator nakłada na {ticker} karę {mln2} mln PLN',
            'Kara obciąży wynik najbliższego kwartału. Spółka zapowiada odwołanie, ale ostrożnościowo zawiąże rezerwę na całą kwotę — tak każe sztuka księgowa. Gorsze od samej kary bywa ryzyko zaostrzenia nadzoru.', 'prawo'],
        'odpis_aktywow' => ['fundamental', 'NEG', 1, -0.5, 12, 4,
            'ESPI: {ticker} dokonuje odpisu wartości aktywów na {mln} mln PLN',
            'Odpis jest bezgotówkowy — pieniądze nie wypływają ze spółki — ale pomniejsza wynik netto i kapitały własne. To księgowe przyznanie, że część wcześniejszych inwestycji nie zarobi tyle, ile zakładano.', 'finanse'],
        'prognoza_dol' => ['fundamental', 'NEG', 1, -0.55, 12, 5,
            'ESPI: zarząd {ticker} obniża prognozę wyników o {pct}%',
            'Powody: słabszy popyt i rosnące koszty. Obniżka prognozy to poważny sygnał — zarządy robią to niechętnie i zwykle dopiero wtedy, gdy problemy są już nie do zamaskowania.', 'prognozy'],
        'presja_kosztow' => ['fundamental', 'NEG', 0, -0.3, 10, 6,
            '{ticker} pod presją kosztów — marże się kurczą',
            'Drożejąca energia i wynagrodzenia zjadają rentowność {name}, a konkurencja nie pozwala w pełni przerzucić kosztów na ceny. Kluczowe pytanie przed najbliższym raportem: ile z tej presji spółka zdołała zamortyzować.', 'prognozy'],
        'audyt_zastrzezenia' => ['fundamental', 'NEG', 1, -0.6, 14, 3,
            'Biegły rewident zgłasza zastrzeżenia do sprawozdania {ticker}',
            'Audytor kwestionuje sposób ujęcia części przychodów. To nie wyrok — ale rynek traktuje zastrzeżenia audytu jak żółtą kartkę dla wiarygodności liczb, na których opiera się cała wycena.', 'prawo'],
        'rezerwa_naleznosci' => ['fundamental', 'NEG', 1, -0.35, 10, 4,
            'ESPI: kontrahent {ticker} przestał płacić — rezerwa {mln2} mln PLN',
            'Spółka zawiąże rezerwę na nieściągalne należności, co obciąży najbliższy wynik. Zarząd kieruje sprawę do sądu, ale odzyskiwanie pieniędzy z upadłego kontrahenta potrafi trwać latami.', 'finanse'],
        'rating_dol' => ['fundamental', 'NEG', 0, -0.35, 10, 4,
            'Agencja obniża ocenę wiarygodności kredytowej {ticker}',
            'Niższy rating oznacza droższe finansowanie przy najbliższym rolowaniu długu. Agencja wskazuje na rosnące zadłużenie i pogarszającą się płynność sektora {sector}.', 'finanse'],
        'awaria_powazna' => ['fundamental', 'NEG', 1, -0.35, 10, 4,
            'ESPI: poważna awaria w zakładzie {ticker} — przestój do dwóch tygodni',
            'Utracona produkcja i koszty napraw obciążą bieżący kwartał; część zamówień przejmie konkurencja. Ubezpieczenie pokryje szkody majątkowe, ale nie utracone przychody.', 'produkt'],
        'recall_produktu' => ['fundamental', 'NEG', 1, -0.4, 12, 3,
            '{ticker} wycofuje partię produktu z rynku',
            'Wykryta wada oznacza koszty serwisu, logistyki zwrotów i — trudniejszą do wyceny — rysę na zaufaniu klientów. Skala finansowa będzie znana za kilka tygodni; niepewność sama w sobie ciąży kursowi.', 'produkt'],
        'przegrany_proces' => ['fundamental', 'NEG', 1, -0.4, 10, 3,
            'Sąd: {ticker} zapłaci {mln2} mln PLN odszkodowania',
            'Wyrok pierwszej instancji w wieloletnim sporze. Spółka zapowiada apelację, lecz zgodnie z zasadą ostrożności rezerwa obciąży wynik już teraz.', 'prawo'],
        'emisja_akcji' => ['fundamental', 'NEG', 1, -0.45, 12, 3,
            'ESPI: {ticker} planuje emisję akcji — rozwodnienie ok. {pct2}%',
            'Nowe akcje sfinansują inwestycje, ale dotychczasowi akcjonariusze będą mieli mniejszy udział w tym samym zysku — EPS spadnie, zanim inwestycje zaczną zarabiać. Rynek zwykle wycenia rozwodnienie od razu.', 'finanse'],

        // ===== NASTROJE — pozytywne (miękkie; grają na nich boty newsowe) =====
        'wywiad_prezes' => ['sentiment', 'POS', 0, 0.3, 8, 7,
            'Prezes {ticker} w wywiadzie: „To będzie przełomowy rok"',
            '{osoba}, prezes {name}, zapowiada w mediach dwucyfrowy wzrost i „projekty, o których rynek jeszcze nie wie". Deklaracje bez liczb to paliwo dla nastrojów — fundamenty poznamy dopiero w raporcie.', 'sentyment'],
        'fundusz_wchodzi' => ['sentiment', 'POS', 1, 0.35, 10, 5,
            'ESPI: {fundusz} ujawnia pakiet powyżej 5% akcji {ticker}',
            'Wejście dużego instytucjonalnego gracza rynek czyta jako wotum zaufania — fundusze rzadko kupują bez własnej analizy. Sceptycy przypomną: fundusze też się mylą, a duży pakiet kiedyś trzeba będzie sprzedać.', 'akcjonariat'],
        'blog_hype' => ['sentiment', 'POS', 0, 0.25, 6, 6,
            'Popularny analityk chwali model biznesowy {ticker}',
            'Wpis z szeroko udostępnianą analizą przyciąga uwagę inwestorów indywidualnych. Uwaga: to opinia, nie rekomendacja domu maklerskiego — bez nowych liczb ze spółki entuzjazm bywa krótkotrwały.', 'sentyment'],
        'ranking_pracodawcow' => ['sentiment', 'POS', 0, 0.15, 6, 4,
            '{ticker} wysoko w rankingu najlepszych pracodawców',
            'Łatwiejsza rekrutacja i mniejsza rotacja to realna, choć trudno mierzalna przewaga. Bezpośredniego wpływu na najbliższe wyniki brak — ale wizerunek pracuje długo.', 'ludzie'],
        'spekulacja_dywidenda' => ['sentiment', 'POS', 0, 0.3, 8, 4,
            'Rynek spekuluje: {ticker} może podnieść dywidendę',
            'Po serii dobrych przepływów gotówkowych część inwestorów obstawia hojniejszą wypłatę. Zarząd milczy — a spekulacja pozostaje spekulacją, dopóki nie potwierdzi jej uchwała.', 'finanse'],
        'forum_hype' => ['sentiment', 'POS', 0, 0.3, 6, 5,
            'Inwestorzy indywidualni rozgrzali forum na temat {ticker}',
            'Liczba wzmianek o spółce rośnie lawinowo, a wraz z nią drobne zlecenia kupna. Tłum potrafi podbić kurs szybciej niż fundamenty — i równie szybko się znudzić.', 'sentyment'],
        'nagroda_branzowa' => ['sentiment', 'POS', 0, 0.15, 6, 4,
            '{name} z nagrodą branżową za innowację roku',
            'Prestiż i darmowy marketing w mediach {sector}. Na wyniki finansowe nagroda nie działa wprost, ale wzmacnia pozycję w rozmowach z klientami.', 'sentyment'],
        'sponsoring_glosny' => ['sentiment', 'POS', 0, 0.2, 6, 3,
            '{ticker} zostaje sponsorem głośnego wydarzenia sportowego',
            'Rozpoznawalność marki w górę, budżet marketingowy w dół. Czy przełoży się to na sprzedaż — zobaczymy w danych za kilka miesięcy; na razie kupują głównie nagłówki.', 'sentyment'],

        // ===== NASTROJE — negatywne =====
        'krytyka_mediow' => ['sentiment', 'NEG', 0, -0.3, 8, 5,
            'Reportaż o warunkach pracy w {ticker} wywołuje burzę',
            'Materiał zdobywa zasięgi, a spółka publikuje oświadczenie. Bezpośrednich skutków finansowych na razie brak — ale presja mediów bywa przedsionkiem kontroli i pozwów.', 'sentyment'],
        'fundusz_wychodzi' => ['sentiment', 'NEG', 1, -0.35, 10, 4,
            'ESPI: {fundusz} redukuje zaangażowanie w {ticker} poniżej progu 5%',
            'Wyjście instytucji zawsze rodzi pytanie: co takiego zobaczyli w spółce? Czasem odpowiedź jest prozaiczna — przebudowa portfela — ale podaż dużego pakietu i tak ciąży notowaniom.', 'akcjonariat'],
        'plotka_kadrowa' => ['sentiment', 'NEG', 0, -0.25, 8, 4,
            'Nieoficjalnie: dyrektor finansowy {ticker} rozważa odejście',
            'Plotka kadrowa obniża komfort inwestorów — CFO zna liczby spółki najlepiej. Bez oficjalnego komunikatu to tylko szum, lecz rynek woli dmuchać na zimne.', 'ludzie'],
        'forum_panika' => ['sentiment', 'NEG', 0, -0.35, 6, 4,
            'Wpis „sygnalisty" o {ticker} podgrzewa panikę wśród drobnych inwestorów',
            'Anonimowe oskarżenia rozchodzą się szybciej niż sprostowania. Spółka zaprzecza, dowodów brak — ale część detalicznych graczy woli najpierw sprzedać, potem pytać.', 'sentyment'],
        'butik_przewartosciowanie' => ['sentiment', 'NEG', 0, -0.3, 8, 4,
            'Butik analityczny: wycena {ticker} „oderwana od fundamentów"',
            'Raport niezależnych analityków wskazuje na zbyt wysoki wskaźnik C/Z na tle branży. To opinia, nie fakt — ale daje amunicję tym, którzy czekali na pretekst do realizacji zysków.', 'sentyment'],
        'bojkot_social' => ['sentiment', 'NEG', 0, -0.25, 6, 3,
            'W sieci narasta akcja bojkotu produktów {ticker}',
            'Hasztagi rosną, choć historia uczy, że internetowe bojkoty rzadko widać w twardych danych sprzedaży. Ryzyko rośnie, jeśli podchwycą je media głównego nurtu.', 'sentyment'],
        'spor_akcjonariuszy' => ['sentiment', 'NEG', 0, -0.3, 10, 3,
            'Konflikt w akcjonariacie {ticker} o strategię spółki',
            'Dwie grupy akcjonariuszy forsują sprzeczne wizje rozwoju. Spory właścicielskie paraliżują decyzje zarządu i potrafią wisieć nad kursem miesiącami.', 'akcjonariat'],
        'zwiazki_spor' => ['sentiment', 'NEG', 0, -0.25, 8, 3,
            'Związki zawodowe w {ticker} zapowiadają spór zbiorowy',
            'Żądania płacowe na razie bez strajku, ale napięcie rośnie. Jeśli dojdzie do przestojów, koszty z miękkich zrobią się twarde.', 'ludzie'],
    ];

    /** Szablony sektorowe. Placeholder {sector}. */
    public const SECTOR = [
        'eksport_branzy' => ['fundamental', 'POS', 0, 0.3, 12, 6,
            'Eksport branży {sector} rośnie drugi kwartał z rzędu',
            'Dane celne pokazują rosnący popyt zagraniczny na produkty sektora. Więcej zamówień u wszystkich graczy — od liderów po maruderów.', 'popyt'],
        'popyt_krajowy_neg' => ['fundamental', 'NEG', 0, -0.3, 12, 6,
            'Popyt krajowy w branży {sector} hamuje',
            'Konsumenci i firmy odkładają zakupy; sektor wchodzi w okres walki o klienta cenami, co zwykle kończy się erozją marż w całej branży.', 'popyt'],
        'surowce_tanieja' => ['fundamental', 'POS', 0, 0.35, 12, 5,
            'Kluczowe surowce dla sektora {sector} tanieją na rynkach światowych',
            'Niższe ceny wsadu to wyższe marże wytwórców — efekt widać w wynikach zwykle po jednym, dwóch kwartałach, gdy skończą się stare, droższe zapasy.', 'surowce'],
        'surowce_drozeja' => ['fundamental', 'NEG', 0, -0.35, 12, 5,
            'Skokowy wzrost cen surowców uderza w sektor {sector}',
            'Koszty produkcji rosną szybciej, niż spółki są w stanie podnosić ceny. Przewagę zyskują firmy z długimi kontraktami na dostawy — reszta zapłaci rynkową stawkę.', 'surowce'],
        'zmiana_podatkowa' => ['fundamental', 'NEG', 0, -0.3, 14, 4,
            'Projekt zmian podatkowych obciąży branżę {sector}',
            'Nowa danina sektorowa w konsultacjach. Do uchwalenia daleko, ale rynek dyskontuje ryzyko od razu — wyceny spółek z branży pod presją.', 'regulacje'],
        'dane_branzy_pos' => ['fundamental', 'POS', 0, 0.25, 10, 6,
            'Raport branżowy: sprzedaż w sektorze {sector} powyżej oczekiwań',
            'Zagregowane dane sprzedażowe branży wyprzedzają raporty pojedynczych spółek — statystycznie dobre otoczenie podnosi prognozy dla wszystkich graczy sektora.', 'dane'],
        'dane_branzy_neg' => ['fundamental', 'NEG', 0, -0.25, 10, 6,
            'Raport branżowy: sektor {sector} wytraca tempo',
            'Wolumeny sprzedaży niższe rok do roku. Analitycy zaczną ścinać prognozy dla spółek z branży — pytanie tylko, kto ucierpi najmniej.', 'dane'],
        'inwestor_zagraniczny' => ['fundamental', 'POS', 0, 0.3, 12, 4,
            'Globalny gracz zapowiada inwestycje w polski sektor {sector}',
            'Kapitał zagraniczny podnosi wyceny w całej branży: rośnie szansa na przejęcia lokalnych firm z premią do kursu.', 'kapital'],
        'konferencja_optymizm' => ['sentiment', 'POS', 0, 0.2, 8, 5,
            'Optymizm na konferencji branżowej sektora {sector}',
            'Prezesi prześcigają się w dobrych zapowiedziach, a kuluary huczą od planów ekspansji. Nastroje to nie zamówienia — ale rynek lubi dobrą atmosferę.', 'moda'],
        'medialna_moda' => ['sentiment', 'POS', 0, 0.3, 8, 4,
            'Media okrzyknęły {sector} „branżą przyszłości"',
            'Seria entuzjastycznych publikacji ściąga kapitał detaliczny do całego sektora. Doświadczeni gracze wiedzą: gdy branża trafia na okładki, do rozdania zostaje zwykle mniej, niż się wydaje.', 'moda'],
        'medialna_niechec' => ['sentiment', 'NEG', 0, -0.3, 8, 4,
            'Sektor {sector} traci błysk w oczach inwestorów',
            'Moda się skończyła: media piszą o „przegrzanych wycenach", a kapitał rotuje do innych branż. Fundamentów to nie zmienia — ale przepływy robią kursy.', 'moda'],
        'niedobor_kadr_sektor' => ['fundamental', 'NEG', 0, -0.25, 12, 4,
            'Branża {sector} zmaga się z niedoborem specjalistów',
            'Presja płacowa podnosi koszty w całym sektorze; firmy konkurują o tych samych ludzi. Najbardziej ucierpią spółki z niską marżą, które nie mają z czego dokładać do pensji.', 'kadry'],
    ];

    /** Lekkie dane makro (scope MARKET; duże wydarzenia rynkowe zostają w EventCatalog). */
    public const MARKET = [
        'makro_konsument_pos' => ['fundamental', 'POS', 0, 0.15, 10, 6,
            'Dane makro: sprzedaż detaliczna powyżej prognoz',
            'Konsumenci wydają śmielej, niż zakładali ekonomiści — dobry omen dla spółek handlowych i dóbr konsumenckich, pośrednio dla całego rynku.', 'konsument'],
        'makro_konsument_neg' => ['fundamental', 'NEG', 0, -0.15, 10, 6,
            'Dane makro: sprzedaż detaliczna rozczarowuje',
            'Słabszy popyt konsumencki studzi oczekiwania wobec wyników spółek na najbliższe kwartały.', 'konsument'],
        'makro_produkcja_pos' => ['fundamental', 'POS', 0, 0.15, 10, 5,
            'Produkcja przemysłowa zaskakuje na plus',
            'Fabryki zwiększają wykorzystanie mocy — sygnał ożywienia, który zwykle wyprzedza poprawę zysków w przemyśle i transporcie.', 'produkcja'],
        'makro_produkcja_neg' => ['fundamental', 'NEG', 0, -0.15, 10, 5,
            'Produkcja przemysłowa poniżej oczekiwań',
            'Zamówienia w przemyśle słabną. Jeśli trend się utrzyma, analitycy zaczną obniżać prognozy dla spółek cyklicznych.', 'produkcja'],
        'naplywy_tfi' => ['sentiment', 'POS', 0, 0.15, 8, 4,
            'Fundusze akcji notują najwyższe napływy od miesięcy',
            'Świeży kapitał od oszczędzających musi zostać zainwestowany — a kupowanie „bo są napływy" podnosi kursy niezależnie od fundamentów. Do czasu.', 'tfi'],
        'odplywy_tfi' => ['sentiment', 'NEG', 0, -0.15, 8, 4,
            'Klienci wycofują pieniądze z funduszy akcji',
            'Umorzenia zmuszają fundusze do sprzedawania akcji bez patrzenia na wyceny. Taka podaż potrafi przyginać kursy nawet dobrych spółek.', 'tfi'],
    ];

    /* ================= REALIZM STRUMIENIA ================= */

    /** Minimalny odstęp między HISTORIAMI na jednym celu (ticki) — raporty/dywidendy/konsensusy nie liczą się. */
    public const STORY_COOLDOWN = ['COMPANY' => 30, 'SECTOR' => 40, 'MARKET' => 0];
    /** Ten sam TEMAT nie wraca na celu przez tyle ticków (anty-sprzeczność i anty-monotonia). */
    public const TOPIC_COOLDOWN = ['COMPANY' => 150, 'SECTOR' => 150, 'MARKET' => 120];

    public static function topicHash(string $topic): int { return crc32('temat:' . $topic) & 0x7FFFFFFF; }

    /**
     * Czy wolno opublikować historię o danym temacie na danym celu?
     * Newsy narracyjne znaczymy template_id = hash tematu; strukturalne
     * (raporty, dywidendy, konsensusy, komentarze techniczne) mają NULL
     * i nie blokują dramaturgii.
     */
    public static function storyAllowed(string $scope, ?int $targetId, string $topic, int $tick): bool
    {
        $global = self::STORY_COOLDOWN[$scope] ?? 0;
        $tgt = $scope === 'MARKET' ? "scope='MARKET'" : 'scope=' . Db::pdo()->quote($scope) . ' AND target_id=' . (int) $targetId;
        if ($global > 0) {
            $last = Engine::one("SELECT MAX(publish_tick) FROM news WHERE $tgt AND template_id IS NOT NULL");
            if ($last !== false && $last !== null && $tick - (int) $last < $global) return false;
        }
        $tc = self::TOPIC_COOLDOWN[$scope] ?? 0;
        if ($tc > 0) {
            $lastT = Engine::one("SELECT MAX(publish_tick) FROM news WHERE $tgt AND template_id = " . self::topicHash($topic));
            if ($lastT !== false && $lastT !== null && $tick - (int) $lastT < $tc) return false;
        }
        return true;
    }

    /* ================= GENERATOR ================= */

    /** Główny hak — wołany co tick z Engine::runTick (zastępuje stare generateNews). */
    public static function onTick(int $tick): void
    {
        // spółki: częstotliwość wg DNA news_frequency (jak dotąd ~0,7% × nf na tick)
        foreach (Engine::all("SELECT s.*, sec.name AS sector_name, sec.news_sensitivity
                              FROM stocks s JOIN sectors sec ON sec.id = s.sector_id") as $s) {
            if (mt_rand(1, 1000) <= (int) round(7 * (float) $s['news_frequency'])) {
                self::emitCompany($tick, $s);
            }
        }
        // sektory
        foreach (Engine::all("SELECT * FROM sectors") as $sec) {
            if (mt_rand(1, 1000) <= 4) self::emitSector($tick, $sec);
        }
        // lekkie makro (rzadkie, z własnym cooldownem)
        $lastMkt = (int) (Engine::one("SELECT v FROM game_state WHERE k='newsroom_last_macro'") ?: -1000);
        if ($tick - $lastMkt >= 30 && mt_rand(1, 1000) <= 12) {
            self::emitFromCatalog($tick, self::MARKET, 'MARKET', null, ['{sector}' => ''], 1.0);
            Engine::setState('newsroom_last_macro', (string) $tick);
        }
        self::technicalPulse($tick);
        self::preReportConsensus($tick);
    }

    private static function emitCompany(int $tick, array $s): void
    {
        $profit = max(1000.0, (float) $s['base_profit']);
        $revenueY = $profit * 12 / 0.15;                      // roczne przychody (marża netto ~15%)
        $mln  = round($profit * (mt_rand(20, 80) / 10) / 1e6, 1);   // kontrakt: 2–8 miesięcznych zysków
        $mln2 = round($profit * (mt_rand(5, 20) / 10) / 1e6, 1);
        $ctx = [
            '{ticker}' => (string) $s['ticker'],
            '{name}'   => (string) $s['name'],
            '{sector}' => (string) $s['sector_name'],
            '{mln}'    => number_format(max(0.1, $mln), 1, ',', ' '),
            '{mln2}'   => number_format(max(0.1, $mln2), 1, ',', ' '),
            '{pct}'    => (string) mt_rand(8, 30),
            '{pct2}'   => (string) mt_rand(3, 12),
            '{pctrev}' => (string) max(1, min(60, (int) round($mln * 1e6 / max(1, $revenueY) * 100))),
            '{klient}' => self::KLIENCI[array_rand(self::KLIENCI)],
            '{kraj}'   => self::KRAJE[array_rand(self::KRAJE)],
            '{osoba}'  => self::OSOBY[array_rand(self::OSOBY)],
            '{dm}'     => self::DOMY[array_rand(self::DOMY)],
            '{fundusz}'=> self::FUNDUSZE[array_rand(self::FUNDUSZE)],
        ];
        self::emitFromCatalog($tick, self::COMPANY, 'COMPANY', (int) $s['id'], $ctx,
            (float) $s['news_sensitivity'] * max(0.5, min(1.8, (float) $s['news_impact'])));
    }

    private static function emitSector(int $tick, array $sec): void
    {
        self::emitFromCatalog($tick, self::SECTOR, 'SECTOR', (int) $sec['id'],
            ['{sector}' => (string) $sec['name']], (float) $sec['news_sensitivity']);
    }

    /** Losowanie ważone z katalogu + cooldowny realizmu + insert (ze stemplem tematu). */
    private static function emitFromCatalog(int $tick, array $catalog, string $scope, ?int $targetId, array $ctx, float $sens): void
    {
        $total = 0;
        foreach ($catalog as $t) $total += max(1, (int) $t[5]);
        $r = mt_rand(1, $total); $acc = 0; $pick = null;
        foreach ($catalog as $t) { $acc += max(1, (int) $t[5]); if ($r <= $acc) { $pick = $t; break; } }
        if (!$pick) return;
        [$kind, $type, $espi, $impact, $dur, , $head, $body] = $pick;
        $topic = (string) ($pick[8] ?? 'inne');
        if (!self::storyAllowed($scope, $targetId, $topic, $tick)) return;   // cisza > absurd
        self::insert($tick, $kind, $type, $scope, $targetId, (int) $espi,
            round((float) $impact * $sens, 3), (int) $dur, strtr($head, $ctx), strtr($body, $ctx), self::topicHash($topic));
    }

    public static function insert(int $tick, string $kind, string $type, string $scope, ?int $targetId,
                                  int $espi, float $impact, int $dur, string $head, string $body, ?int $topicId = null): void
    {
        Db::pdo()->prepare("INSERT INTO news (template_id,headline,body,type,kind,scope,target_id,is_espi,impact_strength,publish_tick,expire_tick,published_at)
                            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$topicId, mb_substr($head, 0, 250), $body, $type, $kind, $scope, $targetId, $espi, $impact, $tick, $tick + max(1, $dur), Db::now()]);
    }

    /**
     * KOMENTARZ TECHNICZNY pisany z danych (kind=technical): wybicia sygnału AT,
     * anomalie wolumenu, serie sesji, nowe szczyty. Zero wpływu na fundament —
     * ale boty AT czytają te same dane, więc komentarz często się "sprawdza".
     * Anty-spam: max 1 komentarz techniczny na spółkę na 40 ticków.
     */
    public static function technicalPulse(int $tick): void
    {
        $recent = array_fill_keys(array_map('intval',
            Engine::col("SELECT DISTINCT target_id FROM news WHERE kind='technical' AND publish_tick > ?", [$tick - 40])), true);
        foreach (Engine::all("SELECT id, ticker, price, ta_signal FROM stocks") as $s) {
            $sid = (int) $s['id'];
            if (isset($recent[$sid])) continue;
            $ta = (float) $s['ta_signal'];
            $px = number_format((float) $s['price'], 2, ',', ' ');

            // 1) mocny sygnał zbiorczy AT (świeżo — patrz anty-spam wyżej)
            if (abs($ta) >= 0.38 && mt_rand(1, 3) === 1) {
                $up = $ta > 0;
                self::insert($tick, 'technical', $up ? 'POS' : 'NEG', 'COMPANY', $sid, 0, $up ? 0.1 : -0.1, 6,
                    $up ? "Analiza rynkowa: {$s['ticker']} z silnym sygnałem kupna (AT " . sprintf('%+.2f', $ta) . ')'
                        : "Analiza rynkowa: {$s['ticker']} z silnym sygnałem sprzedaży (AT " . sprintf('%+.2f', $ta) . ')',
                    ($up ? "Zbiorczy sygnał techniczny przy kursie $px PLN wszedł w strefę kupna: średnie kroczące i oscylatory grają w tę samą stronę. "
                         : "Zbiorczy sygnał techniczny przy kursie $px PLN wszedł w strefę sprzedaży: struktura wykresu przemawia za korektą. ")
                    . 'Fundusze algorytmiczne handlują dokładnie na tym wskaźniku — podobne sygnały bywają samospełniające. Szczegóły w zakładce Analiza.');
                continue;
            }
            // 2) anomalia wolumenu: ostatni tick ≥ 4× średniej z 30
            $v = Engine::row("SELECT MAX(t) mt, AVG(v) av FROM candles WHERE stock_id=? AND t > ?", [$sid, $tick - 30]);
            if ($v && (float) $v['av'] > 20) {
                $lastV = (int) (Engine::one("SELECT v FROM candles WHERE stock_id=? AND t=?", [$sid, (int) $v['mt']]) ?: 0);
                if ($lastV > 4 * (float) $v['av'] && mt_rand(1, 2) === 1) {
                    self::insert($tick, 'technical', 'NEU', 'COMPANY', $sid, 0, 0.0, 6,
                        "Nietypowy obrót na {$s['ticker']} — wolumen " . number_format($lastV / max(1, (float) $v['av']), 1, ',', ' ') . '× wyższy od średniej',
                        "Przez rynek przeszły duże pakiety akcji przy kursie $px PLN. Sam wolumen nie mówi, kto ma rację — ale zwykle zwiastuje ruch: ktoś wie coś wcześniej albo duży gracz buduje pozycję.");
                    continue;
                }
            }
            // 3) seria sesji w jedną stronę (5 świec dziennych) / nowy szczyt 50 sesji
            $daily = Engine::all("SELECT o, c, h FROM candles_daily WHERE stock_id=? ORDER BY session DESC LIMIT 50", [$sid]);
            if (count($daily) >= 6) {
                $run = 0;
                foreach ($daily as $i => $d) {
                    if ($i >= 5) break;
                    $dirUp = (float) $d['c'] >= (float) $d['o'];
                    if ($i === 0) $run = $dirUp ? 1 : -1;
                    elseif (($run > 0) === $dirUp) $run += $run > 0 ? 1 : -1;
                    else break;
                }
                if (abs($run) >= 5 && mt_rand(1, 2) === 1) {
                    $up = $run > 0;
                    self::insert($tick, 'technical', $up ? 'POS' : 'NEG', 'COMPANY', $sid, 0, $up ? 0.06 : -0.06, 8,
                        ($up ? "Piąta wzrostowa sesja {$s['ticker']} z rzędu" : "Piąta spadkowa sesja {$s['ticker']} z rzędu"),
                        ($up ? "Popyt kontroluje notowania od tygodnia — kurs $px PLN. Długie serie przyciągają graczy momentum, ale im dłuższa seria, tym bliżej korekty."
                             : "Podaż nie odpuszcza od tygodnia — kurs $px PLN. Łapanie spadającego noża bywa kosztowne, choć kontrarianie zaczynają się przyglądać."));
                    continue;
                }
                $maxH = max(array_map(fn($d) => (float) $d['h'], $daily));
                if ((float) $s['price'] >= $maxH * 0.999 && count($daily) >= 20 && mt_rand(1, 3) === 1) {
                    self::insert($tick, 'technical', 'POS', 'COMPANY', $sid, 0, 0.08, 8,
                        "{$s['ticker']} atakuje najwyższy poziom od " . count($daily) . ' sesji',
                        "Kurs $px PLN zrównał się z lokalnym maksimum. Wybicie na nowe szczyty to klasyczny sygnał kontynuacji trendu — nad kursem nie wisi żadna zamrożona podaż z wyższych poziomów.");
                }
            }
        }
    }

    /**
     * KONSENSUS PRZED WYNIKAMI: ~5 ticków przed raportem rynek poznaje oczekiwania
     * analityków (bez czasowych nakładek z wydarzeń — analitycy nie wiedzą wszystkiego).
     * Gracz może zagrać pod raport; po publikacji liczy się bicie/rozminięcie z konsensusem.
     */
    public static function preReportConsensus(int $tick): void
    {
        foreach (Engine::all("SELECT s.*, sec.profit_climate AS sector_climate FROM stocks s
                              JOIN sectors sec ON sec.id = s.sector_id WHERE s.next_report_tick = ?", [$tick + 5]) as $s) {
            $prev = ((float) $s['last_profit']) ?: (float) $s['base_profit'];
            $per = max(1, (int) $s['report_period']);
            $trend = ((float) $s['profit_trend'] + (float) $s['sector_climate'] + (float) $s['growth_potential'] * $per) / 100.0;
            $expProfit = $prev * (1 + $trend) * (1 + mt_rand(-3, 3) / 100);   // analitycy też mają rozrzut
            $expEps = round($expProfit * 12.0 / max(1.0, (float) $s['total_shares']), 2);
            self::insert($tick, 'fundamental', 'NEU', 'COMPANY', (int) $s['id'], 0, 0.0, 6,
                "Konsensus przed wynikami {$s['ticker']}: oczekiwany zysk " . number_format($expProfit, 0, ',', ' ') . ' PLN',
                'Ankieta wśród analityków ' . self::DOMY[array_rand(self::DOMY)] . ' i pozostałych biur: mediana prognoz zakłada zysk netto '
                . number_format($expProfit, 0, ',', ' ') . ' PLN (EPS ~' . number_format($expEps, 2, ',', ' ') . ' PLN). '
                . 'Publikacja raportu lada moment. Wynik POWYŻEJ konsensusu zwykle podbija kurs, PONIŻEJ — ciąży; sama liczba znaczy mniej niż zaskoczenie.');
        }
    }
}
