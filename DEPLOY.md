# Wdrożenie na hosting (cPanel/DirectAdmin + FTP + MySQL)

Deploy jest automatyczny: **push do `main` → GitHub Actions wysyła pliki na serwer przez FTPS**.
Poniżej jednorazowa konfiguracja. Rób po kolei.

---

## 1. Baza danych MySQL
W panelu (MySQL Management / Bazy danych):
1. Utwórz bazę, np. `gpw`.
2. Utwórz użytkownika + hasło i przypisz go do bazy z pełnymi uprawnieniami.
3. Zapisz: **nazwa bazy, użytkownik, hasło, host** (na shared hostingu host to zwykle `localhost`).

## 2. Folder aplikacji + adres (docroot = `public`)
Zalecane i bezpieczne: **subdomena z document rootem wskazującym na `public/`**.
1. Utwórz subdomenę, np. `gpw.twojadomena.pl`.
2. Jako **Document Root** ustaw folder z `/public` na końcu, np. `domains/twojadomena.pl/gpw/public`.
   Dzięki temu kod silnika (`src/`, `config.local.php`, `cron/`) leży **nad** folderem publicznym i jest niedostępny z internetu.

> Wariant B (bez subdomeny): wdroż do `public_html/gpw/`, wejście przez `https://twojadomena.pl/gpw/public/`.
> Działa, ale mniej czysto — wtedy warto dołożyć `.htaccess` blokujący dostęp do `src/`, `cron/`, `config*.php`.

## 3. Konto FTP
Utwórz konto FTP, najlepiej z katalogiem domowym = folder aplikacji (np. `.../gpw/`).
Zapisz: **host FTP, użytkownik, hasło**.

## 4. Sekrety w GitHub
Repo → **Settings → Secrets and variables → Actions → New repository secret** — dodaj:

| Nazwa | Wartość |
|---|---|
| `FTP_SERVER` | host FTP, np. `ftp.twojadomena.pl` |
| `FTP_USERNAME` | użytkownik FTP |
| `FTP_PASSWORD` | hasło FTP |
| `FTP_SERVER_DIR` | folder docelowy zakończony `/`. Jeśli konto FTP ląduje w `gpw/` → `./`. Jeśli wyżej → np. `./gpw/` |
| `DB_HOST` | host bazy (zwykle `localhost`) |
| `DB_NAME` | nazwa bazy z kroku 1 |
| `DB_USER` | użytkownik bazy |
| `DB_PASS` | hasło do bazy |
| `APP_URL` | publiczny adres gry bez ukośnika na końcu, np. `https://makleria.pl` (albo `https://twojadomena.pl/gpw/public` w wariancie B). Trafia do `config.local.php` (linki w e-mailach, link polecający, karta wyniku, PayU) i do workflow health/raport/trace |

> Haseł **nie** wpisuj do kodu ani nie wysyłaj na czacie — tylko tutaj, w sekretach.
> Dane bazy (`DB_*`) workflow sam zamienia w plik `config.local.php` na serwerze — nic nie edytujesz ręcznie.

## 5. Pierwszy deploy
Repo → **Actions → „Deploy (FTP)" → Run workflow** (albo dowolny push do `main`).
Po zielonym ✔ pliki są na serwerze.

## 6. Hasło do bazy — automatycznie
Nic nie robisz ręcznie. Skoro dane bazy są w sekretach `DB_*`, workflow przy deployu
buduje z nich `config.local.php` i wgrywa na serwer (hasło jest maskowane w logach).
Zmiana danych bazy = zmiana sekretu i ponowny deploy.

## 7. Załóż tabele i dane (raz)
**Najprościej — instalator webowy (bez SSH):** wejdź na adres instalatora i kliknij „Zainstaluj teraz".
- jeśli adres wskazuje na katalog aplikacji (np. `public_html`): **`https://twoj-adres/public/install.php`**
- jeśli docroot ustawiłeś na `.../public`: **`https://twoj-adres/install.php`**

Zakłada tabele i wypełnia świat. Jest bezpieczny — odmawia działania, jeśli baza jest już
założona (nie skasuje danych), a `migrate.php`/`seed.php` są zablokowane przez przeglądarkę (403).
**Po instalacji usuń plik `public/install.php`.**

