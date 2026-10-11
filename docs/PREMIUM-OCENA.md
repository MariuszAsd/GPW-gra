# Warstwa premium Maklerii — ocena i rekomendacja

*Dokument dla właściciela. Stan na 11.10.2026, schemat bazy v43. Przegląd wykonano na kopii repozytorium bez żadnych zmian w kodzie ani na produkcji. Kwoty przychodu w tym dokumencie są **założeniami do policzenia**, nie danymi rynkowymi.*

*Jak powstał ten dokument: panel czterech niezależnych propozycji (perspektywy: inwestor-praktyk, wzrost i retencja, prawo i zaufanie, model cenowy) ocenionych przez trzech sędziów i scalonych w jedną rekomendację; fakty o kodzie zebrane z repozytorium. Testy, na które powołuje się sekcja 1, potwierdzone niezależnie: QA 157/157 na SQLite i MySQL (11.10.2026).*

---

## 1. Odpowiedź wprost

**Gra działa prawidłowo.** W trakcie przeglądu uruchomiono pełny cykl testów na świeżym świecie: wszystkie pliki PHP przechodzą kontrolę składni, 100 ticków rynku, test integralności (`verify.php`: pieniądz w świecie równa się kotwicy co do grosza, żadnych ujemnych sald ani rezerwacji) i QA-bot grający przez HTTP — **157/157 asercji zaliczonych**. Jedyne, co na produkcji czeka na Ciebie, to znane blizny sprzed lipcowych poprawek (jedno kliknięcie „Rekoncyliacja rezerwacji” w panelu GM) oraz zmiana hasła admina. Nic w grze nie wymaga pilnej naprawy.

**Czy warto rozbudowywać premium teraz? Tak — ale w ściśle określonym sensie.** Warstwa premium jest dziś poprawna technicznie, lecz słabo zaprojektowana sprzedażowo: darmowy obieg Tokenów pokrywa ok. 90% ceny obu pakietów (aktywny gracz nie ma powodu płacić), najdroższy pakiet sprzedaje liczby, które widać za darmo w sąsiedniej zakładce, karnet sezonu *rozdaje* Tokeny zamiast je zbierać, nikt nie dostaje przypomnienia, że pakiet wygasa, a sklep nie ma regulaminu, polityki prywatności ani bramki wieku. Warto to **uporządkować teraz**: jeden czytelny produkt „Makleria Pro”, fundament prawny i 18+, przypomnienia o wygasaniu, kody promocyjne, a potem dwie–trzy funkcje, za które dorosły znający rynek faktycznie płaci w prawdziwym biurze maklerskim (statystyki własnej skuteczności, alerty push, skaner). To zadania na kilka tygodni, które jednocześnie **wspierają retencję** — a retencja i wzrost są dziś ważniejsze niż przychód.

**Uczciwa ocena opłacalności przy kilku graczach: przychód będzie bliski zera przez wiele miesięcy.** Przy typowej dla darmowych gier konwersji 2–5% i średniej wpłacie ok. 20 zł miesięcznie (założenie), 50 aktywnych graczy daje 20–50 zł/mies., 200 graczy — 80–200 zł, a dopiero ok. 500–1000 graczy — kilkaset złotych netto po VAT i prowizji operatora. Włączenie prawdziwych płatności (PayU) ma sens ekonomiczny **od ok. 200 aktywnych miesięcznie** i tylko po opinii prawnika. Do tego czasu premium ma trzy cele: zatrzymać graczy (alerty, statystyki, przypomnienia), zmierzyć chęć płacenia (licznik „Chcę kupić”, Pro za Tokeny, kody) i mieć gotową, legalną ścieżkę na dzień, gdy ruszy makleria.pl i marketing. **Nie przebudowuj premium kosztem wzrostu** — kolejność ma być: fundament → jeden produkt → funkcje retencyjne → reszta po wzroście bazy.

---

## 2. Co dziś działa, a co jest niedokończone

| Element | Stan | Uwaga |
|---|---|---|
| Tokeny inwestora + pełna księga operacji | **Działa** | Zasada „tokeny nigdy nie zamieniają się na PLN” jest w kodzie i na stronie głównej — dobra podstawa. |
| Pakiet Analityka (15 tok. / 7 sesji) | Działa | Sprzedaje wygodę (kolumna sygnału, skaner, alerty, rekomendacje dzień wcześniej). Sygnał pojedynczej spółki jest i tak darmowy; rekomendacje nie ruszają kursów (boty ich nie czytają), więc „dzień wcześniej” to słaby argument dla kogoś, kto zna rynek. |
| Raport Premium (25 tok. / 7 sesji) | Działa, **słabo zaprojektowany** | Wycenę i premię każdy policzy z darmowej zakładki Info. Ekskluzywne są tylko surowe parametry silnika (beta, zmienność, odporność) — a tych pokazywać wprost nie powinniśmy. |
| Kosmetyka (tytuły, kolory, ramki) | Działa | Katalog 190 tokenów, każdy przedmiot kupowany raz — po wyczerpaniu Tokeny nie mają na co iść. |
| Karnet sezonowy (30 tok.) | Działa technicznie, **ekonomicznie odwrotnie** | Zwraca do 60 tokenów + tytuł, więc jest źródłem Tokenów, nie wydatkiem. Sezon istnieje tylko, gdy GM ręcznie utworzy serię; tytuł „Legenda Sezonu” przy kilku graczach jest nieosiągalny i nigdy nie został przyznany. |
| Trial premium (2 sesje obu pakietów) | Działa | Gracz, który raz kupił dowolny pakiet, nigdy nie dostanie trialu drugiego. |
| Darmowy obieg Tokenów | Działa, **za hojny** | Seria + misje ≈ 36 tokenów/tydz. przy obu pakietach za 40/tydz. Komentarz w kodzie zakłada „tydzień ≈ Pakiet Analityka”, faktycznie ≈ oba pakiety. |
| Sklep i bramka PayU | **Kod kompletny, nieuruchomiony** | Webhook jest zrobiony dobrze (przejęcie zamówienia atomowo, kontrola kwoty, odporność na powtórki). Brakuje: sekretów PayU, testu w sandboxie, e-maila kupującego w zamówieniu, obsługi zwrotów, limitu zamówień na gracza, faktur. |
| Zakupy za Tokeny | Działa, **bez transakcji** | Pobranie tokenów i aktywacja pakietu/kosmetyki/karnetu to osobne zapytania — przy awarii bazy między nimi gracz traci tokeny bez produktu (mało prawdopodobne, ale przed sprzedażą za PLN trzeba to domknąć). |
| Przypomnienie o wygasaniu pakietu | **Brak** | Gracz dowiaduje się o końcu pakietu, gdy znika kolumna — i nie wraca do sklepu. |
| Metryki w panelu GM | Mylące | „Konwersja” liczy każdy wydatek Tokenów (także darmowych) jako płacącego; przychód i ARPPU są poprawne. |
| Regulamin, polityka prywatności, dane sprzedawcy | **Brak** | Rejestracja wymaga tylko loginu i hasła; e-mail opcjonalny — nie ma komu wysłać potwierdzenia zakupu ani faktury. |
| Bramka wieku 18+ | **Brak** | Żadnego pola daty urodzenia, checkboxa ani oświadczenia. |

