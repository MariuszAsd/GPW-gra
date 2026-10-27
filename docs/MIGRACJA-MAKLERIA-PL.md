# Migracja gry na makleria.pl — instrukcja dla nowego konta Claude

Stan na 27.10.2026. **Dzisiejszy adres gry: https://gra.mppp.com.pl/public**
Docelowo: **https://makleria.pl** (bez `/public`, jeśli ustawimy document root — patrz krok 4).

Migracja **nie przenosi danych**. Baza MySQL zostaje ta sama, więc gracze, portfele, ranking i historia
zostają bez zmian. Zmienia się tylko: gdzie leżą pliki, pod jakim adresem gra odpowiada, skąd chodzi cron.

---

## Kto co robi

| Ty (właściciel) | Claude (nowe konto) |
|---|---|
| podpinasz repo do nowego konta Claude (część A) | prowadzi Cię krok po kroku i sprawdza każdy etap przez GitHub Actions |
| DNS, DirectAdmin, SSL, konto FTP, cron, DKIM | nie ma dostępu do serwera ani do domeny — tylko do repozytorium |
| sekrety w GitHubie (`APP_URL`, `FTP_*`) | po przełączeniu: zmienia domyślny adres w kodzie i w workflow, aktualizuje dokumentację |
| | uruchamia deploy i health check, czyta wyniki, mówi, czy wszystko gra |

Claude **nigdy nie dostaje haseł na czacie**. Hasła wpisujesz tylko w DirectAdmin i w sekretach GitHuba.

---

## A. Podpięcie nowego konta Claude (10 minut)

1. Zaloguj się na nowe konto i wejdź na **claude.ai/code**.
2. Podłącz GitHub: **to samo konto `MariuszAsd`** (najprościej). Jeśli nowe konto Claude ma używać innego
   GitHuba, najpierw dodaj je w `github.com/MariuszAsd/GPW-gra` → Settings → Collaborators z prawem **Write**.
3. Daj dostęp do repozytorium **GPW-gra** i utwórz sesję na gałęzi `main`.
4. Wklej pierwszą wiadomość z części C.

Sekretów nie przenosisz — siedzą w ustawieniach repozytorium i działają niezależnie od konta Claude.

---

## B0. Wariant zalecany: nowa domena, te same pliki (najprostszy)

Kod gry nie zależy od nazwy domeny. Można więc podpiąć `makleria.pl` pod **ten sam katalog**, w którym gra
leży dziś. Wtedy nie zmieniasz FTP ani crona — tylko adres. Robi to administrator VPS w DirectAdmin:

| # | Kto | Co |
|---|---|---|
| 1 | admin | DNS: rekord `A` dla `makleria.pl` i `www.makleria.pl` → IP VPS-a |
| 2 | admin | DirectAdmin → Domain Setup → Add New: `makleria.pl` z SSL; potem Let's Encrypt dla `makleria.pl` + `www` i „Force SSL” |
| 3 | admin | Admin Level → Custom HTTPD Configurations → `makleria.pl` → w górnym polu: `\|?DOCROOT=<pełna ścieżka do katalogu public obecnej gry>\|` (tę samą ścieżkę ma dziś `gra.mppp.com.pl`; widać ją w jego ustawieniach albo w ścieżce crona — to katalog nad `cron/` + `/public`) |
| 4 | Ty | sekret GitHub `APP_URL` = `https://makleria.pl` (bez `/public`, bo document root wskazuje już na `public`) |
| 5 | Claude | deploy (zbuduje `config.local.php` z nowym adresem), health check na nowym adresie |
| 6 | Ty | logujesz się na `https://makleria.pl` — te same konto i portfel |
| 7 | admin | Site Redirection: `gra.mppp.com.pl` → `https://makleria.pl/` (301); DKIM i SPF dla `makleria.pl` |
| 8 | Claude | podmienia domyślny adres w `config.php`, w workflow i w dokumentacji |

