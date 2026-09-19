# Makleria (GPW-gra) — instrukcja dla Claude

Ten plik czytasz automatycznie przy starcie. Zawiera wszystko, czego potrzebujesz,
żeby pracować nad tą grą bez psucia produkcji. **Przeczytaj do końca przed pierwszą zmianą.**

Właściciel projektu pisze po polsku — **odpowiadaj po polsku**. Komentarze w kodzie też są po polsku
(pełnymi zdaniami, tłumaczą DLACZEGO, nie CO). Trzymaj ten styl.

---

## 1. Czym jest ta gra

**Makleria** — symulator giełdy w czystym PHP 8 (bez frameworka, bez composera, bez JS-frameworka).
Gra działa na żywo pod **https://gra.mppp.com.pl/public** i mają ją prawdziwi gracze.

Gracz dostaje 100 000 PLN i **inwestuje długoterminowo — gra nie ma limitu czasu ani celu „milion”**.
Liczy się **stopa zwrotu w procentach**, nigdy kwota: ranking od startu (`start_equity`), liga tygodnia
(nagrody PLN ze skarbca) i liga miesiąca (Tokeny) startują od zera z każdym okresem (migawki
`equity_snapshots`), a **drabinka** `Engine::LADDER` (+10% … +900%) daje odznaki i Tokeny za szczeble.
Obok wyniku gracz widzi **„vs MAK40”** — czy pobił indeks (punkt odniesienia `users.bench_*` z tej samej chwili).
Handluje akcjami 76+ spółek na wspólnym arkuszu zleceń razem ze ~100 botami. Świat żyje sam: boty kwotują,
spółki publikują raporty i dywidendy, newsroom generuje wiadomości, zdarzają się krachy, hossy, IPO
i zawieszenia notowań. Skarbiec gry (prowizje) jest **pulą nagród**: nagrody ligi tygodnia, dopłata do puli
każdego wyzwania z człowiekiem, odsetki lokat, zysk/strata funduszu MAK40.

**Sesja** = dzień giełdowy. **Tick** = puls rynku (cron co minutę). Godziny handlu 07:50–22:00
Europe/Warsaw — poza nimi świat stoi (kursy zamrożone, boty śpią, zlecenia odrzucane).
Pierwsze 10 minut po otwarciu to **faza otwarcia (fixing)**: `Engine::marketPhase()` = `preopen`, zlecenia z limitem
zbierają się (matchBook nie kojarzy, PKC odrzucane, QA pomija przebieg), a pierwszy tick po 08:00 uruchamia
`Engine::openingAuction()` — jeden kurs otwarcia na spółkę (maks. wolumen, `Engine::fixingPrice`). GM ustawia
długość fazy (`market_fixing_minutes`, 0 = wyłączone). Bez godzin handlu (testy) aukcja idzie na starcie każdej sesji tickowej.

### Główne moduły gry
Rynek i arkusz zleceń (limit/PKC, stop-buy „kup, gdy przebije”, SL/TP, SL kroczący) · boty o 5 strategiach z własnym DNA ·
newsroom (ESPI, fundamenty, nastroje, technika) · raporty finansowe i dywidendy · analiza techniczna
(10 wskaźników) · IPO z zapisami i redukcją · wyzwania (konkursy na osobnych portfelach) · sezon i liga ·
lokaty bankowe · fundusz indeksowy MAK40 i „czy pobiłeś indeks?” (Pulpit/Ranking: vs MAK40) · odznaki, misje dnia, seria logowań · tygodniowy raport e-mail i publiczna karta wyniku (Weekly) · PWA (manifest, `sw.js`, strona offline) i powiadomienia push (Push) · tokeny inwestora (premium, PayU) · czat i fora spółek ·
ranking, profile graczy, polecenia i obserwowani gracze · panel GM · dziennik logów.

---

## 2. Architektura — mapa plików

```
config.php            jedna konfiguracja (env → config.local.php → domyślne SQLite)
migrate.php           tworzy/aktualizuje schemat            seed.php  zasiewa świat
verify.php            testy integralności (gotówka/akcje)
cron/tick.php         puls rynku (blokada pliku cron/tick.lock)
cron/qa_probe.php     QA-bot: gra przez HTTP jak gracz, 157 asercji
src/                  logika (patrz niżej)
public/               warstwa web — każda strona to jeden plik PHP; manifest.json + sw.js = PWA (karta.php i sw.js są publiczne celowo)
.github/workflows/    deploy, health, raport, trace, reinstall
```