Wniosek: **nic nie jest zepsute, ale sklep w obecnej formie nie jest gotowy ani do sprzedaży, ani do przekonania dorosłego gracza.**

---

## 3. Rekomendowany model: jedno „Makleria Pro”, Tokeny tylko za grę

### Zasady modelu

1. **Jeden produkt zamiast dwóch pakietów tygodniowych**: „Makleria Pro” łączy wszystko, co dziś dają Analityk i Raport, plus nowe funkcje (statystyki, alerty, skaner, eksport). Stare pakiety nie znikają z bazy — zostają jako „aliasy”, więc nikt nie traci aktywnego pakietu i nie trzeba ruszać danych graczy.
2. **Pro kupuje się za złotówki ALBO za Tokeny zdobyte grą** — obie drogi dają identyczne funkcje, więc ranking pozostaje uczciwy. Gracz codzienny (nasz ambasador) wyrabia miesiąc Pro w ok. 5–6 tygodni; gracz weekendowy dopłaca.
3. **Tokenów nie sprzedajemy za złotówki** (likwidacja zakładki „Doładuj”). To jednym ruchem usuwa najpoważniejszy problem prawny (nagrody z lig przestają mieć cenę w PLN), upraszcza VAT i prawa konsumenta do jednej usługi cyfrowej na czas, i kończy sytuację, w której sprzedajemy walutę, którą rozdajemy za darmo.
4. **Pro nigdy nie dotyka rynku**: żadnej gotówki, lepszych kursów, pierwszeństwa w arkuszu, dodatkowych typów zleceń. Wszystko, co pokazuje, da się odczytać ręcznie za darmo albo dotyczy wyłącznie własnego konta gracza. Dane silnika (zaplanowane wydarzenia, nadchodzące krachy, surowe parametry spółek) **nigdy** nie trafiają do Pro.
5. **Bez automatycznego odnawiania z karty.** Przedpłata + przypomnienie przed końcem. Opcjonalnie auto-odnowienie wyłącznie z Tokenów, po świadomym włączeniu.

### Cennik (propozycja, ceny brutto z VAT 23%)

| Wariant | Za PLN | Za Tokeny | Uwaga |
|---|---|---|---|
| Pro 30 dni | 19,99 zł | 90 tokenów | wariant „kotwica”, wyróżniony w sklepie |
| Pro 90 dni | 49,99 zł | 240 tokenów | ok. 17% taniej |
| Pro 365 dni | *później* | — | dodać, gdy ktokolwiek odnowi dwa razy |
| Trial Pro | 7 dni, raz na konto | — | po aktywnej grze (dzisiejsze warunki trialu), także dla graczy, którzy mieli stary pakiet |
| Polecenia | polecony: 7 dni Pro · polecający: 7 dni Pro + 25 tokenów | — | warunek aktywności poleconego zostaje |
| Kosmetyka | — | 10–40 tokenów | jak dziś + nowe pozycje (motyw karty wyniku, kosmetyka sezonowa limitowana) |
| Karnet sezonu | — | 30 tokenów | wypłaca kosmetykę i wirtualne PLN, **nie Tokeny** |

**Czas trwania:** dla kupującego za złotówki liczony w **dniach kalendarzowych** (konsument musi wiedzieć, do kiedy zapłacił — „30 sesji giełdowych” jest niejednoznaczne). Technicznie to jedna nowa kolumna z datą końca obok dzisiejszego numeru sesji. Jeśli wolisz zostać przy sesjach, w sklepie, e-mailu i na koncie musi być zawsze pokazana przewidywana **data** końca, nie numer sesji.

**Kalibracja darmowych Tokenów:** dziś ≈ 36/tydz., docelowo ≈ 17–20/tydz. (seria logowań bez zmian; misje dnia: mniej tokenów za pojedyncze, premia za komplet). Zmianę ogłosić z wyprzedzeniem, **salda graczy zostają**, a stałe przenieść do ustawień w panelu GM, żeby stroić je bez wdrożeń. Nie ciąć skokowo — misje dnia są dziś głównym motorem codziennych powrotów.

### Decyzja prawna do podjęcia z prawnikiem (dwa warianty)