FTP, cron i baza zostają nietknięte, więc nie ma ryzyka dwóch cronów. Odwrót: usuń sekret `APP_URL`
i przekierowanie. Wariant z przenosinami plików (niżej) ma sens tylko wtedy, gdy chcesz mieć grę
w osobnym katalogu domeny `makleria.pl`.

**Nie używaj „Change Domain” w DirectAdmin**, jeśli `gra.mppp.com.pl` jest subdomeną `mppp.com.pl`:
zmiana nazwy dotyczy całej domeny `mppp.com.pl` i wszystkich stron na niej.

## B. Wariant z przenosinami plików — przebieg migracji (Ty klikasz, Claude sprawdza)

Kolejność ma znaczenie: **stary adres działa do samego końca**, gracze nie widzą przerwy.

| # | Kto | Co | Jak sprawdzić |
|---|---|---|---|
| 1 | Ty | DNS u rejestratora: rekord `A` dla `makleria.pl` i `www.makleria.pl` → IP VPS-a | `ping makleria.pl` pokazuje IP VPS-a (do kilku godzin) |
| 2 | Ty | DirectAdmin → Domain Setup → Add New: `makleria.pl`, zaznacz SSL | powstaje katalog `domains/makleria.pl/public_html` |
| 3 | Ty | SSL Certificates → Let's Encrypt (`makleria.pl` + `www`); w Domain Setup „Force SSL with https redirect” | `https://makleria.pl` otwiera się z kłódką |
| 4 | Ty | **Zalecane:** document root na `public/` (Admin → Custom HTTPD Configurations → `makleria.pl`, w górnym polu `\|?DOCROOT=/home/USER/domains/makleria.pl/public_html/public\|`) | adres gry bez `/public`, silnik niedostępny z internetu |
| 5 | Ty | Konto FTP z katalogiem domowym `domains/makleria.pl/public_html` | logowanie FTP działa |
| 6 | Ty | Sekrety GitHub (Settings → Secrets and variables → Actions): `FTP_SERVER_DIR` = `./` (gdy FTP ląduje w nowym katalogu), w razie potrzeby nowe `FTP_USERNAME`/`FTP_PASSWORD`; **`APP_URL`** = `https://makleria.pl` (albo `https://makleria.pl/public`, jeśli pominąłeś krok 4) | — |
| 7 | Claude | uruchamia deploy na nowy katalog (Actions → Deploy (FTP) → Run workflow albo pusty commit wyzwalający) | deploy zielony; pierwszy wysyła wszystkie pliki i buduje `config.local.php` z sekretów bazy |
| 8 | Claude | health check na nowy adres (`.healthcheck`, workflow sam czyta `APP_URL`) | „schemat v44, push: dostepny, strefa: Europe/Warsaw” |
| 9 | Ty | logujesz się na nowym adresie, oglądasz Pulpit i Rynek | konto i portfel są te same co na starym adresie |
| 10 | Ty | **Cron**: DirectAdmin → Cron Jobs → zmień ścieżkę na `php /home/USER/domains/makleria.pl/public_html/cron/tick.php 1`. **Ma zostać JEDEN cron** | Claude porównuje licznik ticków w health checku z upływem minut |
| 11 | Ty | Site Redirection: `gra.mppp.com.pl` → `https://makleria.pl/` (301) | stare linki (karta wyniku, polecenia, e-maile) trafiają do gry |
| 12 | Ty | E-mail: DKIM dla `makleria.pl` (E-mail Accounts → DKIM) i rekord SPF | e-maile z gry (`no-reply@makleria.pl`) nie lądują w spamie |
| 13 | Claude | sprząta w kodzie: domyślny `app_url` w `config.php`, fallbacki adresu w `.github/workflows/*.yml`, `CLAUDE.md`, `DEPLOY.md`, ta instrukcja | health check zielony po wdrożeniu |
| 14 | Ty | po tygodniu spokoju: kasujesz stary katalog plików | — |

