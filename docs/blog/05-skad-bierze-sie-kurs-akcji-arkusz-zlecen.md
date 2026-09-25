---
tytul_seo: "Skąd bierze się kurs akcji? Arkusz zleceń, bid, ask, spread i wolumen"
meta_description: "Kurs akcji nie bierze się znikąd. Wyjaśniamy, jak działa arkusz zleceń, czym są bid, ask i spread, jak kojarzone są zlecenia, co to fixing i dlaczego duże zlecenie rusza ceną."
slug: skad-bierze-sie-kurs-akcji-arkusz-zlecen
slowa_kluczowe: [skąd bierze się kurs akcji, arkusz zleceń, bid ask spread, jak powstaje cena akcji, wolumen obrotu, fixing giełda, płynność akcji, kojarzenie zleceń]
intencja: informacyjna / edukacyjna
status: szkic – nie publikować
---

# Skąd bierze się kurs akcji? Arkusz zleceń, bid, ask, spread i wolumen wyjaśnione od podstaw

Większość ludzi wyobraża sobie kurs akcji jako liczbę, którą „ktoś ustala” – giełda, spółka, może jakiś algorytm. Tymczasem kurs nikt nie ustala. Powstaje z tysięcy zleceń kupna i sprzedaży, które spotykają się w arkuszu zleceń, i zmienia się za każdym razem, gdy ktoś zgodzi się na cenę drugiej strony. Zrozumienie tego mechanizmu to jedna z najważniejszych lekcji dla początkującego inwestora, bo tłumaczy, dlaczego cena z tabeli nie jest ceną, po której kupisz, dlaczego duże zlecenie „rusza” kursem i dlaczego rano rynek zachowuje się inaczej niż w środku dnia.

## W skrócie

- **Kurs akcji** to cena ostatniej zawartej transakcji. Nie jest ustalany przez nikogo, tylko wynika z kojarzenia zleceń.
- **Arkusz zleceń** to lista wszystkich oczekujących ofert kupna (bid) i sprzedaży (ask), uporządkowana według ceny.
- **Spread** to różnica między najlepszą ofertą sprzedaży a najlepszą ofertą kupna. To koszt handlu „od ręki”.
- **Wolumen** to liczba akcji, które zmieniły właściciela. Mówi, jak łatwo kupić i sprzedać bez ruszania kursem.
- **Fixing** (aukcja otwarcia) to jednorazowe wyznaczenie kursu na początku sesji: cena, przy której da się zrealizować największy wolumen.

## Arkusz zleceń: rynek w jednej tabeli

Wyobraź sobie tablicę z dwiema kolumnami. Po lewej stoją ci, którzy chcą kupić: każdy zapisał, ile akcji chce i po jakiej maksymalnej cenie. Po prawej stoją ci, którzy chcą sprzedać: ile akcji i po jakiej minimalnej cenie. Kupujący uporządkowani są od najwyższej ceny w dół, sprzedający od najniższej w górę. To jest arkusz zleceń.

Przykład dla spółki Bałtyk Logistyka:

| Kupno (bid) | | Sprzedaż (ask) | |
|---|---|---|---|
| liczba akcji | cena | cena | liczba akcji |
| 300 | 49,80 | 50,20 | 150 |
| 500 | 49,50 | 50,50 | 400 |
| 1 200 | 49,00 | 51,00 | 800 |

Najlepsza oferta kupna to 49,80 zł (ktoś chce kupić 300 akcji, ale nie drożej). Najlepsza oferta sprzedaży to 50,20 zł (ktoś chce sprzedać 150 akcji, ale nie taniej). Między nimi nie ma transakcji, bo nikt nie chce zapłacić tyle, ile żąda druga strona. Ta różnica, 0,40 zł, to spread.

## Jak dochodzi do transakcji

Transakcja następuje, gdy ktoś zaakceptuje cenę drugiej strony. Jeśli złożysz zlecenie kupna 100 akcji z limitem 50,20 zł, dopasujesz się do najlepszej oferty sprzedaży i transakcja zostanie zawarta po 50,20. Nowy kurs to 50,20. W arkuszu po stronie sprzedaży zostanie 50 akcji po 50,20.

Jeśli złożysz zlecenie kupna 100 akcji z limitem 50,00 zł, nikt nie chce sprzedać tak tanio, więc twoje zlecenie trafi na lewą stronę arkusza jako nowa najlepsza oferta kupna. Spread zmniejszy się do 0,20 zł. Czekasz, aż jakiś sprzedający zaakceptuje twoją cenę.

Zasada kojarzenia jest prosta: pierwszeństwo ma lepsza cena, a przy tej samej cenie zlecenie złożone wcześniej. Zlecenie, które „przychodzi” na rynek i zawiera transakcję, nazywane jest agresywnym; zlecenie, które czeka w arkuszu, pasywnym. Agresywny płaci spread, pasywny go zarabia, ale ryzykuje brak realizacji.