- **Wariant A (rekomendowany docelowo):** hybryda jak wyżej; nagrody w Tokenach za wynik rynkowy (liga miesiąca 20/10/5, wyzwania 10/5, drabinka) zostają, bo Tokeny nie mają ceny w złotówkach i są niezbywalne. *Prawnik musi potwierdzić, że Token kupujący Pro (które ma cenę) nie czyni nagrody ligowej „wygraną o wartości majątkowej”.*
- **Wariant B (zapasowy, jeśli prawnik ma wątpliwości):** nagrody za wynik rynkowy zamienić na wirtualne PLN ze skarbca + odznaki/tytuły; Tokeny przyznawane wyłącznie za aktywność nielosową (seria, misje, polecenia, kody). To zmiana w trzech miejscach kodu, nie przebudowa gry.

Oba warianty są lepsze prawnie niż stan dzisiejszy (Tokeny z ceną 0,33–0,50 zł wygrywane w lidze).

---

## 4. Funkcje premium w kolejności wdrażania

Każda pozycja = osobne wdrożenie z testami (SQLite i MySQL) i zielonym health checkiem, zgodnie z tym, jak pracujemy. Pracochłonność: S = 1–2 dni, M = 3–4 dni, L = 5–7 dni (jeden programista, z testami i wdrożeniem).

| # | Funkcja | Praca | Kiedy |
|---|---|---|---|
| 0 | Porządki techniczne, poprawna metryka, kody promocyjne | M | teraz |
| 1 | Fundament prawny + bramka 18+ | M | teraz (po tekstach od prawnika) |
| 2 | Jedno „Makleria Pro” + przypomnienia o wygasaniu + trial 7 dni | M | teraz |
| 3 | Polecenia 2.0 (dni Pro po obu stronach) | S | teraz |
| 4 | Statystyki inwestora „czy jestem dobry, czy mam szczęście” | L | zaraz potem |
| 5 | Alerty własne push (cena, raport, dywidenda, SL/TP) | M | zaraz potem |
| 6 | Trafność rekomendacji DM · Sygnał AT za darmo · Raport DM bez parametrów silnika | S | zaraz potem |
| 7 | Skaner spółek z filtrami + kalendarz inwestora | M | po pierwszych ~50 aktywnych |
| 8 | Eksport CSV + raport miesięczny e-mailem | S | po pierwszych ~50 aktywnych |
| 9 | Status Pro w kosmetyce + sezon jako „battle pass” kosmetyczny | M | po ~20 aktywnych |
| — | Kluby (prywatne ligi ze znajomymi) | L | po ~20 aktywnych — najlepszy pomysł na wzrost, ale dziś byłyby puste |

### 0. Porządki techniczne, metryka, kody promocyjne — M
- **Co daje graczowi:** pewność, że zakup za Tokeny nigdy „nie zniknie” w połowie; pole „Mam kod” w sklepie zamiast „napisz na czacie po tokeny”.
- **Dlaczego dorosły zapłaci:** nie zapłaci za to — ale reklamacja „zapłaciłem, nie mam Pro” u dorosłego klienta kończy się chargebackiem i UOKiK-iem, nie wiadomością na czacie. Płatności muszą być nudne i pewne.
- **Dlaczego to nie pay-to-win:** zero wpływu na rozgrywkę.
- **Zakres:** zakupy za Tokeny w jednej transakcji; limit jednego otwartego zamówienia na gracza; status „zwrócone” i przycisk zwrotu w GM; e-mail kupującego w zamówieniu PayU; metryka „konwersja” tylko z realnych płatności; kody promocyjne tworzone w GM (np. kod w artykule blogowym = 7 dni Pro, limit 100 użyć); licznik kliknięć „Chcę kupić” przy wyłączonym PayU jako miara popytu.

### 1. Fundament prawny + bramka 18+ — M
- **Co daje graczowi:** regulamin, politykę prywatności, dane firmy, jasne warunki zakupu, potwierdzenie na e-mail.
- **Dlaczego dorosły zapłaci:** to warunek, żeby w ogóle legalnie przyjąć pierwszą złotówkę od konsumenta w Polsce i żeby PayU podpisało umowę. Dorosły gracz oczekuje regulaminu i faktury — ich brak obniża zaufanie bardziej niż cena.
- **Dlaczego to nie pay-to-win:** dotyczy konta i procesu zakupu, nie gry.
- **Zakres:** szczegóły w sekcji 5.

### 2. Jedno „Makleria Pro” + przypomnienia + trial — M
- **Co daje graczowi:** jeden status, jedna decyzja, licznik „Pro aktywne do [data]”, przypomnienie dzień przed końcem (dzwonek + push + e-mail) z przyciskiem „Przedłuż”, po wygaśnięciu jednorazowy baner „co zebrało się przez tydzień” (X alertów, Y rekomendacji, zmiana Twoich statystyk).
- **Dlaczego dorosły zapłaci:** rozumie abonament miesięczny jak w serwisie giełdowym; nie będzie przeliczał „15 tokenów za 7 sesji”. Większość utraconych odnowień to zapomnienie, nie decyzja — przypomnienie to najtańsza dźwignia przychodu w każdym modelu czasowym.
- **Dlaczego to nie pay-to-win:** ten sam zestaw dostaje gracz, który zdobył Tokeny grą; żadnej zmiany w rynku.

### 3. Polecenia 2.0 — S
- **Co daje graczowi:** polecony dostaje 7 dni Pro, polecający 7 dni Pro + 25 tokenów za każdego aktywnego poleconego (limit 10 zostaje).
- **Dlaczego dorosły zapłaci:** nie płaci — poleca. „Dostaniesz raporty jak u mnie” to konkret do pokazania koledze; gracz, który polecił 4 osoby, ma miesiąc Pro bez płacenia — i to dokładnie ten gracz, którego chcemy.
- **Dlaczego to nie pay-to-win:** nagroda zależy od aktywności poleconego, nie od wyniku na rynku.

