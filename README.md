# Tycoon.pl — rdzeń MVP (czysty PHP 8)

Działająca podstawa gry giełdowej: **jeden** schemat, **jeden** silnik, **jeden** dialekt,
realne logowanie, poprawny escrow (nic się nie drukuje), boty tworzące pływający kurs,
arkusz zleceń, wykres (inline SVG), portfel i egzekucja SL/TP.

## Uruchomienie lokalne (SQLite, zero konfiguracji)
```bash
php migrate.php          # tworzy schemat
php seed.php             # gracz demo + 4 spółki + 30 botów
php cron/tick.php 30     # 30 cykli rynku (kurs zaczyna pływać)
php -S 127.0.0.1:8123 -t public
# otwórz http://127.0.0.1:8123  ·  login: gracz / haslo123
php verify.php           # testy integralności (gotówka/akcje) + sparkline kursów
```

## Wdrożenie na hosting (cPanel + MySQL)
1. Utwórz bazę MySQL i użytkownika w cPanel.
2. Ustaw zmienne środowiskowe (lub wpisz w `config.php`):
   `DB_DRIVER=mysql`, `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
   Sekretów nie commituj do repozytorium.
3. Katalog dokumentów domeny ustaw na `public/`.
4. `php migrate.php && php seed.php` (raz).
5. Cron w cPanel: `* * * * * php /sciezka/cron/tick.php 1` (puls rynku co minutę).

## Struktura
- `config.php` — jedna konfiguracja (env, przełącznik SQLite/MySQL)
- `src/Db.php` — jedna klasa połączenia (przenośna)
- `src/Schema.php` — **jedno źródło prawdy** dla schematu (dialekt-świadome)
- `src/Engine.php` — silnik: escrow, kojarzenie, boty, sygnały, świece, SL/TP
- `cron/tick.php` — puls rynku (z blokadą pliku)
- `public/` — warstwa web (logowanie, rynek, spółka, portfel, akcje)

## Co gwarantują testy (`verify.php`)
- suma gotówki w systemie jest **niezmienna** (brak kreacji pieniądza),
- brak ujemnych sald i rezerwacji,
- liczba akcji każdej spółki jest **zachowana**.
