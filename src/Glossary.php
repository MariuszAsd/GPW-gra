<?php
/**
 * Słowniczek gry — JEDNO źródło wyjaśnień dla początkujących.
 *
 * Każde pojęcie (klucz) ma: nazwę, wyjaśnienie w 1–2 zdaniach „po ludzku” i kotwicę w Pomocy.
 * Strony pokazują je dymkiem `term('klucz')` (public/_boot.php) — ten sam tekst wszędzie, więc gracz
 * uczy się raz. Pełna lista renderuje się automatycznie jako sekcja „Słowniczek” w pomoc.php.
 * Dodajesz mechanikę z nowym słowem → dopisujesz je tutaj i wstawiasz `term()` tam, gdzie pada.
 */
final class Glossary
{
    /** klucz => [nazwa, wyjaśnienie, kotwica w pomoc.php ('' = brak sekcji)] */
    public static function all(): array
    {
        return [
            // rynek i arkusz
            'kurs'          => ['Kurs', 'Cena ostatniej zawartej transakcji. Po niej wyceniamy akcje w Twoim portfelu.', 'arkusz'],
            'zmiana'        => ['Zmiana', 'O ile procent kurs różni się od kursu otwarcia dzisiejszej sesji (ustalonego na fixingu).', 'fixing'],
            'bid'           => ['Bid', 'Najwyższa cena, po jakiej ktoś chce teraz KUPIĆ. Tyle dostaniesz, sprzedając natychmiast (PKC).', 'arkusz'],
            'ask'           => ['Ask', 'Najniższa cena, po jakiej ktoś chce teraz SPRZEDAĆ. Tyle zapłacisz, kupując natychmiast (PKC).', 'arkusz'],
            'spread'        => ['Spread', 'Różnica między Ask a Bid. Im węższy, tym płynniejsza spółka i mniejszy koszt wejścia i wyjścia.', 'plynnosc'],
            'arkusz'        => ['Arkusz zleceń', 'Lista wszystkich czekających ofert kupna (bid) i sprzedaży (ask). Zlecenie z limitem trafia do arkusza i czeka na drugą stronę.', 'arkusz'],
            'plynnosc'      => ['Płynność', 'Jak łatwo kupić lub sprzedać bez ruszania kursu. Przy niskiej płynności PKC potrafi wykonać się po wyraźnie gorszej cenie.', 'plynnosc'],
            'obrot'         => ['Obrót', 'Za ile PLN handlowano akcjami spółki od otwarcia sesji. Duży obrót = żywy rynek, łatwe wejście i wyjście.', 'plynnosc'],
            'widelki'       => ['Widełki i zawieszenie', 'Gdy kurs zmieni się o ponad 20% od otwarcia, notowania są zawieszane na kilka minut — jak na GPW. Zlecenia czekają na wznowienie.', 'widelki'],
            'fixing'        => ['Fixing (aukcja otwarcia)', 'Pierwsze 10 minut po otwarciu: zlecenia z limitem zbierają się w arkuszu, a o 8:00 jeden kurs otwarcia kojarzy je wszystkie.', 'fixing'],
            'sesja'         => ['Sesja', 'Jeden dzień giełdowy gry: od otwarcia (7:50) do zamknięcia (22:00). Poza sesją kursy stoją, a zleceń nie ma jak złożyć.', 'fixing'],
            'indeks'        => ['Indeks MAK40', 'Jedna liczba pokazująca, jak idzie całej giełdzie: kursy wszystkich spółek ważone ich wielkością. Start: 1000 punktów.', 'fundusz'],
            // zlecenia
            'limit'         => ['Zlecenie LIMIT', 'Podajesz swoją cenę. Kupisz po niej lub taniej (sprzedasz po niej lub drożej) — albo zlecenie czeka w arkuszu na drugą stronę.', 'limit'],
            'pkc'           => ['PKC (po każdej cenie)', 'Bierzesz od razu to, co jest w arkuszu, po najlepszych dostępnych cenach. Szybko, ale przy małej płynności drożej, niż myślisz.', 'pkc'],
            'stopbuy'       => ['Stop-buy', 'Kupno, które włącza się dopiero, gdy kurs WZROŚNIE do progu — do łapania wybić. Gotówka na nie jest zarezerwowana z góry.', 'stopbuy'],
            'sl'            => ['Stop-Loss (SL)', 'Automatyczna sprzedaż, gdy kurs SPADNIE do progu — ucina stratę bez Twojego udziału, także gdy nie ma Cię w grze.', 'sl'],
            'tp'            => ['Take-Profit (TP)', 'Automatyczna sprzedaż, gdy kurs WZROŚNIE do progu — zamienia zysk w gotówkę, zanim rynek go zabierze.', 'tp'],
            'trailing'      => ['SL kroczący', 'Stop-Loss, którego próg sam podąża za rosnącym kursem (np. zawsze 8% pod szczytem). Kurs rośnie — zysk chroniony; zawraca — sprzedaż.', 'trailing'],
            'obronne'       => ['Zlecenie obronne', 'Wspólna nazwa SL i TP: pilnują kursu za Ciebie i same sprzedają po przekroczeniu progu. Akcje pod nimi są zarezerwowane.', 'sl'],
            'typ_zlecenia'  => ['Typ zlecenia', 'KUPNO/SPRZEDAŻ = zwykłe zlecenie z limitem. OBRONNE = SL/TP pilnujące kursu. STOP-BUY = kupno czekające na przebicie progu.', 'limit'],
            'waznosc'       => ['Ważność zlecenia', 'Bezterminowe czeka, aż je anulujesz. Sesyjne samo znika z końcem dnia giełdowego, a rezerwacja wraca.', 'waznosc'],
            'zamrozone'     => ['Zamrożone (rezerwacja)', 'Pieniądze albo akcje odłożone pod Twoje czekające zlecenia. Nadal są Twoje i liczą się do kapitału — wracają po realizacji lub anulowaniu.', 'limit'],
            'prowizja'      => ['Prowizja', 'Opłata od wartości sprzedaży (zwykle 0,5%), pobierana tylko przy sprzedaży. Trafia do skarbca gry, z którego płacone są nagrody.', 'prowizja'],
            // portfel i wynik
            'kapital'       => ['Kapitał', 'Wszystko, co masz, po dzisiejszych kursach: gotówka + zamrożone + akcje + lokaty, fundusz, zapisy IPO i buy-in w wyzwaniu.', 'cel'],
            'gotowka'       => ['Wolna gotówka', 'Tyle możesz teraz wydać na zakupy. Nie obejmuje pieniędzy zamrożonych pod zleceniami ani lokat.', 'cel'],
            'pozycja'       => ['Pozycja', 'Akcje jednej spółki w Twoim portfelu. Otwierasz ją kupnem, zamykasz sprzedażą.', 'limit'],
            'srednia'       => ['Średnia cena zakupu', 'Ile średnio zapłaciłeś za jedną akcję tej spółki (dokupując, średnia się zmienia). Porównaj z kursem — to Twój wynik na pozycji.', 'limit'],
            'udzial'        => ['Udział w portfelu', 'Jaką część wartości Twoich akcji stanowi ta spółka. Duży udział = duże uzależnienie od jednej firmy.', 'cel'],
            'wynik'         => ['Wynik (niezrealizowany)', 'Różnica między dzisiejszą wartością akcji a tym, co za nie zapłaciłeś. „Na papierze” — prawdziwy staje się dopiero po sprzedaży.', 'prowizja'],
            'zrealizowany'  => ['Zrealizowany wynik', 'Zysk lub strata z akcji, które już sprzedałeś: cena sprzedaży po prowizji minus średni koszt zakupu.', 'prowizja'],
            'stopa_zwrotu'  => ['Stopa zwrotu', 'O ile procent urósł (lub zmalał) Twój kapitał względem punktu startu. W rankingu i ligach liczy się procent, nie kwota.', 'cel'],
            'vs_mak40'      => ['vs MAK40', 'Twoja stopa zwrotu minus zmiana indeksu MAK40 w tym samym czasie. Dodatnia = pobijasz rynek; ujemna = fundusz indeksowy zarobiłby więcej.', 'fundusz'],
            'drabinka'      => ['Drabinka', 'Progi stopy zwrotu od startu: +10%, +25%, +50%… aż do +900%. Każdy zdobyty szczebel to odznaka i Tokeny.', 'cel'],
            'liga'          => ['Liga tygodnia / miesiąca', 'Rywalizacja od zera w każdym okresie: liczy się stopa zwrotu od początku tygodnia lub miesiąca. Podium tygodnia dostaje PLN ze skarbca, miesiąca — Tokeny.', 'cel'],
            'skarbiec'      => ['Skarbiec gry', 'Zebrane prowizje od obrotu. Z niego płacone są nagrody ligi, dopłaty do wyzwań i odsetki lokat — pieniądze wracają do graczy.', 'prowizja'],
            'tokeny'        => ['Tokeny inwestora', 'Waluta premium: za pakiety (np. skaner AT) i kosmetykę. Nigdy nie kupisz za nie wirtualnych PLN — ranking zostaje uczciwy.', 'cel'],
            // spółka i raporty
            'kapitalizacja' => ['Kapitalizacja', 'Ile warta jest cała spółka: kurs × liczba wszystkich akcji. Duże spółki mocniej ważą w indeksie MAK40.', 'fundusz'],
            'fundamentalna' => ['Wartość fundamentalna', 'Ile spółka „powinna” być warta według zysków i sytuacji w branży. Kurs krąży wokół niej — boty domykają lukę, newsy ją przesuwają.', 'wydarzenia'],
            'cz'            => ['C/Z (cena do zysku)', 'Ile lat zysków potrzeba, żeby „spłacić” cenę akcji. Niskie C/Z = tanio względem zysków, wysokie = rynek oczekuje wzrostu.', 'dywidenda'],
            'eps'           => ['EPS (zysk na akcję)', 'Zysk spółki podzielony przez liczbę akcji. Rośnie EPS — zwykle rośnie wycena i dywidenda.', 'dywidenda'],
            'dywidenda'     => ['Dywidenda', 'Część zysku wypłacana Ci za każdą posiadaną akcję przy miesięcznym raporcie. W dniu wypłaty kurs jest pomniejszany o jej wartość.', 'dywidenda'],
            'niespodzianka' => ['Niespodzianka (raport)', 'O ile wynik spółki różni się od tego, czego rynek się spodziewał (konsensus). Pozytywna niespodzianka zwykle podbija kurs, negatywna ciąży.', 'wydarzenia'],
            'espi'          => ['ESPI', 'Oficjalny komunikat spółki (jak na prawdziwej giełdzie): kontrakty, wyniki, zmiany w zarządzie. Twarde fakty, nie plotki.', 'wydarzenia'],
            'at'            => ['Analiza techniczna (AT)', 'Wnioski z samego wykresu: 10 wskaźników daje sygnał od „sprzedaj” do „kupuj”. Boty techniczne grają dokładnie ten sam sygnał, który widzisz.', 'wydarzenia'],
            'werdykt'       => ['Rekomendacja', 'Opinia analityków domu maklerskiego: kupuj / trzymaj / sprzedaj z ceną docelową. Wskazówka, nie gwarancja.', 'wydarzenia'],
            'cena_docelowa' => ['Cena docelowa', 'Kurs, do którego według analityków spółka powinna dojść. Potencjał = o ile procent różni się od dzisiejszego kursu.', 'wydarzenia'],
            // produkty i konkursy
            'fundusz'       => ['Fundusz MAK40', 'Kupujesz „cały rynek” jedną decyzją: jednostki funduszu rosną i spadają razem z indeksem. Bez wybierania spółek.', 'fundusz'],
            'nav'           => ['Wycena jednostki', 'Cena jednej jednostki funduszu = indeks MAK40 podzielony przez 10. Rośnie i spada razem z rynkiem.', 'fundusz'],
            'lokata'        => ['Lokata', 'Zamrażasz gotówkę na kilka sesji za stały procent. Kapitał lokaty dalej liczy się do Twojego wyniku; zerwanie oddaje kapitał bez odsetek.', 'lokaty'],
            'ipo'           => ['IPO', 'Debiut nowej spółki na giełdzie. Zapisujesz się po stałej cenie emisyjnej; przy nadmiarze chętnych dostajesz mniej (redukcja), a nadpłata wraca.', 'ipo'],
            'redukcja'      => ['Redukcja', 'Gdy chętnych na IPO jest więcej niż akcji, każdy dostaje proporcjonalnie mniej. Pieniądze za nieprzydzielone akcje wracają na konto.', 'ipo'],
            'od_emisji'     => ['Od emisji', 'O ile procent dzisiejszy kurs różni się od ceny emisyjnej — tyle zarobili (lub stracili) ci, którzy dostali akcje w IPO.', 'ipo'],
            'buyin'         => ['Buy-in', 'Kwota, którą grasz w wyzwaniu na osobnym portfelu. Wraca po wyzwaniu w formie, do jakiej ją doprowadziłeś (gotówka + akcje).', 'wyzwania'],
            'wpisowe'       => ['Wpisowe', 'Opłata za udział w wyzwaniu — zasila pulę nagród dla podium. Poza wynikiem handlu to jedyne, co możesz stracić.', 'wyzwania'],
            'pula'          => ['Pula nagród', 'Suma wpisowych uczestników plus dopłata skarbca gry. Po wyzwaniu dzielą ją najlepsi.', 'wyzwania'],
            'kapital_wyzwania' => ['Kapitał wyzwania', 'Wartość osobnego portfela wyzwania (gotówka + akcje po kursie). Startuje od buy-inu; wynik to zmiana w procentach.', 'wyzwania'],
            'sezon'         => ['Sezon', 'Seria wyzwań z punktami za miejsca. Punkty odblokowują nagrody na ścieżce darmowej i premium.', 'wyzwania'],
            'karnet'        => ['Karnet premium', 'Płatny Tokenami dostęp do ścieżki premium sezonu z lepszymi nagrodami za te same punkty.', 'wyzwania'],
        ];
    }

    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }
}