### 4. Statystyki inwestora — L
- **Co daje graczowi:** nowa strona „Statystyki”: odsetek zyskownych pozycji, średni zysk vs średnia strata, czas trzymania, maksymalne obsunięcie kapitału, zmienność portfela, zysk/ryzyko, „vs MAK40” za dowolny okres, udział sektorów, suma prowizji, dyscyplina (ile zleceń miało SL/TP, ile stopów zadziałało), 5 najlepszych i najgorszych spółek. Darmowy widzi 3 liczby + jedną prawdziwą zajawkę z kłódką („Twoje maks. obsunięcie: −6,2% — zobacz, co je spowodowało”).
- **Dlaczego dorosły zapłaci:** to jedyna rzecz, której osoba znająca rynek naprawdę chce od symulatora: obiektywny raport o własnym stylu — czy tnie straty, czy przetrzymuje zyski, czy wynik to umiejętność, czy jedna szczęśliwa pozycja. Prawdziwe biura maklerskie tego nie dają; płaci się za to w zewnętrznych dziennikach transakcji.
- **Dlaczego to nie pay-to-win:** patrzy wyłącznie wstecz, na własne dane gracza; nie zdradza nic o rynku ani o innych. Liczone raz na sesję z pamięcią podręczną, poza pulsem rynku.

### 5. Alerty własne push — M
- **Co daje graczowi:** „kurs przebił X”, „zmiana w sesji ≥ Y%”, „jutro raport spółki z Twojego portfela”, „zbliża się dywidenda”, „zadziałał Twój SL/TP” — na telefon. Darmowy: 2–3 alerty cenowe; Pro: bez limitu i wszystkie typy.
- **Dlaczego dorosły zapłaci:** nie siedzi w grze 07:50–22:00, ma pracę. Alert to jedyny sposób, żeby „grać” bez patrzenia — standard płatnych kont w prawdziwych aplikacjach maklerskich. Każdy alert to też powrót do gry (retencja) bez wysiłku z naszej strony.
- **Dlaczego to nie pay-to-win:** powiadamia o faktach publicznych (kurs, data raportu widoczna w zakładce Info), z tym samym opóźnieniem, jakie ma każdy na Rynku. Nie wykonuje zleceń i nie zna przyszłości.

### 6. Trafność rekomendacji DM · Sygnał AT za darmo · Raport DM bez parametrów silnika — S
- **Co daje graczowi:** na stronie rekomendacji statystyka „ile werdyktów »kupuj« z ostatnich 60 sesji zarobiło po 5/10 sesjach”; kolumna sygnału AT na Rynku dla wszystkich; w Raporcie DM opisy („rozchwiana”, „twierdza”) zamiast liczb z silnika, ryzyko liczone z historii kursu.
- **Dlaczego dorosły zapłaci:** doświadczony inwestor nie ufa rekomendacji, dopóki nie zna jej historycznej trafności — to zamienia słaby argument „dzień wcześniej” w narzędzie oceny informacji. Darmowy sygnał AT usuwa irytującą kłódkę na liczbie, którą i tak widać spółka po spółce.
- **Dlaczego to nie pay-to-win:** analiza przeszłości z publicznych kursów; ukrycie parametrów silnika *zmniejsza* dzisiejszą asymetrię.

### 7. Skaner z filtrami + kalendarz inwestora — M
- **Co daje graczowi:** filtrowanie 76+ spółek po C/Z, stopie dywidendy, zmianie 5/20/60 sesji, odległości od maksimum, sygnale AT, obrocie; zapisane zestawy filtrów. Kalendarz raportów, dywidend, IPO i rozliczeń lig z widokiem „w moim portfelu”.
- **Dlaczego dorosły zapłaci:** od skanera i kalendarza zaczyna dzień każdy, kto inwestował naprawdę (Stooq, Biznesradar). Przy 76 spółkach i IPO ręczne przeklikiwanie kart jest możliwe, ale czasochłonne — Pro sprzedaje czas.
- **Dlaczego to nie pay-to-win:** każda liczba jest widoczna za darmo na karcie spółki; darmowy skaner ma 2–3 filtry naraz. Kalendarz pokazuje datę raportu, nigdy jego wynik ani zaplanowane wydarzenia newsroomu.

### 8. Eksport CSV + raport miesięczny — S
- **Co daje graczowi:** transakcje, zlecenia, historia kapitału i księga gotówki jako plik dla Excela; miesięczny e-mail „Twój miesiąc w Maklerii” z obsunięciem, vs MAK40 i trzema wnioskami.
- **Dlaczego dorosły zapłaci:** chce liczyć po swojemu w arkuszu — eksport to standard „wyciągu z rachunku”; raport miesięczny to powód powrotu po przerwie. Eksport jest też realizacją prawa do przenoszenia danych (RODO).
- **Dlaczego to nie pay-to-win:** wyłącznie własne dane.

### 9. Status Pro w kosmetyce + sezon kosmetyczny — M
- **Co daje graczowi:** znacznik „Pro” przy nicku (ranking, czat, profil), ramka dostępna tylko w czasie Pro, motyw publicznej karty wyniku; karnet sezonu wypłaca limitowaną ramkę „Sezon 10/2026” i tytuł zamiast Tokenów; sezon startuje sam co miesiąc, a próg „Legendy Sezonu” jest osiągalny za aktywność, nie tylko za wygrane.
- **Dlaczego dorosły zapłaci:** status w społeczności, w której się rywalizuje, działa i u dorosłych — byle estetyka była „maklerska”, nie cukierkowa. Ładniejsza karta wyniku wędruje do znajomych = darmowa reklama.
- **Dlaczego to nie pay-to-win:** czysta kosmetyka.

---

## 5. Bramka 18+ i prawo — minimum do zrobienia

**Nie mam pewności prawnej. Wszystko poniżej to lista do konsultacji z prawnikiem i księgową przed włączeniem płatności — nie opinia prawna.**

### Bramka wieku (deklaratywna, bez skanu dowodu)

