---
tytul_seo: "Rodzaje zleceń giełdowych: limit, PKC, stop loss, take profit"
meta_description: "Limit, PKC, stop loss, take profit, stop kroczący i stop-buy: jak działają rodzaje zleceń giełdowych, kiedy ich używać i jakie błędy popełniają początkujący."
slug: rodzaje-zlecen-gieldowych-limit-pkc-stop-loss
slowa_kluczowe: [rodzaje zleceń giełdowych, zlecenie z limitem ceny, zlecenie PKC, stop loss co to jest, take profit, stop loss kroczący, zlecenie stop buy, jak składać zlecenia na giełdzie]
intencja: informacyjna / edukacyjna
status: szkic – nie publikować
---

# Rodzaje zleceń giełdowych: limit, PKC, stop loss, take profit i stop-buy wyjaśnione na przykładach

Formularz zlecenia to pierwsze miejsce, w którym początkujący inwestor czuje się zagubiony. Limit czy PKC? Co to jest stop loss i czym różni się od take profit? Po co komu stop loss kroczący i co właściwie znaczy „kup, gdy przebije”? Ten artykuł wyjaśnia wszystkie podstawowe rodzaje zleceń giełdowych na konkretnych liczbach. Przykłady pochodzą z symulatora giełdy, ale mechanika jest taka sama jak na prawdziwym rynku.

## W skrócie

- **Zlecenie z limitem ceny** – kupujesz najwyżej po cenie X (lub sprzedajesz najniżej po X). Czeka w arkuszu, aż ktoś je zrealizuje. Kontrolujesz cenę, nie kontrolujesz czasu.
- **Zlecenie PKC (po każdej cenie)** – realizowane natychmiast po najlepszych dostępnych ofertach. Kontrolujesz czas, nie kontrolujesz ceny.
- **Stop loss** – automatyczna sprzedaż, gdy kurs spadnie do progu. Ogranicza stratę.
- **Take profit** – automatyczna sprzedaż, gdy kurs wzrośnie do progu. Realizuje zysk.
- **Stop loss kroczący** – próg sprzedaży sam rośnie wraz z kursem, chroniąc zysk.
- **Stop-buy** – automatyczne kupno, gdy kurs przebije poziom w górę. Wejście po potwierdzeniu wybicia.

## Dlaczego rodzaj zlecenia ma znaczenie?

Kurs, który widzisz w tabeli notowań, to cena ostatniej transakcji. Nie jest to cena, po której możesz kupić w tej chwili. W arkuszu zleceń są oferty kupna (bid) i oferty sprzedaży (ask), a między nimi jest spread. Kupując „od ręki”, płacisz cenę ask; sprzedając „od ręki”, dostajesz cenę bid. Rodzaj zlecenia decyduje o tym, czy godzisz się na tę cenę, czy wolisz poczekać na lepszą.

Przykład. Spółka Kowalski Motors ma ostatni kurs 100,00 zł. W arkuszu najlepsza oferta kupna to 99,50 zł, najlepsza oferta sprzedaży 100,60 zł. Jeśli złożysz zlecenie PKC na 100 akcji, zapłacisz 100,60 zł za sztukę (albo więcej, jeśli po 100,60 sprzedawanych jest tylko 40 akcji, a reszta po 101,00). Jeśli złożysz zlecenie z limitem 100,00 zł, twoje zlecenie stanie w arkuszu i zostanie zrealizowane dopiero wtedy, gdy ktoś zechce sprzedać po 100,00 lub taniej.

## Zlecenie z limitem ceny

To podstawowe zlecenie każdego rozsądnego inwestora. Podajesz maksymalną cenę kupna (albo minimalną cenę sprzedaży) i liczbę akcji. Gra (albo broker) rezerwuje gotówkę na kupno, czyli liczbę akcji razy limit, do czasu realizacji lub anulowania zlecenia. Przy sprzedaży rezerwowane są akcje.

**Zalety:** pełna kontrola ceny, brak ryzyka „poślizgu” w płytkim arkuszu, możliwość złożenia zlecenia wieczorem i pozostawienia go na rano.

