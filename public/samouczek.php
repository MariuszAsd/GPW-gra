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
     'Tabela wszystkich spółek: kurs, zmiana od otwarcia, oferty kupna (BID) i sprzedaży (ASK), obrót. Kropka przy obrocie pokazuje płynność. Kliknij wiersz, żeby otworzyć spółkę.',
     'market.php', 'Otwórz Rynek'],
    ['🛒', 'Pierwsze zlecenie',
     'Na karcie spółki wybierz KUP, potem: LIMIT = kupisz po swojej cenie lub lepiej (czeka w arkuszu), PKC = kupujesz natychmiast po najlepszej ofercie. Podaj ilość i zatwierdź.',
     'market.php', 'Wybierz spółkę i spróbuj'],
    ['🛡️', 'Ochrona pozycji: SL i TP',
     'Stop-Loss sprzedaje automatycznie, gdy kurs SPADNIE do progu (ucina stratę). Take-Profit — gdy WZROŚNIE (zamyka zysk). Ustawisz je przy kupnie albo później w Portfelu.',
     'pomoc.php', 'Prosta infografika w Pomocy'],
    ['💼', 'Portfel',
     'Twoje akcje (ze średnią ceną zakupu i wynikiem), aktywne zlecenia, historia transakcji i zamknięte pozycje. Kliknij zlecenie, żeby zobaczyć oś czasu: co, kiedy i dlaczego się stało.',
     'portfolio.php', 'Otwórz Portfel'],
    ['📰', 'Newsy ruszają kursami',
     'Komunikaty ESPI dotyczą jednej spółki, wydarzenia — sektora albo całego rynku. Dobre wieści podnoszą kursy, złe zbijają. Reaguj, zanim zrobią to boty.',
     'wiadomosci.php', 'Przeczytaj dzisiejsze newsy'],
    ['📈', 'Raporty i dywidendy',
     'Co miesiąc spółki raportują zyski — pozytywna niespodzianka to często rajd kursu. Część spółek dzieli się zyskiem: trzymasz akcje w dniu dywidendy — dostajesz gotówkę.',
     'wiadomosci.php', 'Kalendarz raportów'],
    ['📉', 'Krachy, hossy, plotki',
     'Rynkiem rządzą wydarzenia: od plotek o przejęciach po krachy. Duże są rzadkie, małe częste. Odważni kupują, gdy leje się krew — jest za to odznaka.',
     'wiadomosci.php', 'Śledź wydarzenia'],
    ['⚔️', 'Wyzwania — konkursy z pulą',
     'Wpłacasz buy-in na ODDZIELNY portfel wyzwania i przez kilkanaście sesji rywalizujesz z innymi. Wpisowe wszystkich tworzy pulę — dzieli ją top ~20% graczy. Tabela wyników na żywo.',
     'wyzwania.php', 'Zobacz aktualne wyzwanie'],
    ['📢', 'Debiuty giełdowe (IPO)',
     'Rynek rośnie: regularnie debiutują nowe spółki — z newsem i powiadomieniem. Świeży debiutant bywa okazją, zanim wycenią go inni.',
     'market.php', 'Wypatruj 📈 w newsach'],
    ['🏆', 'Ranking, odznaki i profil',
     'Ranking liczy kapitał (gotówka + akcje). Za osiągnięcia zbierasz odznaki (jest ich ' . count(Achievements::all()) . '). Kliknij nick gracza, żeby zobaczyć jego profil i akcjonariat spółek.',
     'ranking.php', 'Sprawdź swoją pozycję'],
    ['💬', 'Czat i społeczność',
     'Na Rynku jest czat — pytaj, chwal się, ostrzegaj. Nicki prowadzą do profili graczy.',
     'market.php', 'Napisz coś na czacie'],
    ['📜', 'Dziennik — pełna historia',
     'Każde zlecenie, transakcja, dywidenda i odznaka zapisuje się w Twoim Dzienniku. Gdy nie wiesz, „co się stało z moimi akcjami" — tu znajdziesz odpowiedź.',
     'dziennik.php', 'Otwórz Dziennik'],
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