Twarda weryfikacja tożsamości jest nieproporcjonalna do produktu za 20 zł i zabiłaby rejestracje; sprzedaż niehazardowej treści cyfrowej nie wymaga jej ustawowo (do potwierdzenia). Proponuję trzy warstwy:

| Gdzie | Co | Zapis |
|---|---|---|
| Rejestracja | rok urodzenia + checkbox „Mam ukończone 18 lat” + osobny checkbox „Akceptuję Regulamin i Politykę prywatności” | data potwierdzenia, wersja regulaminu |
| Istniejące konta | jednorazowy ekran z tymi samymi checkboxami po wdrożeniu, 7 dni na potwierdzenie (mechanizm jak wymuszona zmiana hasła admina) | j.w. |
| Zakup | drugie oświadczenie 18+ i zgoda konsumencka na ekranie płatności; e-mail obowiązkowy i zweryfikowany linkiem; bez tego przycisk „Kup” nieaktywny | w zamówieniu: e-mail, czas zgody, IP, wersja regulaminu |

**Decyzja dla Ciebie:** czy cała gra ma być 18+ (zgodnie z Twoim celem), czy 18+ tylko dla sklepu i zakupów, a reszta gry dostępna szerzej (mniejsza strata rejestracji w czasie, gdy wzrost jest najważniejszy). Oba warianty są wykonalne tym samym kodem; prawnie żaden nie jest wymagany, to decyzja produktowa. Dodatkowo: oznaczenie „18+ · symulacja · wirtualne pieniądze” na stronie głównej i w opisie aplikacji PWA; procedura zwrotu na wniosek rodzica (umowa z osobą 13–18 lat bez zgody rodzica może być nieważna — art. 20 KC, do konsultacji).

### Dokumenty i ekrany minimum

1. **Regulamin gry i sklepu** (jeden dokument): sprzedawca, przedmiot (Pro = usługa cyfrowa na czas), ceny brutto, czas trwania, reklamacje (odpowiedź w 14 dni), odstąpienie, zakaz multikont i handlu kontami, Tokeny jako licencja bez wartości poza grą (niezbywalne, nietransferowalne, niewymienialne), prawo do zmiany cennika z zachowaniem opłaconych okresów, zasady 18+.
2. **Polityka prywatności (RODO)**: login, e-mail, IP, dane płatności przekazywane do PayU jako odrębnego administratora, push, dziennik logów; podstawy prawne, okresy retencji (zamówienia i księga tokenów — 5 lat do celów podatkowych), prawa użytkownika, usunięcie konta na wniosek (dziś brak samousunięcia — dodać w Koncie).
3. **Ekran zakupu**: informacje przedumowne (art. 12 ustawy o prawach konsumenta), przycisk **„Kupuję i płacę”** (art. 17 ust. 3), checkbox zgody na **natychmiastowe rozpoczęcie usługi** — który przepis jest właściwy (art. 38 pkt 13 „utrata prawa odstąpienia” dla treści cyfrowej czy art. 38 pkt 1 + art. 35 „zapłata proporcjonalna” dla usługi w czasie) **musi rozstrzygnąć prawnik**; bez poprawnej zgody konsument może odstąpić w 14 dni po wykorzystaniu usługi.
4. **Potwierdzenie zawarcia umowy e-mailem** (trwały nośnik): treść, cena, okres, dane sprzedawcy, link do regulaminu. Stąd e-mail obowiązkowy do zakupu i potrzeba niezawodnej poczty (SPF/DKIM dla makleria.pl — dziś e-maile idą przez `mail()` hostingu i mogą lądować w spamie).
5. **Stopka na każdej stronie**: nazwa firmy, NIP, adres, kontakt, linki do regulaminu i polityki, informacja o ODR/pozasądowym rozwiązywaniu sporów.
6. **Disclaimer**: gra jest symulacją edukacyjno-rozrywkową na wirtualne pieniądze; „DM Makleria”, „rekomendacje” i „Raport DM” są fikcyjne i nie stanowią porady inwestycyjnej. **Sprawdzić z prawnikiem nazewnictwo „dom maklerski / rekomendacja”** pod kątem ustawy o obrocie instrumentami finansowymi.

### VAT, faktury, kasa

- Usługa elektroniczna dla konsumentów w Polsce: 23% VAT w cenie, ceny w sklepie brutto. Jeśli sprzedawca korzysta ze zwolnienia podmiotowego (do 200 tys. zł), inaczej — **księgowa**.
- Sprzedaż ograniczyć regulaminem do konsumentów w Polsce (unika procedury OSS dla innych krajów UE).
- Kasa fiskalna: sprzedaż na odległość opłacona przez operatora z jednoznaczną ewidencją zwykle korzysta ze zwolnienia — **do potwierdzenia z księgową**; faktura na żądanie konsumenta (dane z konta, do 3 miesięcy).
- PayU wymaga zarejestrowanej działalności (JDG lub spółka) i umowy.

### Czego unikać — ustawa o grach hazardowych

Hazard to w uproszczeniu: pieniądze × losowość × wygrana o wartości majątkowej. Gra nie pobiera stawki w prawdziwych pieniądzach, a o wyniku decyduje głównie umiejętność — to przemawia za brakiem hazardu. Ryzyko leży w łączeniu losowości świata (krachy, plotki) z nagrodami mającymi cenę w złotówkach — dlatego **Tokenów nie sprzedajemy**. Nigdy nie wprowadzać: turniejów z wpisowym w Tokenach lub złotówkach, skrzynek, losowych pakietów, kół fortuny, losowych bonusów za zakup, wymiany Tokenów między graczami ani na nagrody rzeczowe, auto-obciążania karty. Buy-in wyzwań zostaje w wirtualnych PLN. Czy nagrody tokenowe w lidze mogą zostać po odcięciu sprzedaży Tokenów — patrz warianty A/B w sekcji 3, **do potwierdzenia przez prawnika**.