**Wady:** brak gwarancji realizacji. Jeśli kurs ucieknie, zostaniesz z niezrealizowanym zleceniem i zarezerwowaną gotówką. Zlecenie może też zostać zrealizowane częściowo, na przykład 60 ze 100 akcji.

**Kiedy używać:** zawsze, gdy nie musisz kupić natychmiast. W praktyce to znaczy prawie zawsze.

**Praktyczna wskazówka:** jeśli chcesz mieć niemal pewność realizacji, ale nie chcesz przepłacić w płytkim arkuszu, ustaw limit nieco powyżej najlepszej oferty sprzedaży. Zlecenie zrealizuje się natychmiast po cenach z arkusza, ale nie wyżej niż twój limit.

## Zlecenie PKC – po każdej cenie

Zlecenie rynkowe. Nie podajesz ceny, tylko liczbę akcji; realizacja następuje natychmiast po kolejnych najlepszych ofertach z arkusza, aż do wyczerpania twojego zlecenia.

**Zalety:** pewność realizacji (o ile w arkuszu w ogóle są oferty), szybkość.

**Wady:** brak kontroli ceny. W płytkim arkuszu duże zlecenie PKC potrafi „zjeść” kilka poziomów cen i kosztować znacznie więcej, niż sugerował kurs. To najczęstsza przyczyna zaskoczenia początkujących: „kurs był 100, a zapłaciłem średnio 103”.

**Kiedy używać:** gdy szybkość jest ważniejsza niż cena, na przykład przy wychodzeniu z pozycji w czasie gwałtownego spadku, gdy stop loss już nie pomoże. Przy małych spółkach i małych obrotach lepiej unikać.

**Uwaga na fazę otwarcia:** na prawdziwej giełdzie i w Maklerii pierwsze minuty sesji to fixing, w którym zlecenia się zbierają, a kurs otwarcia wyznaczany jest jednorazowo. W tej fazie zlecenia PKC są odrzucane (albo, na niektórych rynkach, realizowane po nieprzewidywalnej cenie otwarcia). Rano składaj zlecenia z limitem.

## Stop loss – zlecenie obronne

Stop loss to zlecenie sprzedaży aktywowane dopiero wtedy, gdy kurs spadnie do ustalonego progu. Kupiłeś po 100 zł, ustawiasz stop loss na 92 zł. Dopóki kurs jest powyżej 92, nic się nie dzieje. Gdy spadnie do 92 lub niżej, zlecenie sprzedaży zostaje wysłane na rynek i akcje są sprzedawane.

**Po co:** żeby ograniczyć stratę z góry i zdjąć z siebie decyzję w chwili paniki. Inwestor, który nie ma stop lossa, w czasie spadku myśli „jeszcze odbije” i często zamienia stratę 8% w stratę 40%.

**Jak dobrać poziom:** zbyt ciasny stop loss (2–3%) będzie wyrzucał cię z pozycji przy zwykłych wahaniach. Zbyt szeroki (30%) nie chroni. Praktyczna zasada: poziom zależy od zmienności spółki. Stabilna, duża spółka: 6–10%. Mała, zmienna: 12–20%. Dobrze też umieszczać stop poniżej ostatniego ważnego dołka na wykresie, a nie na „okrągłej” liczbie.

**Pułapka:** stop loss nie gwarantuje ceny. Po aktywacji zlecenie jest realizowane po cenach z arkusza. Przy gwałtownym spadku (luka cenowa po złych wiadomościach) możesz sprzedać sporo niżej niż próg. W symulatorze zobaczysz to na własne oczy, gdy newsroom opublikuje fatalny raport przed otwarciem.

## Take profit – realizacja zysku

Take profit działa lustrzanie: zlecenie sprzedaży aktywowane, gdy kurs wzrośnie do progu. Kupiłeś po 100, ustawiasz take profit na 120. Gdy kurs dotknie 120, akcje są sprzedawane.

**Po co:** żeby zamknąć pozycję według planu, a nie według chciwości. Wielu inwestorów patrzy na zysk 20%, myśli „jeszcze trochę” i kończy z zyskiem 3% albo stratą.

