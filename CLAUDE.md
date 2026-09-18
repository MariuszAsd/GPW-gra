# Makleria (GPW-gra) — instrukcja dla Claude

Ten plik czytasz automatycznie przy starcie. Zawiera wszystko, czego potrzebujesz,
żeby pracować nad tą grą bez psucia produkcji. **Przeczytaj do końca przed pierwszą zmianą.**

Właściciel projektu pisze po polsku — **odpowiadaj po polsku**. Komentarze w kodzie też są po polsku
(pełnymi zdaniami, tłumaczą DLACZEGO, nie CO). Trzymaj ten styl.

---

## 1. Czym jest ta gra

**Makleria** — symulator giełdy w czystym PHP 8 (bez frameworka, bez composera, bez JS-frameworka).
Gra działa na żywo pod **https://gra.mppp.com.pl/public** i mają ją prawdziwi gracze.

Gracz dostaje 100 000 PLN i ma dojść do miliona w limicie sesji. Handluje akcjami 76+ spółek
na wspólnym arkuszu zleceń razem ze ~100 botami. Świat żyje sam: boty kwotują, spółki publikują
raporty i dywidendy, newsroom generuje wiadomości, zdarzają się krachy, hossy, IPO i zawieszenia notowań.

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
lokaty bankowe · fundusz indeksowy MAK40 i „czy pobiłeś indeks?” (Pulpit/Ranking: vs MAK40) · odznaki, misje dnia, seria logowań · tygodniowy raport e-mail i publiczna karta wyniku (Weekly) · tokeny inwestora (premium, PayU) · czat i fora spółek ·
ranking, profile graczy, polecenia i obserwowani gracze · panel GM · dziennik logów.

---

## 2. Architektura — mapa plików

```
config.php            jedna konfiguracja (env → config.local.php → domyślne SQLite)
migrate.php           tworzy/aktualizuje schemat            seed.php  zasiewa świat
verify.php            testy integralności (gotówka/akcje)
cron/tick.php         puls rynku (blokada pliku cron/tick.lock)
cron/qa_probe.php     QA-bot: gra przez HTTP jak gracz, 150 asercji
src/                  logika (patrz niżej)
public/               warstwa web — każda strona to jeden plik PHP
.github/workflows/    deploy, health, raport, trace, reinstall
```

### `src/` — co gdzie mieszka
| Plik | Odpowiada za |
|---|---|
| `Db.php` | jedno połączenie PDO, `Db::now()`, `Db::driver()` |
| `Schema.php` | **jedno źródło prawdy** dla schematu + `const VERSION` |
| `Migrator.php` | migracje przyrostowe; `Migrator::ensure()` woła się sam z `_boot.php` i `tick.php` |
| `Engine.php` | **serce (2200 linii)**: escrow, kojarzenie zleceń, boty, świece, SL/TP, sesje, wydarzenia, cel gry |
| `Challenges.php` | wyzwania: zapisy, start z funduszami gry, rozliczenie, pula nagród |
| `Ipo.php` | debiuty giełdowe: oferta, zapisy, redukcja, przydział |
| `Newsroom.php` + `EventCatalog.php` | generowanie newsów i wydarzeń rynkowych |
| `Technical.php` | wskaźniki AT i zbiorczy sygnał |
| `Bank.php` | lokaty · `Seasons.php` sezon · `Daily.php` misje · `Achievements.php` odznaki |
| `Tokens.php` | tokeny premium, pakiety, trial, **polecenia** · `Payments.php` PayU |
| `Recommendations.php` | rekomendacje DM · `Moderation.php` filtr słów · `Mailer.php`, `PasswordReset.php` |
| `Qa.php` | definicje 150 asercji QA-bota (w tym `inv.money` — suma pieniądza w świecie) |
| `Weekly.php` | „Twój tydzień w Maklerii”: podsumowanie po zamknięciu tygodnia (powiadomienie + e-mail) i publiczna karta wyniku `karta.php?u=TOKEN` z linkiem polecającym |
| `Fund.php` | fundusz indeksowy MAK40: jednostki = indeks/10, pula `game_state.fund_pool` w świecie, zysk/stratę rozlicza skarbiec |
| `Reconcile.php` | rekoncyliacja rezerwacji (panel GM): podgląd rozjazdów escrow + korekta na kliknięcie, nigdy sama |

`public/_boot.php` — wspólny bootstrap każdej strony: sesja, `require_login()`, layout, helpery
(`h()`, `money()`, `flash()`, `redirect()`, `explainer()`, `tip()`).

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
   na produkcji przy pierwszym żądaniu po deployu. Aktualnie **wersja 42**.
5. **Sekretów nie commituj.** `config.local.php` i `.env` są w `.gitignore`. Dane bazy produkcyjnej
   żyją w sekretach GitHuba i workflow sam buduje z nich `config.local.php` na serwerze.