### `src/` — co gdzie mieszka
| Plik | Odpowiada za |
|---|---|
| `Db.php` | jedno połączenie PDO, `Db::now()`, `Db::driver()` |
| `Schema.php` | **jedno źródło prawdy** dla schematu + `const VERSION` |
| `Migrator.php` | migracje przyrostowe; `Migrator::ensure()` woła się sam z `_boot.php` i `tick.php` |
| `Engine.php` | **serce (~2900 linii)**: escrow, kojarzenie zleceń (`matchBook`, także z ceną fixingu), boty, świece, SL/TP i stop-buy (`checkStops`), sesje i fazy rynku (`marketPhase`, `openingAuction`), ligi i drabinka (`leagueTable`, `settleLeagues`, `checkLadder`), skarbiec, benchmark „vs MAK40”, zamknięta ekonomia (`worldCash`), retencja botów, blokada świata (`worldLock`) |
| `Challenges.php` | wyzwania: zapisy, start z funduszami gry, rozliczenie, pula nagród |
| `Ipo.php` | debiuty giełdowe: oferta, zapisy, redukcja, przydział |
| `Newsroom.php` + `EventCatalog.php` | generowanie newsów i wydarzeń rynkowych |
| `Technical.php` | wskaźniki AT i zbiorczy sygnał |
| `Bank.php` | lokaty · `Seasons.php` sezon · `Daily.php` misje · `Achievements.php` odznaki |
| `Tokens.php` | tokeny premium, pakiety, trial, **polecenia** · `Payments.php` PayU |
| `Recommendations.php` | rekomendacje DM · `Moderation.php` filtr słów · `Mailer.php` (PHP `mail()`, przy porażce treść do dziennika), `PasswordReset.php` |
| `Qa.php` | definicje 157 asercji QA-bota (w tym `inv.money` — suma pieniądza w świecie) |
| `Glossary.php` | słowniczek pojęć (klucz → nazwa, wyjaśnienie, kotwica w Pomocy); dymki `term('klucz')`, sekcja „Słowniczek” w `pomoc.php` renderuje się z niego |
| `Push.php` | Web Push bez composera: klucze VAPID w `game_state`, JWT ES256, aes128gcm, subskrypcje `push_subscriptions`, `Push::flush()` z crona dosyła wpisy z dzwonka |
| `Weekly.php` | „Twój tydzień w Maklerii”: podsumowanie po zamknięciu tygodnia (powiadomienie + e-mail) i publiczna karta wyniku `karta.php?u=TOKEN` z linkiem polecającym |
| `Fund.php` | fundusz indeksowy MAK40: jednostki = indeks/10, pula `game_state.fund_pool` w świecie, zysk/stratę rozlicza skarbiec |
| `Reconcile.php` | rekoncyliacja rezerwacji (panel GM): podgląd rozjazdów escrow + korekta na kliknięcie, nigdy sama |

`public/_boot.php` — wspólny bootstrap każdej strony: sesja, `require_login()`, `require_market_open()`, layout
(z tagami PWA), helpery (`h()`, `money()`, `flash()`, `redirect()`, `explainer()`, `tip()`), przyjazna strona błędu.
Strony **publiczne bez logowania** (celowo): `index.php`, `login/register`, `karta.php` (karta wyniku po tokenie),
`manifest.json`, `sw.js`, `offline.html`. Skrypty silnika (`cron/*`, `migrate.php`, `seed.php`) mają strażnika CLI —
health check pilnuje, że z internetu odpowiadają 403/404.

---

## 3. ŻELAZNE ZASADY — złamanie = zepsuta gra

1. **Ekonomia jest zamknięta.** Pieniądze nie powstają z powietrza. Jedyne legalne źródło nowej
   gotówki to dywidendy spółek (odsetki lokat i nagrody lig płaci skarbiec, czyli zebrane prowizje).
   Pilnuje tego asercja QA `inv.money`: `Engine::worldCash()` musi równać się kotwicy `world_cash_base`
   + `dividends_paid`. Kapitał startowy nowego gracza (+) i zapłata za akcje z IPO (−) przesuwają kotwicę
   przez `Engine::worldCashAdjust()` — każde nowe źródło/ujście gotówki MUSI ją tak samo przesuwać.
   Fundusz MAK40 nie tworzy pieniądza: wpłaty siedzą w puli (`fund_pool`, liczonej w `worldCash`), a zysk/stratę
   przy sprzedaży jednostek płaci/zatrzymuje skarbiec (jak odsetki lokat).
