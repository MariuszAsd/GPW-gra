# Przeniesienie prac nad grą do nowego Claude (nowe konto, nowy czat)

Instrukcja dla właściciela projektu. Robisz to raz, zajmuje ~10 minut.
Na końcu jest **gotowa pierwsza wiadomość do wklejenia**.

---

## Najpierw najważniejsze: co tak naprawdę przenosisz

**Nic nie ginie razem ze starym czatem.** Cała wiedza o projekcie siedzi w repozytorium GitHub:

| Co | Gdzie |
|---|---|
| kod gry | `src/`, `public/`, `cron/` |
| instrukcja dla Claude (czyta ją sam przy starcie) | `CLAUDE.md` |
| konfiguracja wdrożenia | `DEPLOY.md`, `.github/workflows/` |
| hasła do serwera i bazy | sekrety GitHuba (w repo, nie w czacie) |
| historia decyzji — po co każda zmiana | opisy commitów (`git log`) |

Nowy Claude podpięty pod repo dostaje to wszystko automatycznie. **Nie musisz mu niczego streszczać.**

---

## Krok 1. Dostęp do repozytorium z nowego konta

Repo `MariuszAsd/GPW-gra` jest prywatne, więc nowe konto Claude musi mieć do niego dostęp.
Wybierz jedną drogę:

**A. Ten sam GitHub (najprostsze, zalecane)**
W nowym koncie Claude podłączasz **to samo konto GitHub** (`MariuszAsd`). Nic więcej nie robisz —
uprawnienia, sekrety i workflow zostają bez zmian.

**B. Inne konto GitHub**
Jeśli nowe konto Claude ma korzystać z innego konta GitHub, wejdź na
`github.com/MariuszAsd/GPW-gra` → **Settings → Collaborators → Add people** i dodaj tamto konto
z uprawnieniem **Write**. Musi przyjąć zaproszenie mailem.

---

## Krok 2. Podpięcie repo w nowym Claude

1. Zaloguj się na nowe konto i wejdź na **claude.ai/code**.
2. **Connect GitHub** (albo Ustawienia → Connectors → GitHub) i autoryzuj.
3. Przy wyborze repozytoriów zaznacz **GPW-gra** (możesz dać dostęp tylko do tego jednego).
4. Utwórz nową sesję/środowisko i wskaż repozytorium `MariuszAsd/GPW-gra`, gałąź `main`.

To wszystko. Claude sklonuje repo i przy starcie sam wczyta `CLAUDE.md`.

---

## Krok 3. Czego NIE musisz robić

- ❌ **Nie przenosisz sekretów.** Sekrety wdrożeniowe (`FTP_*`, `DB_*`, opcjonalnie `APP_URL`,
  `ADMIN_PASS`, `PAYU_*`, `SETUP_TOKEN`) siedzą w ustawieniach repozytorium na GitHubie
  (Settings → Secrets and variables → Actions). Deploy i testy produkcji działają dalej
  niezależnie od tego, z którego konta Claude pracujesz.
- ❌ **Nie kopiujesz starego czatu.** Podsumowanie historii jest w `CLAUDE.md` i w opisach commitów.
- ❌ **Nie zmieniasz niczego na serwerze.** Hosting, cron i baza zostają nietknięte.
- ⚠️ **Nigdy nie wklejaj haseł ani danych FTP/bazy na czacie** — Claude bierze je z sekretów repo.

---

## Krok 4. Pierwsza wiadomość — wklej to

Skopiuj poniższy tekst do pierwszej wiadomości w nowym czacie:

> Pracujemy nad moją grą giełdową Makleria (repo GPW-gra, produkcja: https://gra.mppp.com.pl/public).
> Przeczytaj najpierw `CLAUDE.md` w korzeniu repo — to instrukcja projektu — oraz `git log --oneline -20`,
> żeby poznać ostatnie zmiany. Potem streść mi własnymi słowami: czym jest ta gra, jaki jest stan
> na dziś i jakie sprawy czekają nierozwiązane. Nic jeszcze nie zmieniaj.
>
> Zasady pracy: pisz po polsku i po ludzku (nie czytam kodu). Pracuj na branchu `claude/<opis>`,
> a po testach scalaj do `main` — to uruchamia automatyczny deploy. Przed każdym commitem odpal
> pełny test (`php cron/qa_probe.php` musi dać 134/134), a po wdrożeniu health check przez GitHub Actions.
> Niczego destrukcyjnego (reset świata, zmiany sald graczy) nie rób bez mojej wyraźnej zgody.

Nowy Claude ma po tym wiedzieć: że gra żyje na produkcji, jak ją testować, jak wdrażać
i czego nie ruszać.

---

## Krok 5. Sprawdź, czy naprawdę ma kontekst

Zadaj trzy pytania kontrolne. Jeśli odpowie zgodnie z kolumną „powinien powiedzieć", jest gotowy:

| Pytanie | Powinien powiedzieć |
|---|---|
| „Jak sprawdzisz, czy gra na produkcji działa?" | Nie ma dostępu sieciowego do domeny; sprawdza przez GitHub Actions — nadpisuje plik `.healthcheck` albo `.raport` i czyta wynik workflow |
| „Co się stanie, jak wypchniesz zmianę do `main`?" | Ruszy automatyczny deploy FTP na żywy serwer, a migracje schematu wykonają się same |
| „Ile asercji ma mieć QA i co jest dziś czerwone?" | 134; lokalnie 134/134, na produkcji 3 czerwone przez stare osierocone rezerwacje |

Jeśli któraś odpowiedź jest z sufitu — każ mu przeczytać `CLAUDE.md` jeszcze raz.

---

## Krok 6. Stary czat i konto

Możesz je po prostu zostawić — nic się nie dzieje samo. Jeśli chcesz odciąć stary dostęp,
na GitHubie: **Settings → Applications → Authorized OAuth Apps** (albo Collaborators, gdy szedłeś drogą B)
i usuń tam stare powiązanie. Zrób to dopiero, gdy nowe konto potwierdzi, że widzi repo.

---

## Ściąga: pierwsze zadania dla nowego Claude

Jeśli nie wiesz, od czego zacząć w nowym czacie, są gotowe tematy (opisane w `CLAUDE.md`, sekcja 6):

1. **Rekoncyliacja rezerwacji w panelu GM** — naprawia 3 czerwone asercje QA na produkcji.
2. **Krótka sprzedaż** — największa luka mechaniki; newsy mówią o graniu na spadki, a gracz może tylko kupować.
3. **Alarm o awarii** — e-mail do GM, gdy QA dwa razy z rzędu zgłosi błąd.
4. **Powiadomienia push / PWA** — o realizacji zlecenia, zawieszeniu notowań, przydziale IPO.