---

## 6. Jak to uruchomić krok po kroku

### Zanim pojawi się PayU (można robić od razu)

1. Wdrożenia 0–3 z sekcji 4 (porządki, fundament prawny po otrzymaniu tekstów, jedno Pro, polecenia).
2. Pro za Tokeny i kody promocyjne działają od razu — bez żadnej płatności. Kod „BETA30” dla pierwszych graczy = miesiąc Pro; mierzymy, czy używają statystyk i alertów.
3. W sklepie przycisk „Chcę kupić za 19,99 zł” zliczany w GM — to Twój pierwszy pomiar chęci płacenia.
4. Wdrożenia 4–6 (statystyki, alerty, trafność rekomendacji) — to one tworzą wartość, za którą ktokolwiek zapłaci.

### Włączenie PayU (dopiero po opinii prawnika i przy ~200 aktywnych/mies.)

| Krok | Co zrobić | Kto |
|---|---|---|
| 1 | Opinia prawnika + teksty regulaminu i polityki; decyzja księgowej o VAT i kasie | Ty |
| 2 | Działalność gospodarcza, umowa z PayU, weryfikacja konta; w panelu PayU adresy powrotu i powiadomień dla docelowej domeny | Ty |
| 3 | Sekrety w GitHub: `PAYU_POS_ID`, `PAYU_MD5`, `PAYU_CLIENT_ID`, `PAYU_CLIENT_SECRET`, `PAYU_SANDBOX=1`, `APP_URL` (deploy sam buduje z nich konfigurację) | Ty + ja |
| 4 | **Test w sandboxie** (obowiązkowy, dziś nie istnieje): zamówienie → webhook z poprawnym podpisem → Pro aktywne; zła kwota → brak realizacji; powtórzony webhook → bez podwójnego Pro; anulowanie; zwrot | ja |
| 5 | Zmiana domeny na makleria.pl **razem** z sekretem `APP_URL` i ustawieniami w PayU (inaczej webhooki nie dojdą i gracze zapłacą bez Pro); SPF/DKIM dla poczty | Ty + ja |
| 6 | `PAYU_SANDBOX=0`, pierwszy zakup testowy za 19,99 zł własną kartą, zwrot przez GM — sprawdzenie całej pętli | Ty + ja |
| 7 | Health check po wdrożeniu (jak zawsze) | ja |

### Co mierzyć (panel GM, po poprawie metryk)

| Wskaźnik | Cel orientacyjny | Po co |
|---|---|---|
| Konwersja trial → Pro (za PLN lub Tokeny) w 14 dni po trialu | ≥ 10% | czy Pro ma wartość |
| Odnowienia w 7 dni po wygaśnięciu | ≥ 40–50% | czy przypomnienie działa; miernik „warte pieniędzy, nie ciekawostka” |
| Płacący / aktywni w 30 dni (tylko z realnych zamówień) | 2–5% po 3–6 mies. od PayU | zdrowy benchmark gier darmowych |
| Retencja D30: Pro vs darmowi | Pro wyraźnie wyższa | jeśli nie — funkcje Pro nie trafiają w potrzebę |
| Użycie Pro: % z wejściem na Statystyki i ≥ 1 alertem w tygodniu | ≥ 50–60% | nieużywane = nieodnowione |
| Alerty: % wejść do gry w 10 min po push | rosnący | dowód, że alert napędza retencję |
| Bilans Tokenów: wydane / przyznane miesięcznie; mediana salda | ≥ 0,6; saldo nie rośnie bez końca | czy darmowa waluta ma ujścia |
| Reklamacje i zwroty na 100 zamówień | < 2%, odpowiedź < 14 dni | obowiązek ustawowy |
| Lejek: kłódka → sklep → „Kup” → opłacone | — | gdzie gubimy klienta |
| Rejestracje tygodniowo i udział z linku polecającego/karty | rosnący | ważniejsze niż przychód, dopóki aktywnych < 200 |

---

## 7. Czego NIE robić (odrzucone pomysły)