## Dlaczego duże zlecenie rusza kursem

Wróćmy do arkusza. Chcesz kupić 1 000 akcji Bałtyk Logistyka zleceniem po każdej cenie. Co się stanie?

1. Kupujesz 150 akcji po 50,20 zł.
2. Kupujesz 400 akcji po 50,50 zł.
3. Kupujesz 450 akcji po 51,00 zł (z 800 dostępnych).

Średnia cena zakupu to około 50,68 zł, a ostatni kurs to 51,00 zł, o 1,6% wyżej niż przed twoim zleceniem. Sam ruszyłeś kursem, a wszyscy, którzy patrzą na tabelę, widzą „wzrost”. Gdybyś za chwilę chciał sprzedać, najlepsza oferta kupna nadal wynosi 49,80 zł, więc natychmiastowa sprzedaż oznaczałaby stratę ponad 1,7%.

To zjawisko nazywa się wpływem na rynek (market impact) i jest tym większe, im płytszy arkusz. Dlatego przy małych spółkach doświadczeni inwestorzy dzielą duże zlecenia na części i używają zleceń z limitem. W symulatorze giełdy z prawdziwym arkuszem, takim jak Makleria, zobaczysz to zjawisko na własnym portfelu: w grze, w której każde zlecenie realizuje się „po kursie z tabeli”, nigdy się go nie nauczysz.

## Płynność i wolumen

Płynność to zdolność rynku do przyjęcia zlecenia bez dużej zmiany ceny. Spółka płynna ma głęboki arkusz: setki tysięcy akcji po obu stronach, spread rzędu ułamka procenta. Spółka niepłynna ma kilka ofert, szeroki spread i każde większe zlecenie przesuwa kurs.

Wolumen to liczba akcji, które zmieniły właściciela w danym okresie (na przykład w ciągu sesji). Obrót to wolumen razy cena, czyli wartość transakcji w złotych. Obie liczby mówią o zainteresowaniu spółką i o tym, jak łatwo będzie wyjść z pozycji. Prosta zasada: nie kupuj pozycji większej niż kilka procent dziennego obrotu, jeśli chcesz móc ją sprzedać bez ruszania kursem.

W Maklerii przy każdej spółce widać obrót dzienny i arkusz z kilkoma najlepszymi poziomami po obu stronach. Około stu botów rynkowych pełni funkcję animatorów i inwestorów o różnych strategiach, dzięki czemu arkusz nigdy nie jest pusty, ale głębokość różni się między spółkami tak jak na prawdziwej giełdzie.

## Skąd biorą się zlecenia po drugiej stronie

Kto stoi po drugiej stronie twojej transakcji? Na prawdziwej giełdzie: inni inwestorzy indywidualni, fundusze, animatorzy rynku (firmy zobowiązane do kwotowania), algorytmy. W symulatorze: inni gracze oraz boty o różnych strategiach. W Maklerii są boty fundamentalne (kupują spółki o dobrych raportach), techniczne (reagują na wskaźniki), spekulacyjne (grają pod newsy), animatorzy (wystawiają oferty po obu stronach, zarabiając na spreadzie) i inwestorzy długoterminowi. Każdy bot ma własne „DNA”: horyzont, skłonność do ryzyka, ulubione branże. To sprawia, że rynek reaguje na wydarzenia w sposób zróżnicowany i nieprzewidywalny, jak prawdziwy.

## Fixing: jak powstaje kurs otwarcia

Na wielu giełdach sesja nie zaczyna się od zwykłego handlu, tylko od aukcji. Przez pierwsze minuty zlecenia się zbierają, ale nie są kojarzone. Następnie system wyznacza jedną cenę, przy której da się zrealizować największą liczbę akcji, i wszystkie pasujące zlecenia realizuje po tej cenie. To fixing, czyli aukcja otwarcia.

Po co to? Przez noc napływają wiadomości: raporty, decyzje banków centralnych, wydarzenia na świecie. Gdyby handel zaczynał się natychmiast, pierwsze transakcje odbywałyby się po przypadkowych cenach, bo arkusz jest wtedy rzadki. Aukcja pozwala „zebrać” cały nocny popyt i podaż i wyznaczyć cenę, która odzwierciedla wszystkie informacje naraz.

W Maklerii sesja zaczyna się o 7:50 fazą otwarcia trwającą dziesięć minut, a o 8:00 dla każdej spółki wyznaczany jest kurs otwarcia według zasady maksymalnego wolumenu. W tej fazie zlecenia po każdej cenie są odrzucane, bo nie ma jeszcze ceny, po której mogłyby się zrealizować. Gracz uczy się w ten sposób, że rano składa się zlecenia z limitem i że cena otwarcia może odbiegać od wczorajszego zamknięcia, jeśli w nocy pojawiły się wiadomości.

