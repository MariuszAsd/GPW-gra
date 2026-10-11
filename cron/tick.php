<?php
/**
 * Puls rynku. Na hostingu wpinasz w cron co minutę: php cron/tick.php 1
 * Lokalne testy: php cron/tick.php [ile_ticków].
 *
 * TRYB PULSU (cron, arg=1): po głównym ticku skrypt zostaje żywy i co ~18 s
 * odpala dodatkowe rundy handlu botów (Engine::subRound) — transakcje pojawiają
 * się co kilkanaście sekund zamiast raz na minutę, a czas świata gry (sesje,
 * raporty, wydarzenia) wciąż płynie w tempie 1 tick/min. Liczba rund:
 * game_state.sub_rounds (0-3, domyślnie 2, sterowane w GM).
 * Wspólna blokada świata (Engine::worldLock) zapobiega nakładaniu się cykli —
 * obejmuje też ręczne ticki z panelu GM.
 */
// TYLKO Z KONSOLI. Bez tej bramki dowolny gość z internetu mógłby kręcić zegarem gry jednym
// żądaniem GET: przewinąć sesje i przepalić wszystkim limit celu, wypłacić lokaty z odsetkami,
// zakończyć wyzwania albo dokręcać ticki, aż zrealizuje się jego własne zlecenie.
// Taką samą bramkę mają migrate.php, seed.php i cron/qa_probe.php.
if (php_sapi_name() !== 'cli') { http_response_code(403); exit('Forbidden'); }

require __DIR__ . '/../src/Db.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Migrator.php';
require __DIR__ . '/../src/Engine.php';
require __DIR__ . '/../src/Log.php';

try { Migrator::ensure(); } catch (Throwable $e) { error_log('Migracja(cron): ' . $e->getMessage()); }

$count = (int) ($argv[1] ?? 1);

// GODZINY HANDLU: poza sesją (np. 22:00-7:50) świat gry stoi — cron wychodzi bez ticka.
if ($count === 1 && !Engine::marketIsOpen()) {
    echo "giełda zamknięta — bez ticka\n";
    exit(0);
}

// JEDNA PĘTLA ŚWIATA NARAZ — ta sama blokada, której używa ręczny tick w panelu GM.
// Stara blokada po dacie pliku puszczała drugi cykl po 120 s i kasowała cudzy plik przy wyjściu;
// dwie pętle naraz rozjeżdżały escrow botów i mogły wypłacić dywidendę dwa razy.
if (!Engine::worldLock()) { fwrite(STDERR, "Poprzedni cykl trwa.\n"); exit(1); }
register_shutdown_function(fn() => Engine::worldUnlock());
$t = 0;
for ($i = 0; $i < $count; $i++) {
    try {
        $t = Engine::runTick();
    } catch (Throwable $e) {
        Log::write('error', 'engine', 'tick.exception', $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
        throw $e;
    }
    if (php_sapi_name() === 'cli') echo "tick #$t ok\n";
}

// WYSYŁKA POZA BLOKADĄ ŚWIATA, nie tylko poza transakcją. Push i e-maile to rozmowy z obcymi serwerami
// (sekundy, czasem timeouty). Dane ticka są już zacommitowane, więc transakcja ich nie trzyma — ale blokada
// świata TAK: gdyby wysyłka szła pod nią, kolejny cron i ręczny tick z panelu GM czekałyby na serwer poczty
// i push, a nie na silnik. Zwalniamy ją więc tutaj, a przed QA i pulsem handlu bierzemy ponownie.
Engine::worldUnlock();
if (php_sapi_name() === 'cli' && $t > 0) {
    // PUSH: dosyłka nowych powiadomień subskrybentom. Lekki, nieblokujący flock na cron/push.lock: bez blokady
    // świata dwa procesy (przeciągający się cron i następny) mogłyby wysyłać te same wpisy naraz — zajęty plik
    // oznacza, że ktoś już wysyła, więc pomijamy (następny cron dośle resztę po kursorze push_last_notif_id).
    $pushLock = @fopen(__DIR__ . '/push.lock', 'c');
    if ($pushLock && flock($pushLock, LOCK_EX | LOCK_NB)) {
        try {
            require_once __DIR__ . '/../src/Push.php';
            [$ps, $pf] = Push::flush();
            if ($ps + $pf > 0) echo "push: $ps wysłanych, $pf błędów\n";
        } catch (Throwable $e) { Log::write('warn', 'engine', 'push.flush', $e->getMessage()); }
        flock($pushLock, LOCK_UN);
    } elseif ($pushLock) {
        echo "push: pomijam — inna wysyłka trwa\n";
    }
    if ($pushLock) fclose($pushLock);

    // TYGODNIOWE RAPORTY „Twój tydzień w Maklerii”: tick zapisał tylko znacznik (Engine::settleLeagues),
    // powiadomienia i e-maile wysyłamy tu — po commicie ticka, bez blokady świata; claim-first w flushPending.
    try {
        require_once __DIR__ . '/../src/Weekly.php';
        [$wn, $wm] = Weekly::flushPending();
        if ($wn + $wm > 0) echo "tydzień: $wn powiadomień, $wm e-maili\n";
    } catch (Throwable $e) { Log::write('warn', 'engine', 'weekly.flush', $e->getMessage()); }
}

// QA i puls handlu znów pod blokadą świata (puls to pełna runda silnika). Jeśli w czasie wysyłki
// wszedł inny cykl (następny cron), ustępujemy — on zrobi swoje.
if (php_sapi_name() === 'cli' && $t > 0 && !Engine::worldLock(10)) { echo "inny cykl przejął świat — bez QA i pulsu handlu\n"; exit(0); }

// QA-bot co N ticków (tylko z CLI/crona — testuje grę przez HTTP jak prawdziwy gracz)
$qaRan = false;
if (php_sapi_name() === 'cli' && $t > 0) {
    $every = max(1, (int) (Engine::one("SELECT v FROM game_state WHERE k='qa_every_ticks'") ?: 30));
    if ($t % $every === 0) {
        $qaRan = true;
        require __DIR__ . '/../src/Qa.php';
        $r = Qa::run();
        echo ($r['ok'] ? "QA OK ({$r['checks']} asercji)" : 'QA BŁĘDY: ' . count($r['fails'])) . "\n";
    }
}

// PULS HANDLU: dodatkowe rundy botów między tickami crona (żywszy rynek).
// Pomijane w minucie QA (żeby nie przekroczyć 60 s przed kolejnym cronem).
if (php_sapi_name() === 'cli' && $count === 1 && $t > 0 && !$qaRan) {
    $subV = Engine::one("SELECT v FROM game_state WHERE k='sub_rounds'");
    $sub = ($subV === false || $subV === null) ? 2 : max(0, min(3, (int) $subV));
    for ($i = 0; $i < $sub; $i++) {
        sleep(18);
        if (!Engine::marketIsOpen()) break;   // zamknięcie w trakcie minuty — koniec pulsu
        try {
            Engine::subRound();
            echo "puls handlu +1\n";
        } catch (Throwable $e) {
            Log::write('error', 'engine', 'subround.exception', $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
        }
    }
}
