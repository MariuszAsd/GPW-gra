<?php
require __DIR__ . '/_boot.php';
$user = require_login();
layout_header('Pomoc', $user, 'help');
?>
<div class="page-head"><h1>Jak grać — proste wyjaśnienia</h1>
  <a class="btn sm ghost" style="margin-left:auto" href="samouczek.php">Samouczek krok po kroku</a></div>

<div class="help-toc">
  <a href="#arkusz">Arkusz zleceń</a>
  <a href="#limit">Zlecenie LIMIT</a>
  <a href="#pkc">Zlecenie PKC</a>
  <a href="#plynnosc">Płynność i obrót</a>
  <a href="#waznosc">Ważność zlecenia</a>
  <a href="#sl">Stop-Loss</a>
  <a href="#trailing">SL kroczący</a>
  <a href="#tp">Take-Profit</a>
  <a href="#dywidenda">Dywidendy</a>
  <a href="#wydarzenia">Wydarzenia</a>
  <a href="#wyzwania">Wyzwania</a>
  <a href="#lokaty">Lokaty</a>
  <a href="#widelki">Widełki</a>
  <a href="#ipo">IPO</a>
  <a href="#prowizja">Prowizja</a>
  <a href="#cel">Cel gry</a>
</div>

<div class="panel help-sec" id="arkusz">
  <h3>📖 Arkusz zleceń — gdzie spotykają się kupujący i sprzedający</h3>
  <p>Giełda to lista ofert. Po lewej <b class="up">KUPNO (bid)</b> — ile ktoś chce zapłacić.
     Po prawej <b class="down">SPRZEDAŻ (ask)</b> — za ile ktoś chce sprzedać.
     Transakcja następuje, gdy te ceny się spotkają. Różnica między najlepszym kupnem a sprzedażą to <b>spread</b>.</p>
  <svg class="help-svg" viewBox="0 0 560 150">
    <text x="120" y="22" fill="var(--up)" font-size="13" font-weight="bold" text-anchor="middle">KUPNO (bid)</text>
    <text x="440" y="22" fill="var(--down)" font-size="13" font-weight="bold" text-anchor="middle">SPRZEDAŻ (ask)</text>
    <rect x="30" y="34" width="180" height="24" rx="5" fill="var(--up-bg)"/><text x="45" y="51" fill="var(--up)" font-size="13" font-family="monospace">99,50</text><text x="195" y="51" fill="var(--soft)" font-size="12" font-family="monospace" text-anchor="end">40 szt.</text>
    <rect x="30" y="64" width="150" height="24" rx="5" fill="var(--up-bg)"/><text x="45" y="81" fill="var(--up)" font-size="13" font-family="monospace">99,00</text><text x="165" y="81" fill="var(--soft)" font-size="12" font-family="monospace" text-anchor="end">25 szt.</text>
    <rect x="350" y="34" width="180" height="24" rx="5" fill="var(--down-bg)"/><text x="365" y="51" fill="var(--down)" font-size="13" font-family="monospace">100,50</text><text x="515" y="51" fill="var(--soft)" font-size="12" font-family="monospace" text-anchor="end">35 szt.</text>
    <rect x="350" y="64" width="150" height="24" rx="5" fill="var(--down-bg)"/><text x="365" y="81" fill="var(--down)" font-size="13" font-family="monospace">101,00</text><text x="485" y="81" fill="var(--soft)" font-size="12" font-family="monospace" text-anchor="end">20 szt.</text>
    <path d="M 218 46 L 342 46" stroke="var(--line2)" stroke-width="1.5" stroke-dasharray="4 4"/>
    <text x="280" y="40" fill="var(--faint)" font-size="11" text-anchor="middle" class="pulse">spread 1,00</text>
    <text x="280" y="125" fill="var(--soft)" font-size="12" text-anchor="middle">kupisz od ręki po 100,50 · sprzedasz od ręki po 99,50</text>
  </svg>
</div>