| Pomysł | Dlaczego nie |
|---|---|
| Pełna głębokość arkusza zleceń (L2) i lista transakcji sesji tylko w Pro | Gra ma realny arkusz i fixing — głębokość to informacja handlowa, nie wygoda. Gracz znający rynek nazwie to pay-to-win. Arkusz identyczny dla wszystkich. |
| Pokazywanie „rekomendacji dzień wcześniej” jako głównej zalety Pro | Nie rusza kursów, ale brzmi jak sprzedaż informacji. Zastąpić trafnością historyczną rekomendacji; „dzień wcześniej” zostaje jako drobny dodatek z dymkiem „boty nie reagują”. |
| Kłódka na kolumnie „Sygnał AT” (np. widoczny dla 3 spółek) i „podgląd 2 wierszy” Raportu DM | Miękki paywall na danych darmowych spółka po spółce — drażni znających rynek. Kolumna za darmo; Pro sprzedaje filtry, zapis i syntezę. |
| Dalsza sprzedaż Tokenów za złotówki obok Pro za Tokeny i Tokenów jako nagrody z lig | Dopóki wszystkie trzy istnieją naraz, nagroda ligowa ma wymierną cenę — najpoważniejsze ryzyko hazardowe w grze. |
| Nowe mechaniki zleceń (OCO, ważność zlecenia, koszyk, szablony) w ramach prac nad premium | Dotykają serca silnika (rezerwacje, wzorzec claim-first), wymagają testów wyścigów na MySQL. Mechanika zleceń nigdy nie może być płatna, więc to nawet nie jest funkcja Pro — osobne zadanie, kiedy indziej. |
| Backtest strategii, klucz API, eksport .ics | Tygodnie pracy, obciążenie współdzielonego hostingu, nisza w niszy. Po ~500 aktywnych. |
| Kluby i automatyczny sezon **teraz** | Świetne na wzrost, ale przy kilku graczach to puste tabele i jeden lider. Po ~20 aktywnych. |
| Auto-odnawianie z karty (PayU recurring) | Osobna umowa, dodatkowe obowiązki informacyjne, ryzyko zarzutu „dark patterns”. Przedpłata + przypomnienie wystarczy. |
| Auto-odnowienie za Tokeny włączone domyślnie | Wydaje Tokeny bez kliknięcia — źródło reklamacji. Tylko jako świadomy opt-in, a na start wystarczy przycisk „Przedłuż”. |
| Skan dowodu, PESEL, zewnętrzne KYC | Nieproporcjonalne do produktu za 20 zł, mnoży obowiązki RODO, zabija rejestracje. |
| Blokada istniejących kont do odczytu do czasu akceptacji regulaminu | Zbyt ostra; 7-dniowy okres przejściowy. |
| Przepisywanie istniejących wierszy pakietów w bazie na „pro” | Zbędne ryzyko na danych graczy; alias w kodzie daje ten sam efekt. |
| Trzeci i czwarty wariant cenowy (7 dni za 7,99 zł, 365 dni) od razu | Więcej kodu, zwrotów i pustych statystyk. Zacząć od 30 i 90 dni. |
| Skokowe cięcie misji dnia z ≈26 do 7 tokenów/tydz. | Osłabia pętlę dzienną, która dziś napędza powroty. Stroić stopniowo, przez ustawienia w GM, razem z nowymi ujściami kosmetycznymi. |
| Turnieje z wpisowym w Tokenach/PLN, skrzynki, losowe pakiety, transfer Tokenów | Najbliżej definicji zakładu/gry losowej. Bezwarunkowo nie. |
| Włączenie PayU na produkcji przed opinią prawną i regulaminem | Każde „do konsultacji” wyżej to realne ryzyko kary lub zwrotów. |

---

## 8. Załącznik techniczny

### 8.1. Zadania, pliki, schemat

Każde zadanie = osobny branch `claude/<opis>`, podbicie `Schema::VERSION` o 1 tam, gdzie zmienia się baza (v44, v45, …), migracja idempotentna, QA na SQLite i MySQL, merge do `main` z `.healthcheck`.

| # | Zadanie | Główne pliki | Zmiany w bazie | Dni |
|---|---|---|---|---|
| 0 | Porządki + metryka + kody promo | `src/Tokens.php` (buyPass w transakcji, spend przez challengeOwner, redeemPromo), `src/Cosmetics.php`, `src/Seasons.php` (buy w transakcji), `src/Payments.php` (limit pending, buyer e-mail, status refunded, URL bez „?”), `public/platnosc_notify.php`, `public/gm.php` (konwersja z payment_orders, sekcja kodów, zwrot), `public/sklep.php` (pole „Mam kod”, licznik „Chcę kupić”), `src/Schema.php`, `src/Migrator.php`, `src/Qa.php` | tabele `promo_codes`, `promo_redemptions`; `payment_orders.email`, `refunded_at`, status `refunded`; komentarz przy `premium_passes` | 2–3 |
| 1 | Fundament prawny + 18+ | `public/register.php`, `public/_boot.php` (strażnik potwierdzenia, stopka, biała lista nowych stron), `public/regulamin.php` i `public/prywatnosc.php` (nowe, publiczne), `public/index.php`, `public/manifest.json`, `public/konto.php` (weryfikacja e-maila, usunięcie konta), `public/sklep.php` (ekran zgód, „Kupuję i płacę”), `src/Payments.php`, `src/Mailer.php` (potwierdzenie umowy), `public/health.php`, `.github/workflows/health.yml`, `CLAUDE.md` (lista stron publicznych) | `users.birth_year`, `adult_confirmed_at`, `terms_version`, `terms_accepted_at`, `email_verified_at`, `email_verify_token`; `payment_orders.consent_at`, `consent_ip`, `terms_version` | 2–3 (bez tekstów prawnika) |
| 2 | Jedno Pro + przypomnienia + trial 7 dni | `src/Tokens.php` (klucz `pro`, aliasy `analityk`/`raport` w hasPass/passUntil, expiryReminders, grantTrials bez blokady po starym pakiecie), `src/Payments.php` (produkty `pro_30`/`pro_90`, complete() nadaje dni Pro), `src/Engine.php` (rollSession: przypomnienia; signalAlerts: kind IN), `src/Recommendations.php`, `public/sklep.php`, `public/platnosc.php`, `public/pulpit.php` (baner po wygaśnięciu), `public/_boot.php` (znacznik Pro w pasku), `public/market.php`, `public/stock.php`, `public/rekomendacje.php`, `public/samouczek.php`, `public/pomoc.php`, `src/Glossary.php` | `premium_passes.until_at` (data kalendarzowa; alternatywnie zostają sesje), `payment_orders.product` | 3–4 |
| 3 | Polecenia 2.0 | `src/Tokens.php` (grantReferrals: extendPass 7 dni), `public/konto.php`, `public/register.php`, `src/Weekly.php`, `public/karta.php` | — | 1 |
| 4 | Statystyki inwestora | `src/Stats.php` (nowy: parowanie FIFO, drawdown, alfa), `public/statystyki.php` (nowy), `src/Engine.php` (rollSession: odświeżenie cache poza transakcją ticka), `public/portfolio.php`, `public/pulpit.php`, `public/menu.php`, `public/samouczek.php`, `public/pomoc.php`, `src/Glossary.php` (obsunięcie, hit rate, alfa, zmienność) | tabela `stats_cache` (user_id, session, json) | 5–7 |
| 5 | Alerty własne push | `src/Schema.php`, `src/Migrator.php`, `src/Engine.php` (checkAlerts po matchBook obok checkStops; notify przy realizacji SL/TP), `src/Push.php` (bez zmian logiki), `public/stock.php` (formularz), `public/pulpit.php`, nowy `public/api_alerts.php`, `src/Glossary.php`, `public/samouczek.php` | tabela `price_alerts` (user_id, stock_id, kind, threshold, fired_session, created_at) | 3–4 |
| 6 | Trafność rekomendacji · AT darmowe · Raport DM bez DNA | `src/Recommendations.php` (accuracy), `public/rekomendacje.php`, `public/market.php`, `public/stock.php` (zakładka Raport DM: opisy zamiast liczb, ryzyko z `candles_daily`), `src/Tokens.php` (opis pakietu) | opcjonalnie `ta_history` (sygnał na koniec sesji, retencja 60) | 1–2 |
| 7 | Skaner + kalendarz | `public/skaner.php` (nowy), `public/kalendarz.php` (nowy), `src/Technical.php` (metryki z `candles_daily`: zmienność, dystans do max), `src/Engine.php` (pomocnik: zdarzenia na N sesji), `src/Ipo.php`, `public/pulpit.php`, `public/menu.php`, `src/Glossary.php` | tabela `screener_presets` albo wpisy w `user_items` | 3–4 |
| 8 | Eksport CSV + raport miesięczny | `public/eksport.php` (nowy), `public/historia.php`, `src/Weekly.php` (wariant month), `public/konto.php` | — | 2 |
| 9 | Status Pro + sezon kosmetyczny | `src/Cosmetics.php` (typy card_theme, badge, przedmioty sezonowe), `public/ranking.php`, `public/gracz.php`, `public/api_chat.php`, `public/karta.php`, `src/Seasons.php` (MILESTONES jako przedmioty, autoCreate, punkty za aktywność), `src/Daily.php`, `public/sezon.php`, `src/Engine.php` (rollSession: Seasons::ensureMonthly) | `users.card_theme`; `user_items.season_id` | 3–4 |
| P | PayU sandbox + produkcja | `src/Qa.php` (test webhooka przez `Payments::$testCfg`), sekrety GitHub, `.github/workflows/deploy.yml` | — | 1–2 |