6. **Uważaj na polskie znaki w kodzie PHP.** Cudzysłów `"` wewnątrz komentarza SQL w stringu PHP
   rozwala parser — używaj `„ "` typograficznych albo omijaj. Po każdej edycji: `php -l <plik>`.
7. **Nie aliasuj `COUNT(*)` jako `c` w zapytaniach o świece** — kolumna `c` to cena zamknięcia
   i przesłoni alias.
8. **Nowa funkcja = krok w samouczku.** `public/samouczek.php` to jedno miejsce prawdy o tym,
   „jak grać". Dodajesz mechanikę → dopisujesz krok.

---

## 4. Jak testować (rób to ZAWSZE przed commitem)

Lokalnie masz SQLite bez żadnej konfiguracji. Pełny cykl:

```bash
cd /ścieżka/do/repo
php migrate.php && php seed.php          # świeży świat (nadpisuje data/tycoon.sqlite — OK)
php cron/tick.php 100                    # 100 ticków; wpisy logów source='qa' to normalny szum
php -S 127.0.0.1:8123 -t public &        # serwer w tle
APP_URL=http://127.0.0.1:8123 php cron/qa_probe.php
# MUSI wypisać: ✅ QA OK — asercji: 150
```

**Test na MySQL jest obowiązkowy dla zmian dotykających transakcji/wyścigów** (produkcja to MySQL,
a SQLite maskuje wyścigi, bo serializuje zapisy). Jeśli w środowisku nie ma MariaDB:
`apt-get update && apt-get install -y mariadb-server`, potem
`mariadb-install-db --no-defaults --user=root --datadir=<kat>` i uruchom `mariadbd` na sockecie
`/tmp/m.sock`, port 3307. Każde wywołanie PHP potrzebuje wtedy **jednocześnie**
flagi `-d pdo_mysql.default_socket=/tmp/m.sock` i zmiennych `DB_DRIVER=mysql DB_HOST=localhost
DB_NAME=gpw DB_USER=root DB_PASS=`. Docroot ustaw na katalog repo, a strony są pod `/public/`.

**Po każdej zmianie sprawdź niezmienniki** zapytaniami z punktu 3 — nie polegaj wyłącznie na QA.

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

---

## 6. Stan na dziś i znane sprawy

- Schemat **v42**. QA lokalnie: **150/150**.
- Na produkcji QA zgłaszał **3 asercje** escrow: osierocone rezerwacje sprzed lipcowych poprawek wyścigów
  (jeden gracz z ujemnym `cash_reserved`, dwóch z zamrożoną gotówką bez zleceń). To blizna, nie wyciek.
  W panelu GM (sekcja „Zdrowie gry") jest **Rekoncyliacja rezerwacji**: podgląd rozjazdów i przycisk korekty
  (suma „wolne + zamrożone" każdego gracza zostaje, każdy wiersz trafia do dziennika). **Klika tylko właściciel.**
- Retencja: silnik sam sprząta stare zlecenia botów (14 dni) i transakcje bot–bot (30 dni) partiami po 5000
  wierszy co 5 ticków (`Engine::pruneBotHistory`, kursory `prune_*_cursor` w `game_state`). Gracze zostają w całości.
- QA po 2 nieudanych przebiegach z rzędu wysyła e-mail do GM (`gm_email` w `game_state` albo e-mail admina).
- Bramka PayU niepodpięta (brak sekretów `PAYU_*`) — sklep pokazuje „wkrótce". To świadome.
- Gra ma na razie kilku graczy — przy priorytetach pamiętaj, że **wzrost i retencja są ważniejsze
  niż kolejna mechanika**.

### Pomysły zgłoszone, jeszcze nierobione
Krótka sprzedaż (newsy już mówią o graniu na spadki, a gracz może tylko kupować) ·
alarm e-mail do GM przy dwóch nieudanych QA z rzędu · powiadomienia push / PWA ·
ranking miesięczny stopy zwrotu obok celu „pierwszy milion".

---

## 7. Jak pracować z właścicielem

- Pracujesz na branchu `claude/<opis>`, a po testach scalasz do `main` (to wyzwala deploy).
  Po wdrożeniu **zawsze** odpal health check i potwierdź wynik.
- Właściciel nie czyta kodu — **pisz podsumowania po ludzku**: co się zmieniło z punktu widzenia gracza,
  co przetestowane, co zostało. Bez żargonu, bez ścian tekstu.
- Zmiany destrukcyjne (reset świata, kasowanie danych graczy, korekty sald na produkcji)
  **tylko po wyraźnej zgodzie**. Reszta — rób i raportuj.
- Gdy coś jest podejrzane, sprawdź to w kodzie, zanim uznasz za błąd. Duża część „błędów"
  to celowe decyzje projektowe opisane w komentarzach.