**Kiedy nie używać:** w inwestowaniu długoterminowym w spółki, które chcesz trzymać latami. Take profit ma sens w handlu z określonym celem, na przykład gdy kupujesz pod raport kwartalny albo pod wybicie techniczne.

**Sztuczka:** zamiast jednego take profit na całą pozycję ustaw dwa: połowę pozycji sprzedaj przy 115, resztę zabezpiecz stop lossem kroczącym. Zrealizujesz część zysku, a resztę zostawisz na wypadek dalszego wzrostu.

## Stop loss kroczący (trailing stop)

Zwykły stop loss stoi w miejscu. Kroczący porusza się w górę razem z kursem, ale nigdy w dół. Ustawiasz go jako odległość od kursu, na przykład 10%. Kupiłeś po 100, próg wynosi 90. Kurs rośnie do 130 – próg automatycznie przesuwa się do 117. Kurs spada do 117 – sprzedajesz z zyskiem 17%, choć nigdy nie musiałeś podejmować decyzji o sprzedaży.

**Zalety:** chroni zysk bez konieczności zgadywania szczytu; łączy zalety stop lossa i take profit.

**Wady:** przy zmiennych spółkach zbyt ciasny stop kroczący zamyka pozycję przy pierwszej większej korekcie, tuż przed dalszym wzrostem.

**Kiedy używać:** gdy pozycja jest już na zysku i chcesz „jechać z trendem”, ale nie chcesz oddać zarobku.

## Stop-buy – „kup, gdy przebije”

Najrzadziej rozumiane zlecenie, a bardzo przydatne. Stop-buy to zlecenie kupna aktywowane, gdy kurs **wzrośnie** do ustalonego progu. Brzmi nielogicznie („dlaczego mam kupować drożej?”), ale ma sens: chcesz wejść w spółkę dopiero wtedy, gdy potwierdzi siłę, na przykład przebije opór na wykresie.

Przykład. Spółka od tygodni odbija się od poziomu 50 zł. Uważasz, że jeśli przebije 50, pójdzie znacznie wyżej, ale jeśli nie przebije, nie chcesz jej mieć. Ustawiasz stop-buy z progiem 50,50 zł i limitem 52 zł. Gdy kurs dojdzie do 50,50, zlecenie kupna z limitem 52 trafia na rynek. Jeśli kurs nigdy nie przebije 50, nic nie kupujesz i nie tracisz kapitału na spółkę bez siły.

W Maklerii stop-buy rezerwuje gotówkę w wysokości liczba akcji razy limit już w chwili złożenia, żeby po aktywacji na pewno było za co kupić. Próg i limit widać w liście zleceń, a przy polu formularza jest dymek z wyjaśnieniem.

**Pułapka:** stop-buy bez limitu (lub z limitem bardzo wysokim) w płytkim arkuszu może kupić drogo. Zawsze ustawiaj rozsądny limit powyżej progu.

## Jak działa rezerwacja gotówki i akcji

To temat, który myli początkujących. Gdy składasz zlecenie kupna z limitem, gotówka nie znika, ale zostaje zarezerwowana: nie możesz wydać jej na inne zlecenie, dopóki to nie zostanie zrealizowane lub anulowane. Podobnie zlecenie sprzedaży i zlecenia obronne rezerwują akcje. Dlatego w portfelu widzisz „gotówkę wolną” i „gotówkę zamrożoną” oraz „akcje wolne” i „akcje zarezerwowane”.

Konsekwencja praktyczna: jeśli masz stop loss na 100 akcji, nie możesz jednocześnie złożyć zlecenia sprzedaży tych samych 100 akcji z limitem. Musisz najpierw anulować stop loss. W symulatorze łatwo to zobaczyć, bo gra pokazuje rezerwacje przy każdej pozycji.

## Najczęstsze błędy przy składaniu zleceń