<div class="panel help-sec" id="limit">
  <h3>🎯 Zlecenie LIMIT — „kupię/sprzedam po mojej cenie albo lepiej"</h3>
  <p>Podajesz cenę graniczną. <b>Kupno:</b> zapłacisz najwyżej tyle. <b>Sprzedaż:</b> dostaniesz co najmniej tyle.
     Jeśli w arkuszu nie ma pasującej oferty, zlecenie <b>czeka</b>, aż ktoś przyjdzie z drugiej strony.</p>
  <p>Gdy Twoje czekające zlecenie zostanie zrealizowane, transakcja idzie <b>po Twojej cenie</b> —
     tak działa prawdziwa giełda: kto czeka w arkuszu, handluje po swojej cenie.</p>
  <div class="help-ex">💡 Przykład: kurs 100. Wystawiasz kupno z limitem 98. Nic się nie dzieje, dopóki ktoś nie
     zechce sprzedać po 98 lub taniej. Wtedy kupujesz dokładnie po 98.</div>
</div>

<div class="panel help-sec" id="pkc">
  <h3>⚡ Zlecenie PKC — „po każdej cenie, byle teraz"</h3>
  <p>Nie podajesz ceny — bierzesz to, co <b>stoi w arkuszu</b>, natychmiast. Kupno zgarnia najtańsze oferty
     sprzedaży (od najlepszej w górę), sprzedaż trafia w najwyższe oferty kupna (od najlepszej w dół).</p>
  <p><b>Uwaga:</b> przy dużej ilości „zjadasz" kolejne poziomy cen — średnia może być gorsza niż kurs na ekranie.
     To się nazywa <b>poślizg</b>. Na spokojnym rynku przy małych ilościach jest pomijalny.</p>
  <div class="help-ex">💡 PKC = szybkość kosztem ceny. LIMIT = cena kosztem czekania.</div>
</div>

<div class="panel help-sec" id="plynnosc">
  <h3>💧 Płynność i obrót — czy łatwo tu kupić i sprzedać?</h3>
  <p><b>Obrót</b> to wartość wszystkich transakcji (w PLN) — pokazujemy go pod wykresem spółki (słupki)
     i w kolumnie „Obrót (sesja)" na Rynku. <b>Płynność</b> mówi, jak łatwo zawrzeć transakcję bez ruszania kursu.
     Oznaczamy ją kropką: <span class="liq hi">●</span> wysoka · <span class="liq mid">●</span> średnia · <span class="liq lo">●</span> niska.</p>
  <p>Przy <b>wysokiej płynności</b> w arkuszu stoi dużo ofert, widełki bid–ask są wąskie — kupisz i sprzedasz
     blisko kursu z ekranu. Przy <b>niskiej płynności</b> ofert jest mało i są rozstrzelone: większe zlecenie PKC
     „zjada" kolejne poziomy cen i realizuje się po gorszej średniej (to tzw. <b>poślizg</b>).</p>
  <div class="help-ex">💡 W mało płynnych spółkach składaj zlecenia LIMIT i dziel większe zakupy na mniejsze części.
     Zanim klikniesz PKC, spójrz na arkusz: ile sztuk stoi po drugiej stronie i po jakich cenach.</div>
</div>

<div class="panel help-sec" id="waznosc">
  <h3>⏳ Ważność zlecenia</h3>
  <p><b>Bezterminowe</b> — czeka w arkuszu, aż je zrealizujesz lub anulujesz.
     <b>Do końca sesji</b> — jeśli do końca bieżącej sesji się nie zrealizuje, samo zniknie,
     a zarezerwowana gotówka/akcje wrócą do Ciebie.</p>
</div>