**Razem: ok. 27–38 dni roboczych** (szacunek; zadania 0–3 to ok. 8–11 dni, zadania 4–6 ok. 9–13, reszta po wzroście bazy). Zadania 7–9 można odłożyć bez szkody dla modelu.

### 8.2. Nowe asercje QA (do `src/Qa.php`, obok dzisiejszych 157)

| Obszar | Asercja |
|---|---|
| Pro za Tokeny | zakup Pro: saldo spada o cenę, wpis w `token_ledger`, `hasPass('pro')` = true, aliasy `hasPass('analityk')` i `hasPass('raport')` = true; przedłużenie dokleja się do końca |
| Transakcyjność | symulowany błąd po pobraniu Tokenów → saldo nietknięte, brak pakietu (SQLite i MySQL) |
| Kody promocyjne | kod działa raz na konto, drugi raz odrzucony; wygasły kod odrzucony; limit użyć |
| Przypomnienia | gracz z Pro kończącym się w następnej sesji dostaje dokładnie jedno powiadomienie; po wygaśnięciu jeden baner |
| Trial | gracz spełniający warunki dostaje 7 dni Pro raz; gracz ze starym pakietem też dostaje |
| Polecenia | aktywny polecony: polecony +7 dni Pro, polecający +7 dni i +25 tokenów; limit 10 |
| Alerty | alert cenowy powstaje, strzela po przekroczeniu progu, nie strzela drugi raz w tej samej sesji; darmowy limit egzekwowany |
| Statystyki | strona odpowiada, liczby zgodne z ręcznym wyliczeniem na 3 transakcjach testowych; darmowy widzi kłódkę |
| Eksport | CSV ma BOM, nagłówki i tyle wierszy, ile transakcji gracza |
| Rejestracja i prawo | rejestracja bez checkboxa 18+/regulaminu odrzucona; `regulamin.php`, `prywatnosc.php` odpowiadają 200 bez logowania; stopka zawiera dane sprzedawcy; zakup bez zweryfikowanego e-maila zablokowany |
| PayU (sandbox, przez `Payments::$testCfg`) | poprawny podpis + kwota → `completed` i Pro; zła kwota → brak realizacji i log; powtórzony webhook → idempotentny; CANCELED → `cancelled`; REFUND → `refunded` i odebranie Pro |
| Ekonomia | `inv.money` bez zmian — Pro, Tokeny i kody nie tworzą ani nie niszczą gotówki w świecie |
| Health check | nowe strony publiczne osiągalne; wersja schematu zgodna z kodem |

### 8.3. Zasady, które obowiązują przy każdym z tych zadań

- Żelazne zasady z `CLAUDE.md` bez wyjątków: zamknięta ekonomia (Pro nie dotyka `worldCash`), rezerwacje co do grosza, claim-first, schemat w 3 krokach, test na MySQL dla wszystkiego, co dotyka transakcji.
- Nowe obliczenia (statystyki, skaner, trafność) żyją w osobnych klasach i **poza transakcją ticka**, z pamięcią podręczną raz na sesję — `Engine.php` ma już ~2900 linii i hosting jest współdzielony.
- Każda nowa funkcja = krok w samouczku, sekcja w pomocy i wpis w słowniczku z dymkiem (zasada 8).
- Wysyłka e-maili i push zostaje poza transakcją ticka (`Push::flush` po tickach).

---

*Podsumowanie w jednym zdaniu: gra jest zdrowa; sklep trzeba uporządkować do jednego uczciwego produktu z fundamentem prawnym, włączyć płatności dopiero po opinii prawnika i przy realnej bazie graczy, a do tego czasu używać premium jako narzędzia retencji i pomiaru — nie jako źródła przychodu.*