Alternatywnie przez SSH w folderze aplikacji: `php migrate.php && php seed.php`.

## 8. Puls rynku (cron)
cPanel/DirectAdmin → **Cron Jobs** → co minutę:
```
* * * * * php /home/USER/.../gpw/cron/tick.php 1
```
To animuje rynek (boty, kojarzenie zleceń, świece). Bez tego kursy stoją.

## 9. Gotowe
Wejdź na `https://gpw.twojadomena.pl` — login **gracz / haslo123**.
Po testach zmień/usuń konto demo i hasła.

---

## 10. Zmiana domeny (np. z `gra.mppp.com.pl` na `makleria.pl`)
Baza danych zostaje **ta sama** (te same sekrety `DB_*`, ci sami gracze). Zmieniają się tylko: katalog plików,
adres w sekretach i cron. Rób po kolei — stary adres działa do samego końca.

1. **DNS** (u rejestratora domeny): rekord `A` dla `makleria.pl` i `www.makleria.pl` → IP VPS-a
   (albo delegacja NS na serwer DirectAdmin, jeśli strefa DNS ma być tam). Czekaj, aż `ping makleria.pl` pokaże IP VPS-a.
2. **DirectAdmin → Domain Setup → Add New**: `makleria.pl`, zaznacz SSL. Powstaje `/home/USER/domains/makleria.pl/public_html`.
3. **SSL Certificates → Let's Encrypt** (`makleria.pl` + `www`) → Save. W Domain Setup włącz „Force SSL with https redirect”.
4. **(zalecane) Document root na `public/`** — Admin Level → Custom HTTPD Configurations → `makleria.pl` → w górnym polu wpisz
   `|?DOCROOT=/home/USER/domains/makleria.pl/public_html/public|` → Save. Adres gry to wtedy `https://makleria.pl/`
   (bez `/public`), a silnik (`src/`, `cron/`, `config.local.php`) leży poza zasięgiem internetu.
   Bez tego kroku adres to `https://makleria.pl/public/` — też działa (root `index.php` przekierowuje).
5. **Konto FTP** z katalogiem domowym `domains/makleria.pl/public_html` (FTP Management → Create) — albo istniejące, jeśli sięga wyżej.
6. **Sekrety GitHub**: `FTP_SERVER_DIR` → nowy katalog (`./`, gdy konto FTP ląduje w nim), w razie potrzeby `FTP_USERNAME`/`FTP_PASSWORD`;
   `APP_URL` → `https://makleria.pl` (albo `https://makleria.pl/public`, gdy pominąłeś krok 4).
7. **Actions → „Deploy (FTP)” → Run workflow.** Pierwszy deploy do nowego katalogu wysyła wszystkie pliki i buduje tam `config.local.php`.
8. Wejdź na nowy adres, zaloguj się, sprawdź Pulpit i Rynek (kursy mogą stać do przepięcia crona — to normalne).
9. **Cron**: DirectAdmin → Cron Jobs → zmień ścieżkę na `php /home/USER/domains/makleria.pl/public_html/cron/tick.php 1`.
   **Ma zostać jeden cron.** Dwa (stary i nowy katalog) nie widzą swoich blokad `tick.lock` i podwajają tempo świata.
10. **Przekierowanie starego adresu**: Site Redirection dla `gra.mppp.com.pl` → `https://makleria.pl/` (301).
    Stare linki (karta wyniku, link polecający, e-maile) dalej trafiają do gry.
11. **E-mail z nowej domeny**: włącz DKIM dla `makleria.pl` (E-mail Accounts → DKIM) i sprawdź rekord SPF —
    nadawcą e-maili z gry jest `no-reply@makleria.pl`, bez tego lądują w spamie.
12. **Health check**: nadpisz `.healthcheck` i wypchnij na `main` (workflow sam używa sekretu `APP_URL`). Stary katalog można potem skasować.

Dla graczy: logują się od nowa (sesja jest przypięta do domeny), zainstalowaną aplikację (PWA) instalują ponownie
z nowego adresu, powiadomienia push włączają ponownie. Danych ani wyników nikt nie traci.