<div class="panel help-sec" id="sl">
  <h3>🛡️ Stop-Loss (SL) — automatyczny hamulec strat</h3>
  <p>Ustawiasz próg <b>poniżej</b> obecnego kursu. Jeśli kurs spadnie do progu, gra <b>sama sprzedaje</b>
     wskazaną ilość akcji po najlepszych cenach z arkusza. Chroni Cię, gdy nie patrzysz na notowania.</p>
  <svg class="help-svg" viewBox="0 0 560 170">
    <line x1="20" y1="140" x2="540" y2="140" stroke="var(--line)"/>
    <polyline points="30,60 90,52 150,66 210,58 270,72 330,64" fill="none" stroke="var(--up)" stroke-width="2"/>
    <g class="drop"><polyline points="330,64 390,80 450,96" fill="none" stroke="var(--down)" stroke-width="2"/>
      <circle cx="450" cy="96" r="5" fill="var(--down)"/></g>
    <line x1="20" y1="110" x2="540" y2="110" stroke="var(--gold)" stroke-width="1.5" stroke-dasharray="6 4"/>
    <text x="28" y="104" fill="var(--gold)" font-size="12" font-weight="bold">próg Stop-Loss</text>
    <text x="470" y="128" fill="var(--down)" font-size="12" class="pulse">SPRZEDAŻ! 🛡️</text>
    <text x="280" y="160" fill="var(--soft)" font-size="12" text-anchor="middle">kurs spada do progu → gra sprzedaje za Ciebie → strata ucięta</text>
  </svg>
  <div class="help-ex">💡 Kupiłeś po 100 i nie chcesz stracić więcej niż ~5%? Ustaw SL na 95.
     W grze SL obejmuje <b>konkretny pakiet</b> (np. 10 szt.) — resztę akcji zostawia w spokoju.</div>
</div>