2. **Escrow musi się zgadzać co do grosza.** Niezmienniki, które sprawdza QA:
   - `users.cash_reserved` = Σ (qty × price) zleceń KUPNA tego gracza ze statusem `active` **i `pending`**
     (stop-buy czeka na przebicie progu z zarezerwowaną gotówką ilość × limit; próg siedzi w `tp_price`),
   - `wallets.qty_reserved` = Σ ilości aktywnych zleceń SPRZEDAŻY + zleceń obronnych,
   - nigdzie ujemnej gotówki ani ujemnych ilości akcji.
3. **Wzorzec „claim-first" przy każdej zmianie statusu.** Najpierw atomowo przejmij wiersz
   (`UPDATE ... WHERE id=? AND status='active'` + sprawdź `rowCount()===1`), dopiero potem ruszaj
   pieniądze. Zwroty licz ze **świeżego odczytu po przejęciu**, nigdy ze starego zrzutu.
   Powód: na MySQL żądania HTTP graczy biegną równolegle z transakcją ticka. Ten wzorzec zamknął
   serię realnych błędów (podwójne zwroty escrow, handel na anulowanym zleceniu) — nie cofaj go.
4. **Zmiana schematu = 3 kroki naraz:** dopisz kolumnę/tabelę w `Schema.php`, podbij `Schema::VERSION`,
   dopisz migrację o tym numerze w `Migrator.php`. Migracje są idempotentne i odpalają się same
   na produkcji przy pierwszym żądaniu po deployu. Aktualnie **wersja 43**.
5. **Sekretów nie commituj.** `config.local.php` i `.env` są w `.gitignore`. Dane bazy produkcyjnej
   żyją w sekretach GitHuba i workflow sam buduje z nich `config.local.php` na serwerze.
6. **Uważaj na polskie znaki w kodzie PHP.** Cudzysłów `"` wewnątrz komentarza SQL w stringu PHP
   rozwala parser — używaj `„ "` typograficznych albo omijaj. Po każdej edycji: `php -l <plik>`.
7. **Nie aliasuj `COUNT(*)` jako `c` w zapytaniach o świece** — kolumna `c` to cena zamknięcia
   i przesłoni alias.
8. **Nowa funkcja = krok w samouczku.** `public/samouczek.php` to jedno miejsce prawdy o tym,
   „jak grać". Dodajesz mechanikę → dopisujesz krok (i zwykle sekcję w `pomoc.php`).
   **Nowe pojęcie = wpis w `src/Glossary.php` + dymek `term('klucz')`** przy każdym miejscu, gdzie słowo pada
   (nagłówek tabeli, kafelek, etykieta pola). Właściciel chce gry zrozumiałej dla początkujących: żargon bez dymka
   to błąd. Dymki są tapowalne na telefonie (klasa `.tip.open`).
9. **Silnik i HTTP nie ścigają się na skróty.** Wszystko, co rusza świat (tick, akcje GM, rekoncyliacja), idzie pod
   `Engine::worldLock()`; strony ponawiają zakleszczenia przez `Engine::retryOnLock()`. Wysyłka push i e-maili
   zostaje **poza** transakcją ticka (`Push::flush()` w `cron/tick.php` po tickach).
10. **Migracje są idempotentne po kodzie błędu** (1050/1060/1061 na MySQL, „already exists”/„duplicate column”
    na SQLite) i biegną pod `GET_LOCK('makleria_migrate')` — pierwsze żądania po deployu wchodzą równolegle.
    Kroki tylko dla jednego silnika zapisuj jako `null` dla drugiego.

---

## 4. Jak testować (rób to ZAWSZE przed commitem)

Lokalnie masz SQLite bez żadnej konfiguracji. Pełny cykl:

```bash
cd /ścieżka/do/repo
php migrate.php && php seed.php          # świeży świat (nadpisuje data/tycoon.sqlite — OK)
php cron/tick.php 100                    # 100 ticków; wpisy logów source='qa' to normalny szum
php -S 127.0.0.1:8123 -t public &        # serwer w tle
APP_URL=http://127.0.0.1:8123 php cron/qa_probe.php
# MUSI wypisać: ✅ QA OK — asercji: 157
```