1. **PKC w płytkim arkuszu.** Sprawdź arkusz, zanim klikniesz. Jeśli oferty sprzedaży są rozrzucone (40 akcji po 100,60, potem 200 po 103), zlecenie PKC na 200 akcji zapłaci średnio ponad 102.
2. **Stop loss na okrągłej liczbie.** Wielu graczy ustawia stopy na 90, 100, 50. Kurs często „dotyka” tych poziomów i odbija, wyrzucając wszystkich naraz. Ustaw stop odrobinę poniżej okrągłej liczby.
3. **Brak limitu przy stop-buy.** Aktywacja w czasie gwałtownego wybicia bez limitu kończy się zakupem na szczycie.
4. **Zlecenia PKC w fazie otwarcia.** Są odrzucane lub realizowane po nieprzewidywalnej cenie.
5. **Zapominanie o rezerwacjach.** „Dlaczego nie mogę kupić, skoro mam gotówkę?” Bo jest zamrożona pod inne zlecenie.
6. **Przesuwanie stop lossa w dół.** Gdy kurs zbliża się do progu, kusi, żeby „dać mu jeszcze trochę miejsca”. To ten sam błąd co brak stop lossa, tylko rozłożony w czasie.

## Ściąga: jakie zlecenie w jakiej sytuacji

| Sytuacja | Zlecenie |
|---|---|
| Chcę kupić, ale nie przepłacić | limit nieco powyżej najlepszej oferty sprzedaży |
| Chcę kupić natychmiast, duża spółka, głęboki arkusz | PKC (poza fazą otwarcia) |
| Kupuję i chcę ograniczyć stratę | stop loss 6–15% poniżej ceny, zależnie od zmienności |
| Mam plan zysku | take profit na poziomie z planu |
| Pozycja jest na zysku, chcę jechać z trendem | stop loss kroczący |
| Chcę wejść dopiero po wybiciu | stop-buy z progiem nad oporem i rozsądnym limitem |
| Muszę wyjść natychmiast w czasie spadku | PKC (świadomie akceptując cenę) |

## Najczęściej zadawane pytania

**Czy stop loss gwarantuje sprzedaż po ustalonej cenie?**
Nie. Gwarantuje aktywację zlecenia sprzedaży, ale realizacja następuje po cenach z arkusza. Przy luce cenowej sprzedasz niżej.

**Czym różni się stop loss od take profit?**
Stop loss sprzedaje przy spadku do progu (ogranicza stratę), take profit sprzedaje przy wzroście do progu (realizuje zysk). Można ustawić oba naraz.

**Kiedy zlecenie z limitem nie zostanie zrealizowane?**
Gdy nikt nie chce zawrzeć transakcji po twojej cenie. Zlecenie czeka w arkuszu do realizacji, anulowania albo wygaśnięcia.

**Co to jest stop-buy?**
Zlecenie kupna aktywowane, gdy kurs wzrośnie do progu. Służy do wejścia w spółkę po potwierdzeniu siły, na przykład po przebiciu oporu.

**Czy mogę mieć stop loss i zlecenie sprzedaży na te same akcje?**
Nie, bo obie rezerwacje dotyczyłyby tych samych akcji. Najpierw anuluj jedno zlecenie.

## Podsumowanie

Sześć rodzajów zleceń, jedna zasada: decyduj z wyprzedzeniem. Zlecenie z limitem chroni przed przepłaceniem, stop loss przed dużą stratą, take profit przed chciwością, stop kroczący przed oddaniem zysku, a stop-buy przed kupowaniem spółek bez siły. Wszystkie te zlecenia możesz przećwiczyć w symulatorze giełdy, obserwując, co dzieje się z gotówką, akcjami i arkuszem. Kilka tygodni takiej praktyki uczy więcej niż kilka miesięcy czytania.

---
*Sugerowane linki wewnętrzne: skąd bierze się kurs akcji (artykuł 5), analiza techniczna (artykuł 6), psychologia inwestora (artykuł 8), krach na giełdzie (artykuł 10).*
*Sugerowany CTA: „Przetestuj wszystkie rodzaje zleceń bez ryzyka – w Maklerii przy każdym polu jest podpowiedź”.*