**Dwa crony naraz = świat w podwójnym tempie** (dwa ticki na minutę). Dlatego w kroku 10 zmieniasz
ścieżkę istniejącego crona, a nie dodajesz drugiego.

**Co zobaczą gracze:** logują się od nowa (sesja jest przypięta do domeny), aplikację na telefonie
instalują ponownie z nowego adresu, powiadomienia push włączają ponownie. Wyniki i portfele zostają.

**Odwrót, gdyby coś poszło źle:** przywracasz poprzednią wartość `FTP_SERVER_DIR`, usuwasz sekret `APP_URL`
(albo wpisujesz stary adres) i cofasz ścieżkę crona. Baza jest jedna, więc nic się nie rozjeżdża.

---

## C. Pierwsza wiadomość do nowego Claude — wklej to

> Pracujemy nad moją grą giełdową Makleria (repo GPW-gra). Dziś działa pod https://gra.mppp.com.pl/public,
> a chcę ją przenieść na domenę **makleria.pl** (mój VPS z DirectAdmin, ta sama baza MySQL).
>
> Najpierw przeczytaj `CLAUDE.md` (instrukcja projektu), `docs/MIGRACJA-MAKLERIA-PL.md` (plan migracji)
> i `DEPLOY.md` sekcja 10. Potem streść mi własnymi słowami: czym jest gra, jaki jest stan na dziś,
> i jak wygląda plan migracji — co robię ja, a co Ty. Nic jeszcze nie zmieniaj.
>
> Zasady: pisz po polsku i po ludzku, bo nie czytam kodu. Prowadź mnie przez migrację krok po kroku
> według wariantu B0 (nowa domena, te same pliki), a jeśli się nie da — według części B; po każdym moim kroku sprawdź, co się da, przez GitHub Actions (nie masz
> dostępu do domeny ani do serwera). Haseł nie podaję na czacie — są w sekretach GitHuba.
> Pracuj na branchu `claude/<opis>`, po testach scalaj do `main` (to jest deploy). Przed każdą zmianą kodu
> pełny test: `php cron/qa_probe.php` musi dać 169/169 na SQLite i na MySQL, a po wdrożeniu zielony
> health check. Nic destrukcyjnego (reset świata, zmiany sald, kasowanie danych) bez mojej wyraźnej zgody.

---

## D. Pytania kontrolne (czy nowy Claude ma kontekst)

| Pytanie | Powinien odpowiedzieć |
|---|---|
| „Czy przy migracji przenosimy bazę?” | Nie, baza zostaje ta sama; w wariancie zalecanym zmienia się tylko sekret `APP_URL` i ustawienia domeny w DirectAdmin |
| „Jak uruchomisz grę u siebie do testów?” | Według `CLAUDE.md` §4: `php migrate.php && php seed.php`, ticki, `php -S` i QA-bot 169/169; MySQL na MariaDB w piaskownicy |
| „Co jeśli zostawię dwa crony?” | Świat pójdzie w podwójnym tempie; ma zostać jeden cron |
| „Jak sprawdzisz nowy adres?” | Health checkiem przez GitHub Actions (nadpisanie `.healthcheck`), bo sam nie ma dostępu do domeny |
| „Ile asercji ma QA?” | 169, lokalnie 169/169 na SQLite i MySQL |

---

## E. Sprawy otwarte, które warto załatwić przy okazji

- **Hasło admina** (dziś domyślne) i ten sam ciąg jako sekret `ADMIN_PASS` — bez tego nie działa raport
  stanu gry i trace gracza.
- **Hasło konta `gracz`** na produkcji jest publiczne (`haslo123`) i konto gra w ligach o nagrody.
- **Rekoncyliacja rezerwacji** w panelu GM („Zdrowie gry”) — jedno kliknięcie, bezpieczne po poprawkach z 11.10.
- **E-mail admina** w Koncie — tam idzie alarm, gdy QA dwa razy z rzędu zgłosi błąd.