**Test na MySQL jest obowiązkowy dla zmian dotykających transakcji/wyścigów** (produkcja to MySQL,
a SQLite maskuje wyścigi, bo serializuje zapisy). Jeśli w środowisku nie ma MariaDB:
`apt-get update && apt-get install -y mariadb-server`, potem
`mariadb-install-db --no-defaults --user=root --datadir=<kat>` i uruchom `mariadbd` na sockecie
`/tmp/m.sock`, port 3307. Każde wywołanie PHP potrzebuje wtedy **jednocześnie**
flagi `-d pdo_mysql.default_socket=/tmp/m.sock` i zmiennych `DB_DRIVER=mysql DB_HOST=localhost
DB_NAME=gpw DB_USER=root DB_PASS=`. Docroot ustaw na katalog repo, a strony są pod `/public/`.

**Po każdej zmianie sprawdź niezmienniki** zapytaniami z punktu 3 — nie polegaj wyłącznie na QA.
`php verify.php` (CLI) sprawdza dodatkowo sumę pieniądza vs kotwica i liczbę akcji każdej spółki między przebiegami.

Ustawienia testowe po zasiewie: `Engine::setState('market_hours_enabled','0')` (sesja = `ticks_per_session` ticków,
fixing na starcie każdej sesji) i `Engine::setState('qa_every_ticks','100000')` (inaczej tick próbuje odpalić QA
na produkcyjnym `app_url`, co w piaskownicy kończy się „QA BŁĘDY: 65” — to nie błąd gry). QA w fazie otwarcia
(`marketPhase()==='preopen'`) świadomie pomija przebieg. Dwa przebiegi QA pod rząd są w porządku (wpisy QA na
czacie/forum są postarzane, więc anty-spam ich nie blokuje). Sztuczne ingerencje w bazie (np. surowe `DELETE FROM orders`)
psują rezerwacje botów i kotwicę pieniądza — po takich testach zasiej świat na nowo, zanim uznasz QA za wiarygodne.

### Pułapki środowiska (kosztowały czas — nie powtarzaj)
- Serwer w tle uruchamiaj tak, żeby nie dziedziczył potoków (`</dev/null`, `disown`), inaczej
  powłoka zawiesza się na `tail`/potoku i nie zobaczysz wyniku testu.