## Luki cenowe i dlaczego stop loss nie zawsze chroni

Jeżeli spółka zamknęła się po 50 zł, a w nocy opublikowała fatalny raport, to na aukcji otwarcia kupujący będą chcieli płacić znacznie mniej, na przykład 42 zł. Kurs otwarcia wyniesie 42 zł i na wykresie powstanie luka: żadna transakcja nie odbyła się między 50 a 42. Stop loss ustawiony na 47 zł zostanie aktywowany na otwarciu, ale sprzedaż nastąpi po 42 zł lub niżej, bo takie są oferty w arkuszu. Zlecenie obronne chroni przed powolnym spadkiem, ale nie przed luką. To jedna z lekcji, którą lepiej odebrać w symulatorze.

## Jak czytać arkusz w praktyce

Kilka wskazówek dla początkujących:

- **Spread mówi o koszcie.** Szeroki spread to droga transakcja „od ręki”. Jeśli spread wynosi 2%, po zakupie i natychmiastowej sprzedaży tracisz 2% plus prowizje.
- **Głębokość mówi o bezpieczeństwie.** Jeśli po stronie kupna stoi 300 akcji, a ty masz 3 000, wyjście z pozycji zajmie czas albo będzie kosztować.
- **Nierównowaga mówi o presji.** Duża przewaga ofert kupna nad ofertami sprzedaży sugeruje presję na wzrost, ale ostrożnie: duże zlecenia w arkuszu bywają wycofywane w ostatniej chwili.
- **Ostatnie transakcje mówią o kierunku.** Jeśli kolejne transakcje zawierane są po cenie ask, kupujący są agresywni; jeśli po cenie bid, sprzedający.

## Dlaczego kurs w tabeli nie jest „prawdą”

Kurs to ostatnia transakcja, czasem sprzed sekundy, czasem sprzed godziny. Przy niepłynnej spółce ostatnia transakcja mogła dotyczyć 10 akcji i nie mówić nic o cenie, po której kupisz 1 000. Doświadczeni inwestorzy patrzą na arkusz, nie na kurs, i myślą o cenie jako o przedziale (bid–ask), a nie o jednej liczbie.

Z tego samego powodu wartość portfela wyceniana po ostatnim kursie jest przybliżeniem. Gdybyś chciał sprzedać wszystko natychmiast, dostałbyś mniej, zwłaszcza przy dużych pozycjach w małych spółkach. To ważne, gdy porównujesz się z innymi w rankingu: wynik w procentach jest liczony po kursie, ale prawdziwa „cena wyjścia” bywa niższa.

## Najczęściej zadawane pytania

**Kto ustala kurs akcji?**
Nikt. Kurs to cena ostatniej transakcji, wynikająca z dopasowania zlecenia kupna i sprzedaży w arkuszu.

**Co to jest spread?**
Różnica między najlepszą ofertą sprzedaży (ask) a najlepszą ofertą kupna (bid). Im węższy, tym tańszy handel.

**Dlaczego zapłaciłem więcej niż kurs z tabeli?**
Bo kupiłeś zleceniem po każdej cenie w arkuszu, w którym po najlepszej cenie było mało akcji. Reszta zrealizowała się po wyższych poziomach.

**Co to jest fixing?**
Aukcja otwarcia: zbieranie zleceń i jednorazowe wyznaczenie kursu, przy którym można zrealizować największy wolumen. Zapobiega chaotycznym cenom na starcie sesji.

**Czym różni się wolumen od obrotu?**
Wolumen to liczba akcji, obrót to ich wartość w złotych (wolumen razy cena).

## Podsumowanie

Kurs akcji jest efektem, nie przyczyną: powstaje z tysięcy zleceń spotykających się w arkuszu. Bid, ask, spread, głębokość, wolumen i aukcja otwarcia to pojęcia, bez których nie da się zrozumieć, dlaczego cena z tabeli nie jest ceną transakcji ani dlaczego stop loss czasem sprzedaje niżej, niż zakładałeś. Najlepiej nauczyć się tego w symulatorze z prawdziwym arkuszem: złóż zlecenie, obserwuj, co dzieje się w tabeli, a potem spróbuj kupić dużą pozycję w małej spółce i zobacz, jak sam ruszasz kursem.

---
*Sugerowane linki wewnętrzne: rodzaje zleceń (artykuł 4), analiza techniczna (artykuł 6), dywidendy, raporty i IPO (artykuł 9).*
*Sugerowany CTA: „Zobacz prawdziwy arkusz zleceń w akcji – zagraj w Maklerię”.*