<div class="panel help-sec" id="trailing">
  <h3>🪜 Stop-Loss kroczący (trailing)</h3>
  <p>Zwykły SL stoi w miejscu. <b>SL kroczący</b> podajesz jako procent (np. 8%) — próg trzyma się zawsze
     8% pod NAJWYŻSZYM kursem od ustawienia: kurs rośnie → próg rośnie razem z nim; kurs zawraca → próg stoi
     i przy przebiciu sprzedaje. Efekt: chronisz narastający zysk bez ręcznego przestawiania progu.
     Ustawisz go w Portfelu przy pozycji (pole „krocz. %").</p>
  <div class="help-ex">💡 Przykład: kupujesz po 100, SL kroczący 8%. Kurs idzie na 150 — próg sam wjeżdża na 138.
     Korekta do 138 = sprzedaż z zyskiem +38%, a nie powrót do zera.</div>
</div>

<div class="panel help-sec" id="tp">
  <h3>💰 Take-Profit (TP) — automatyczna kasa zysku</h3>
  <p>Lustrzane odbicie SL: próg <b>powyżej</b> kursu. Gdy kurs urośnie do progu, gra <b>sama sprzedaje</b>
     i zamienia papierowy zysk na gotówkę — zanim rynek zdąży go zabrać.</p>
  <svg class="help-svg" viewBox="0 0 560 150">
    <line x1="20" y1="120" x2="540" y2="120" stroke="var(--line)"/>
    <polyline points="30,100 110,92 190,96 270,80 350,66 430,50" fill="none" stroke="var(--up)" stroke-width="2"/>
    <circle cx="430" cy="50" r="5" fill="var(--up)" class="pulse"/>
    <line x1="20" y1="50" x2="540" y2="50" stroke="var(--up)" stroke-width="1.5" stroke-dasharray="6 4"/>
    <text x="28" y="44" fill="var(--up)" font-size="12" font-weight="bold">próg Take-Profit</text>
    <text x="450" y="44" fill="var(--up)" font-size="12">SPRZEDAŻ! 💰</text>
    <text x="280" y="142" fill="var(--soft)" font-size="12" text-anchor="middle">kurs rośnie do progu → zysk trafia do kieszeni automatycznie</text>
  </svg>
  <div class="help-ex">💡 SL i TP możesz ustawić razem na tym samym pakiecie — co pierwsze się wyzwoli, to sprzedaje.
     Oba widzisz w Portfelu jako zlecenie <b>OBRONNE</b> i możesz je anulować.</div>
</div>

<div class="panel help-sec" id="dywidenda">
  <h3>💵 Dywidendy — spółka dzieli się zyskiem</h3>
  <p>Część spółek co miesiąc (przy raporcie) wypłaca akcjonariuszom <b>dywidendę</b> — gotówkę za każdą
     posiadaną akcję. Wystarczy mieć akcje w dniu raportu; pieniądze wpadają same (także za akcje
     zamrożone w zleceniach). Ile spółka płaci, sprawdzisz na jej podstronie w zakładce <b>Info</b>
     („Polityka dywidendy") i w <b>Raportach</b>.</p>
  <svg class="help-svg" viewBox="0 0 560 145">
    <rect x="40" y="30" width="150" height="64" rx="10" fill="var(--info-bg)" stroke="var(--accent)"/>
    <text x="115" y="57" fill="var(--ink)" font-size="13" font-weight="bold" text-anchor="middle">SPÓŁKA</text>
    <text x="115" y="76" fill="var(--soft)" font-size="11" text-anchor="middle">zysk z raportu</text>
    <g class="pulse"><path d="M 200 62 L 340 62" stroke="var(--up)" stroke-width="2"/><path d="M 332 54 L 346 62 L 332 70" fill="none" stroke="var(--up)" stroke-width="2"/>
    <text x="270" y="50" fill="var(--up)" font-size="12" text-anchor="middle">0,45 PLN / akcję</text></g>
    <rect x="360" y="30" width="160" height="64" rx="10" fill="var(--up-bg)" stroke="var(--up)"/>
    <text x="440" y="57" fill="var(--ink)" font-size="13" font-weight="bold" text-anchor="middle">TY 💰</text>
    <text x="440" y="76" fill="var(--soft)" font-size="11" text-anchor="middle">100 szt. = +45 PLN</text>
    <text x="280" y="118" fill="var(--soft)" font-size="12" text-anchor="middle">uwaga: w dniu wypłaty kurs spada o wartość dywidendy (odcięcie) —</text>
    <text x="280" y="134" fill="var(--soft)" font-size="12" text-anchor="middle">to nie darmowe pieniądze, tylko zamiana części wyceny na gotówkę</text>
  </svg>
  <div class="help-ex">💡 Strategia dywidendowa: kup spokojne spółki, które dużo wypłacają (Info → „Polityka dywidendy"),
     trzymaj długo i zbieraj gotówkę co miesiąc — mniej emocji niż spekulacja, stabilniejszy wynik.</div>
</div>

<div class="panel help-sec" id="wydarzenia">
  <h3>🌪️ Wydarzenia rynkowe — krachy, hossy, kryzysy branż</h3>
  <p>Od czasu do czasu rynkiem wstrząsa <b>wielkie wydarzenie</b>: krach lub hossa na całej giełdzie,
     albo kryzys/boom w jednej branży. Zobaczysz wtedy baner na stronie Rynku i dostaniesz powiadomienie 🔔.
     Siła wydarzenia jest największa na początku i <b>stopniowo wygasa</b> przez kilkanaście ticków.</p>
  <div class="help-ex">💡 Krach to nie koniec świata — to okazja. Kursy spadają razem z falą paniki,
     ale dobre spółki wracają do wycen z zysków. Kupowanie w dołku krachu (i trzymanie SL-a na wszelki
     wypadek) bywa najlepszą transakcją w grze. Analogicznie: w euforii hossy warto pomyśleć o Take-Profit.</div>
</div>

<div class="panel help-sec" id="wyzwania">
  <h3>⚔️ Wyzwania — pełne zasady krok po kroku</h3>
  <p><b>Wyzwanie to konkurs inwestycyjny na kilkanaście sesji.</b> Rywalizujesz osobnym portfelem,
     a Twoje konto główne gra w tym czasie normalnie dalej.</p>
  <svg viewBox="0 0 560 120" style="width:100%;max-width:560px;display:block;margin:10px auto">
    <line x1="20" y1="46" x2="540" y2="46" stroke="var(--line)" stroke-width="2"/>
    <circle cx="60" cy="46" r="7" fill="var(--accent)"/>
    <circle cx="220" cy="46" r="7" fill="var(--accent)"/>
    <circle cx="380" cy="46" r="7" fill="var(--accent)"/>
    <circle cx="500" cy="46" r="7" fill="var(--up)"/>
    <text x="60" y="24" fill="var(--ink)" font-size="12" font-weight="700" text-anchor="middle">ZAPISY</text>
    <text x="220" y="24" fill="var(--ink)" font-size="12" font-weight="700" text-anchor="middle">START</text>
    <text x="380" y="24" fill="var(--ink)" font-size="12" font-weight="700" text-anchor="middle">HANDEL</text>
    <text x="500" y="24" fill="var(--ink)" font-size="12" font-weight="700" text-anchor="middle">FINAŁ</text>
    <text x="60" y="70" fill="var(--soft)" font-size="10.5" text-anchor="middle">płacisz buy-in</text>
    <text x="60" y="84" fill="var(--soft)" font-size="10.5" text-anchor="middle">+ wpisowe</text>
    <text x="220" y="70" fill="var(--soft)" font-size="10.5" text-anchor="middle">dostajesz portfel</text>
    <text x="220" y="84" fill="var(--soft)" font-size="10.5" text-anchor="middle">wyzwania</text>
    <text x="380" y="70" fill="var(--soft)" font-size="10.5" text-anchor="middle">kilkanaście sesji</text>
    <text x="380" y="84" fill="var(--soft)" font-size="10.5" text-anchor="middle">tabela na żywo</text>
    <text x="500" y="70" fill="var(--soft)" font-size="10.5" text-anchor="middle">buy-in wraca,</text>
    <text x="500" y="84" fill="var(--soft)" font-size="10.5" text-anchor="middle">czołówka dzieli pulę</text>
  </svg>
  <p><b>1. Zapisujesz się.</b> Z konta głównego schodzi <b>buy-in</b> (np. 20 000 PLN — to Twój kapitał startowy
     w konkursie) oraz <b>wpisowe</b> (np. 10% buy-inu — zasila pulę nagród). Maksymalny koszt znasz z góry.</p>
  <p><b>2. Start.</b> Gdy zbierze się minimum graczy, dostajesz powiadomienie 🔔 i osobny portfel wyzwania.
     Przełączasz się na niego na stronie Wyzwań (baner na górze przypomina, którym kontem grasz).
     Za mało chętnych = edycja odwołana i <b>pełny zwrot</b> wszystkiego.</p>
  <p><b>3. Handlujesz.</b> Ten sam rynek, ten sam arkusz zleceń — liczy się kapitał portfela wyzwania
     (gotówka + akcje po bieżącym kursie). Tabela wyników odświeża się na żywo.</p>
  <p><b>4. Finał i rozliczenie.</b> Po ostatniej sesji ranking zamyka się automatycznie:</p>
  <ul style="margin:4px 0 8px 20px;line-height:1.6">
    <li><b>Każdy</b> dostaje z powrotem swój portfel wyzwania — gotówka wraca na konto, akcje przechodzą do portfela głównego.</li>
    <li><b>Top ~20% graczy</b> dzieli pulę wpisowych — im wyższe miejsce, tym większy udział (1. miejsce bierze najwięcej).</li>
    <li>Zwycięzca i podium dostają <b>Tokeny inwestora</b> i odznakę; edycje ligowe dają też punkty sezonu (karnet → sezon.php).</li>
  </ul>
  <div class="help-ex">💡 Ile mogę stracić? Najwyżej wpisowe + to, co stracisz handlując portfelem wyzwania.
     Ile mogę wygrać? Nagrodę z puli (przy 10 graczach 1. miejsce bierze ok. połowy) + zysk z handlu.
     Wyzwanie to najszybsza droga do tokenów i punktów sezonu — a przegrana kosztuje mniej niż wygląda.</div>
</div>

<div class="panel help-sec" id="lokaty">
  <h3>🏦 Lokaty</h3>
  <p>Wolną gotówkę możesz zamrozić na kilka sesji za <b>stały procent</b> (Portfel → Lokaty). Wypłata jest
     automatyczna po terminie. Kapitał lokaty przez cały czas <b>liczy się do Twojego kapitału</b> w rankingu
     i celu gry — nie „znika". Zerwanie przed terminem: kapitał wraca od ręki, odsetki przepadają.</p>
  <div class="help-ex">💡 Lokata to pewny mały zysk kosztem szansy na duży — klasyczna decyzja alokacyjna.
     W czasie hossy zwykle przegrywa z akcjami, w bessie bywa najlepszą pozycją w portfelu.</div>
</div>

<div class="panel help-sec" id="widelki">
  <h3>⏸ Widełki i zawieszenia notowań</h3>
  <p>Jak na prawdziwej giełdzie: gdy kurs spółki oddali się o <b>±15% od otwarcia sesji</b>, giełda zawiesza
     notowania na ~10 minut — nikt nie złoży zlecenia, a SL/TP czekają na wznowienie. Po wznowieniu widełki
     się <b>rozszerzają</b> (±30%), a limit to 2 zawieszenia na sesję. Zawieszenie dostaje komunikat ESPI.</p>
  <div class="help-ex">💡 Zawieszenie po spadku to moment na decyzję na chłodno: panika ma przymusową pauzę.
     Przygotuj zlecenie i złóż je tuż po wznowieniu.</div>
</div>

<div class="panel help-sec" id="ipo">
  <h3>📢 Oferty publiczne (IPO) z zapisami</h3>
  <p>Nowa spółka najpierw <b>ogłasza ofertę</b>: cena emisyjna i pula akcji. Zapisujesz się w zakładce IPO
     (gotówka schodzi od razu, ale liczy się do kapitału). O pulę konkurują też fundusze z gry. Gdy popyt
     przekroczy pulę, działa <b>redukcja proporcjonalna</b> — dostajesz mniej, nadpłata wraca co do grosza.
     Duża redukcja = gorący debiut (i odwrotnie): dokładnie jak na prawdziwej giełdzie.</p>
  <div class="help-ex">💡 Strategia: zapisuj się na więcej, niż chcesz mieć — redukcja i tak przytnie.
     Ale uwaga na zimne oferty: przy słabym popycie dostaniesz wszystko… łącznie z chłodnym otwarciem.</div>
</div>

<div class="panel help-sec" id="prowizja">
  <h3>🧾 Prowizja</h3>
  <p>Przy <b>sprzedaży</b> akcji giełda pobiera prowizję (<?php $f = Engine::one("SELECT v FROM game_state WHERE k='fee_rate'"); echo rtrim(rtrim(number_format($f === false || $f === null ? 0.5 : (float) $f, 2, ',', ''), '0'), ','); ?>% wartości transakcji).
     Kupno jest bez opłat. Wniosek: częste wchodzenie i wychodzenie kosztuje — każda rundka to prowizja.</p>
</div>

<div class="panel help-sec" id="cel">
  <h3>🏆 Cel gry</h3>
  <p>Zaczynasz ze 100 000 PLN. Twoim zadaniem jest zbudować <b>1 000 000 PLN</b> w limicie sesji
     (patrz pasek postępu w Portfelu). Sesja to „dzień giełdowy" gry — kursy żyją cały czas,
     spółki publikują <b>raporty miesięczne</b>, pojawiają się komunikaty <b>ESPI</b>, a sektorami rządzą trendy.
     Czytaj wiadomości i raporty na podstronach spółek — tam często widać, czemu kurs się rusza.</p>
</div>
<?php layout_footer();
