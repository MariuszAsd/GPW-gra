<?php
/**
 * Samouczek: przewodnik po całej grze, krok po kroku.
 * WAŻNE: przy dodawaniu nowej funkcji do gry DOPISZ tutaj krok — to jedno
 * miejsce prawdy o tym, "jak grać" (linkowane z Pulpitu, Pomocy i nawigacji).
 */
require __DIR__ . '/_boot.php';
$user = require_login();

$kroki = [
    ['🎯', 'O co chodzi w grze?',
     'Startujesz ze 100 000 PLN. Cel: pierwszy milion. Kupujesz akcje taniej, sprzedajesz drożej — a rynek żyje: spółki publikują raporty, płacą dywidendy, zdarzają się krachy i hossy.',
     'ranking.php', 'Zobacz ranking i cel gry'],
    ['🕐', 'Godziny handlu',
     'Giełda działa jak prawdziwa: handel trwa 7:50–22:00. Po zamknięciu kursy stoją, a zlecenia złożysz dopiero po otwarciu. Jedna sesja = jeden dzień giełdowy.',
     'market.php', 'Sprawdź status rynku'],
    ['📊', 'Rynek — Twoja mapa',
     'Moduł Rynek ma podzakładki na górze: NOTOWANIA (tabela spółek: kurs, zmiana, BID/ASK, obrót — kropka pokazuje płynność), BRANŻE (trendy sektorowe: która branża ciągnie, która tonie), REKOMENDACJE (wyceny analityków + skaner sygnałów AT), IPO (oferty publiczne) i NEWSY I ESPI. Kliknij wiersz w Notowaniach, żeby otworzyć spółkę.',
     'market.php', 'Otwórz Rynek'],
    ['🛒', 'Pierwsze zlecenie',
     'Na karcie spółki wybierz KUP, potem: LIMIT = kupisz po swojej cenie lub lepiej (czeka w arkuszu), PKC = kupujesz natychmiast po najlepszej ofercie. Podaj ilość i zatwierdź.',
     'market.php', 'Wybierz spółkę i spróbuj'],
    ['🛡️', 'Ochrona pozycji: SL, TP i SL kroczący',
     'Stop-Loss sprzedaje automatycznie, gdy kurs SPADNIE do progu (ucina stratę). Take-Profit — gdy WZROŚNIE (zamyka zysk). Jest też SL KROCZĄCY: podajesz procent (np. 8), a próg sam podąża za rosnącym kursem — zawsze 8% pod szczytem. Kurs rośnie? Zysk chroniony. Zawraca? Sprzedaż. Ustawisz je przy kupnie albo później w Portfelu.',
     'pomoc.php', 'Prosta infografika w Pomocy'],
    ['💼', 'Portfel',
     'Twoje akcje (ze średnią ceną zakupu i wynikiem), aktywne zlecenia, historia transakcji i zamknięte pozycje. Kliknij zlecenie, żeby zobaczyć oś czasu: co, kiedy i dlaczego się stało.',
     'portfolio.php', 'Otwórz Portfel'],
    ['📰', 'Newsy ruszają kursami — naucz się je czytać',
     'Każda wiadomość ma KLASĘ: FUNDAMENTY (twarde fakty o pieniądzach — trwale przesuwają wycenę, grają na nich inwestorzy wartościowi), NASTROJE (plotki i opinie — paliwo spekulantów, efekt zwykle wygasa i kurs wraca) oraz TECHNIKA (komentarze z wykresu — czytają je fundusze algorytmiczne, więc bywają samospełniające). Boty w grze naprawdę reagują różnie na różne klasy i różne spółki — dokładnie jak na prawdziwym rynku. Przed raportem spółki wypatruj newsa „Konsensus przed wynikami": bicie konsensusu podbija kurs, rozczarowanie ciąży.',
     'wiadomosci.php', 'Przeczytaj dzisiejsze newsy'],
    ['📈', 'Raporty i dywidendy',
     'Co miesiąc spółki raportują zyski — pozytywna niespodzianka to często rajd kursu. Część spółek dzieli się zyskiem: trzymasz akcje w dniu dywidendy — dostajesz gotówkę.',
     'wiadomosci.php', 'Kalendarz raportów'],
    ['📉', 'Krachy, hossy, widełki',
     'Rynkiem rządzą wydarzenia: od plotek o przejęciach po krachy. Duże są rzadkie, małe częste. Gdy kurs spółki przekroczy ±15% od otwarcia sesji, giełda ZAWIESZA notowania na ~10 minut (widełki, jak na prawdziwej giełdzie) — panika dostaje przymusową pauzę, a po wznowieniu widełki się rozszerzają. Odważni kupują, gdy leje się krew — jest za to odznaka.',
     'wiadomosci.php', 'Śledź wydarzenia'],
    ['📐', 'Analiza techniczna',
     'Karta spółki ma zakładkę Analiza: 10 wskaźników (RSI, MACD, średnie, wybicia...) składa się w jeden sygnał kupuj/sprzedaj. Każda spółka ma swój charakter — techniczne słuchają wskaźników, fundamentalne raportów. Boty AT widzą ten sam sygnał co Ty.',
     'market.php', 'Otwórz spółkę i zakładkę Analiza'],
    ['⭐', 'Obserwowane spółki',
     'Gwiazdka przy spółce (na Rynku albo jej karcie) przypina ją na górze tabeli i w widżecie Pulpitu. Z Pakietem Analityka obserwowane spółki wysyłają alert 🔔, gdy pojawi się mocny sygnał techniczny.',
     'market.php', 'Oznacz pierwszą spółkę gwiazdką'],
    ['⚔️', 'Liga: Wyzwania, Ranking, Sezon',
     'Zakładka LIGA (dolny pasek / rail) zbiera całą rywalizację. WYZWANIA: wpłacasz buy-in na ODDZIELNY portfel i przez kilkanaście sesji ścigasz się z innymi o pulę (dzieli ją top ~20%). RANKING: kto ma największy kapitał. SEZON: punkty z edycji ligi i karnet. Przełączasz je podzakładkami na górze.',
     'wyzwania.php', 'Wejdź do Ligi'],
    ['🏁', 'Sezon i karnet',
     'Edycje ligi (serii wyzwań) dają punkty sezonowe za zajęte miejsca. Punkty odblokowują progi nagród w tokenach — każdy ma ścieżkę darmową, a karnet premium dokłada drugą, hojniejszą (z tytułem „Legenda Sezonu" na końcu).',
     'sezon.php', 'Sprawdź swój postęp w sezonie'],
    ['📢', 'IPO — zapisy, redukcja, debiut',
     'Nowe spółki wchodzą na giełdę przez OFERTĘ PUBLICZNĄ: zapisujesz się na akcje po stałej cenie emisyjnej (zakładka IPO na Rynku), o pulę konkurują też fundusze z gry. Popyt większy niż pula = REDUKCJA — dostajesz proporcjonalnie mniej, nadpłata wraca. Gorący popyt zwykle oznacza gorący debiut. Kwota zapisu cały czas liczy się do Twojego kapitału.',
     'ipo.php', 'Zobacz aktualną ofertę'],
    ['🏦', 'Lokaty — bezpieczny procent',
     'Gotówki, której nie inwestujesz, nie musisz trzymać bezczynnie: w Portfelu (zakładka Lokaty) zamrozisz ją na kilka sesji za stały procent. Kapitał lokaty nadal liczy się do rankingu i celu gry. Zerwiesz przed terminem? Kapitał wraca, odsetki przepadają. Klasyczny dylemat inwestora: pewny mały procent czy ryzyko akcji.',
     'portfolio.php?tab=lok', 'Załóż pierwszą lokatę'],
    ['🏆', 'Ranking, odznaki i profil',
     'Ranking liczy kapitał (gotówka + akcje + lokaty i zapisy IPO). Za osiągnięcia zbierasz odznaki (jest ich ' . count(Achievements::all()) . '). Kliknij nick gracza, żeby zobaczyć jego profil i akcjonariat spółek.',
     'ranking.php', 'Sprawdź swoją pozycję'],
    ['💬', 'Czat i fora spółek',
     'Na Rynku jest czat ogólny, a każda spółka ma własną Dyskusję (zakładka na jej karcie) — opinie, pytania, plotki graczy. Gdy ktoś odpowie w dyskusji, w której pisałeś, dostaniesz powiadomienie 🔔. Gramy kulturalnie: wulgaryzmy i obelgi są automatycznie gwiazdkowane, a moderacja widzi każde użycie — recydywa kończy się usunięciem z gry. Pamiętaj: wpisy innych to opinie, nie rekomendacje.',
     'market.php', 'Napisz coś na czacie'],
    ['📜', 'Dziennik — pełna historia',
     'Każde zlecenie, transakcja, dywidenda i odznaka zapisuje się w Twoim Dzienniku. Gdy nie wiesz, „co się stało z moimi akcjami" — tu znajdziesz odpowiedź.',
     'dziennik.php', 'Otwórz Dziennik'],
    ['🔥', 'Codzienne nagrody: seria i misje',
     'Samo wejście do gry podbija serię: +1 token dziennie i bonus +3 co siódmy dzień z rzędu (przerwa zeruje). Do tego trzy misje dnia na Pulpicie — wspólne dla wszystkich graczy, każda płaci tokenami. Podaj też e-mail w Ustawieniach konta, żeby nie stracić konta przy zapomnianym haśle.',
     'pulpit.php', 'Sprawdź dzisiejsze misje'],
    ['🪙', 'Tokeny inwestora i premium',
     'Za odznaki, podium wyzwań i progi sezonu zbierasz Tokeny inwestora (możesz je też doładować). W sekcji Tokeny inwestora wymienisz je na Pakiet Analityka (skaner AT, alerty, rekomendacje DZIEŃ przed innymi), Raport Premium (pełna analiza każdej spółki) albo kosmetykę: tytuły, kolory nicka, ramki. Graj regularnie — aktywni gracze dostają w prezencie darmowy okres próbny pełnego premium. Tokeny nigdy nie kupują PLN — ranking pozostaje uczciwy.',
     'sklep.php', 'Zobacz Tokeny inwestora'],
    ['❓', 'Pomoc zawsze pod ręką',
     'Znaczki ? przy polach pokazują dymki z wyjaśnieniem. Pełne infografiki (SL/TP, zlecenia, dywidendy) znajdziesz w Pomocy. Podpowiedzi nad działami możesz wyłączyć — i włączyć ponownie tutaj.',
     'pomoc.php', 'Otwórz Pomoc'],
];