- `cron/tick.lock` jest wspólny dla całego repo: dwa równoległe ticki wykluczają się
  („Poprzedni cykl trwa") — to nie jest błąd gry.
- Przy testach wyzwań/sesji wyłącz godziny handlu: `Engine::setState('market_hours_enabled','0')`,
  inaczej sesje nie przechodzą dalej i nic nie wystartuje.
- Zmienne środowiskowe **nie** przechodzą między wywołaniami powłoki — eksportuj je za każdym razem.

---

## 5. Wdrożenie i weryfikacja produkcji

**Push do `main` = deploy na żywo.** Workflow „Deploy (FTP)" wysyła pliki FTPS-em na hosting.
Migracje schematu wykonają się same przy pierwszym żądaniu.

Ze środowiska Claude **nie masz dostępu sieciowego do domeny gry** (proxy blokuje). Produkcję
sprawdzasz wyłącznie przez GitHub Actions — masz do tego gotowe workflow uruchamiane plikiem-wyzwalaczem:

| Chcesz | Zrób | Workflow |
|---|---|---|
| sprawdzić, czy gra żyje po deployu | nadpisz `.healthcheck` datą i wypchnij | Health check |
| zobaczyć, co się dzieje w grze (rynek, newsy, wyzwania, ranking, błędy 24 h) | nadpisz `.raport` | Raport stanu gry |
| prześledzić konkretnego gracza | wpisz jego login do `.trace` | Trace |
| **zresetować świat od zera** | nadpisz `.reinstall` | Reinstall — **DESTRUKCYJNE, tylko na wyraźne polecenie właściciela** |

Wyniki czytasz przez narzędzia GitHub MCP: `actions_list` → znajdź run → `list_workflow_jobs` → `get_job_logs`.
Logi bywają duże — parsuj je skryptem, nie wklejaj w całości.

Health check sprawdza: stronę logowania, API rynku (kursy + indeks), pliki PWA (`manifest.json`, `sw.js`, `offline.html`,
ikona) i nieosiągalność skryptów silnika. Workflow **Raport** i **Trace** logują się jako admin sekretem `ADMIN_PASS` —
dopóki właściciel go nie ustawi (i nie zmieni hasła admina), te dwa workflow padają; health nie zależy od niego.
Katalog `data/`, `*.md` i `verify.php` nie są wysyłane na serwer (wykluczenia w deploy.yml).

---

## 6. Stan na dziś i znane sprawy

- Schemat **v43**. QA lokalnie: **157/157** (SQLite i MySQL). Ostatnie wdrożenia (wrzesień 2026, jedna sesja pracy):
  audyt i poprawki krytyczne → nowy model rywalizacji (%) → skarbiec jako pula nagród → paczka poprawek średnich
  (retencja, obrót dzienny, rekoncyliacja, zamknięta ekonomia w QA) → stop-buy → fundusz MAK40 i „vs indeks” →
  fixing → tygodniowy raport i karta wyniku → PWA i push. Każde poszło na `main` z zielonym health checkiem.
- **Czeka na właściciela (nie rób sam):**
  - kliknięcie **Rekoncyliacji rezerwacji** w panelu GM („Zdrowie gry”): produkcja ma blizny sprzed lipcowych
    poprawek wyścigów (jeden gracz z ujemnym `cash_reserved`, dwóch z zamrożoną gotówką bez zleceń). Podgląd pokazuje
    dokładnie, co się zmieni; suma „wolne + zamrożone” gracza zostaje; każdy wiersz trafia do dziennika;
  - zmiana hasła admina (przy `admin123` gra wymusza zmianę po zalogowaniu) i sekret GitHuba `ADMIN_PASS`
    (workflow Raport/Trace);
  - e-mail admina w Koncie (albo `gm_email` w `game_state`) — tam idzie alarm po 2 nieudanych QA z rzędu.
- **Push na produkcji**: klucze VAPID generują się same przy pierwszym użyciu; czy hosting ma `openssl_pkey_derive`,
  `aes-128-gcm` i `curl`, pokaże sekcja „Powiadomienia push” w panelu GM (nie sprawdzaliśmy tego z sandboxa).
  Bez nich powiadomienia w grze działają, push nie.
- E-maile (reset hasła, tygodniowy raport, alarm QA) idą przez PHP `mail()` hostingu; przy porażce pełna treść
  trafia do dziennika (`mail.fallback`), więc nic nie ginie po cichu.
- Retencja: silnik sam sprząta stare zlecenia botów (14 dni) i transakcje bot–bot (30 dni) partiami po 5000 wierszy
  na tick, a po dogonieniu zaległości odpoczywa 60 ticków (`Engine::pruneBotHistory`, kursory `prune_*` w `game_state`).
  Gracze zostają w całości. Świece 20k ticków, newsy ~3 tygodnie, indeks 10k punktów.
- Benchmark „vs MAK40” dla kont sprzed wdrożenia liczy się od najstarszego dostępnego punktu ich historii
  (migracja 41) — nie od rejestracji; nowe konta mają punkt odniesienia z chwili rejestracji.
- Bramka PayU niepodpięta (brak sekretów `PAYU_*`) — sklep pokazuje „wkrótce". To świadome.
- Gra ma na razie kilku graczy — przy priorytetach pamiętaj, że **wzrost i retencja są ważniejsze
  niż kolejna mechanika**. Kanały wzrostu, które już są: link polecający, publiczna karta wyniku, tygodniowy e-mail, push.

### Pomysły zgłoszone, jeszcze nierobione
Krótka sprzedaż (newsy już mówią o graniu na spadki, a gracz może tylko kupować) · odznaka za pobicie indeksu w lidze
miesiąca · alarm GM także przy zerowej liczbie ticków przez X minut (cron padł) · widok „vs MAK40” na profilu gracza ·
ranking sezonowy/roczny na bazie migawek.

---

## 7. Jak pracować z właścicielem

- Pracujesz na branchu `claude/<opis>`, a po testach scalasz do `main` (to wyzwala deploy).
  Po wdrożeniu **zawsze** odpal health check i potwierdź wynik. Większe prace rób **zadanie po zadaniu**:
  jedno zadanie = commit na branchu (po QA na SQLite i MySQL) → merge do `main` z `.healthcheck` → zielony health →
  następne zadanie. Właściciel weryfikuje efekt na żywo, więc każdy krok ma być sam w sobie kompletny.
- Właściciel nie czyta kodu — **pisz podsumowania po ludzku**: co się zmieniło z punktu widzenia gracza,
  co przetestowane, co zostało. Bez żargonu, bez ścian tekstu.
- Zmiany destrukcyjne (reset świata, kasowanie danych graczy, korekty sald na produkcji)
  **tylko po wyraźnej zgodzie**. Reszta — rób i raportuj.
- Gdy coś jest podejrzane, sprawdź to w kodzie, zanim uznasz za błąd. Duża część „błędów"
  to celowe decyzje projektowe opisane w komentarzach.