layout_header('Samouczek', $user, 'help');
?>
<div class="page-head">
  <h1>Samouczek</h1>
  <span class="muted"><?= count($kroki) ?> kroków · ~3 minuty · wracaj tu, kiedy chcesz</span>
  <a class="btn sm ghost" style="margin-left:auto" href="pulpit.php">← Pulpit</a>
</div>

<p class="muted" style="margin:0 0 14px">
  <a class="btn sm ghost" href="#" onclick="Object.keys(localStorage).forEach(k=>{if(k.startsWith('exp_'))localStorage.removeItem(k)});Object.keys(sessionStorage).forEach(k=>{if(k.startsWith('exp_'))sessionStorage.removeItem(k)});alert('Podpowiedzi nad działami znów będą widoczne.');return false">Przywróć wszystkie podpowiedzi nad działami</a>
</p>

<?php foreach ($kroki as $i => [$ic, $tytul, $opis, $link, $cta]): ?>
  <section class="panel tut-step" style="margin-bottom:10px">
    <div style="display:flex;gap:14px;align-items:flex-start">
      <div class="tut-n"><span><?= $i + 1 ?></span><?= $ic ?></div>
      <div style="flex:1">
        <h2 style="margin:0 0 4px"><?= h($tytul) ?></h2>
        <p style="margin:0 0 8px;line-height:1.55"><?= h($opis) ?></p>
        <a class="btn sm ghost" href="<?= h($link) ?>"><?= h($cta) ?> →</a>
      </div>
    </div>
  </section>
<?php endforeach; ?>

<section class="panel" style="text-align:center;padding:22px">
  <h2 style="margin:0 0 6px">Gotowy?</h2>
  <p class="muted" style="margin:0 0 12px">Najlepiej uczy rynek — zacznij od małego zakupu i obserwuj, co się dzieje.</p>
  <a class="btn" style="max-width:280px;display:inline-block" href="market.php">Wchodzę na Rynek</a>
</section>
<?php layout_footer();