<?php
/**
 * QA-bot: gra jak prawdziwy gracz PRZEZ HTTP (te same ścieżki co ludzie)
 * i po każdej akcji sprawdza księgowość co do grosza. Wyniki pisze do dziennika
 * logów (source='qa'); podsumowanie widać w panelu GM ("Zdrowie gry").
 *
 * Sprawdza m.in.:
 *  - wszystkie strony (200, brak błędów PHP, kluczowa treść),
 *  - logowanie (poprawne działa, błędne hasło NIE wpuszcza),
 *  - zlecenie oczekujące: rezerwacja gotówki co do grosza + zwrot po anulowaniu,
 *  - zakup z realizacją: gotówka spada dokładnie o cenę transakcji, akcje +1,
 *  - sprzedaż: wpływ = wartość MINUS dokładnie prowizja,
 *  - SL/TP: zapis i czyszczenie,
 *  - inwarianty globalne: brak ujemnych sald; rezerwacje gotówki/akcji każdego
 *    użytkownika równe sumie jego aktywnych zleceń (wykrywa wycieki escrow).
 */
final class Qa
{
    private string $base = '';
    private string $jar = '';
    private array $fails = [];
    private int $checks = 0;

    public static function run(?string $baseUrl = null): array
    {
        $cfg = require __DIR__ . '/../config.php';
        $q = new self();
        $q->base = rtrim($baseUrl ?: (string) ($cfg['app_url'] ?? ''), '/');
        if ($q->base === '') return ['ok' => false, 'checks' => 0, 'fails' => ['brak app_url w config']];
        $q->jar = tempnam(sys_get_temp_dir(), 'qa_ck_');
        try { $q->execute((float) $cfg['starting_cash']); }
        catch (Throwable $e) { $q->fail('qa.crash', $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
        @unlink($q->jar);

        $ok = count($q->fails) === 0;
        Log::write($ok ? 'info' : 'error', 'qa', 'qa.run',
            ($ok ? "OK — {$q->checks} asercji" : 'BŁĘDY: ' . count($q->fails) . " z {$q->checks} asercji"),
            $ok ? [] : ['fails' => array_slice($q->fails, 0, 12)]);
        Log::prune();
        return ['ok' => $ok, 'checks' => $q->checks, 'fails' => $q->fails];
    }

    /* ---------- przebieg ---------- */

    private function execute(float $startingCash): void
    {
        $pdo = Db::pdo();

        // 0) konto QA: świeże hasło co przebieg (bez trwałych sekretów), rola 'qa' (poza rankingiem/celem)
        $pass = bin2hex(random_bytes(8));
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $uid = Engine::one("SELECT id FROM users WHERE username='qa_tester'");
        if ($uid) {
            $pdo->prepare("UPDATE users SET password_hash=?, role='qa' WHERE id=?")->execute([$hash, $uid]);
        } else {
            [$s] = Engine::sessionInfo();
            $pdo->prepare("INSERT INTO users (username, password_hash, is_bot, role, cash, joined_session, start_equity) VALUES ('qa_tester', ?, 0, 'qa', 5000, ?, 5000)")
                ->execute([$hash, $s]);
            $uid = (int) $pdo->lastInsertId();
        }
        $uid = (int) $uid;
        // czysty stan: anuluj zlecenia QA (też obronne), dosyp gotówki gdy wydrenowany
        foreach (Engine::all("SELECT id FROM orders WHERE user_id=? AND status IN ('active','pending')", [$uid]) as $o) Engine::cancel((int) $o['id'], $uid);
        if ((float) Engine::one("SELECT cash FROM users WHERE id=?", [$uid]) < 2000) {
            $pdo->prepare("UPDATE users SET cash=cash+5000, start_equity=start_equity+5000 WHERE id=?")->execute([$uid]);
            Log::write('info', 'qa', 'qa.topup', 'dosypano 5000 PLN kontu qa_tester');
        }

        // 1) logowanie: złe hasło NIE wpuszcza; dobre wpuszcza
        [$code, $body] = $this->http('GET', '/login.php');
        $this->check($code === 200 && str_contains($body, 'Zaloguj'), 'page.login', "login.php code=$code");
        $this->http('POST', '/login.php', ['username' => 'qa_tester', 'password' => 'zle-haslo-123']);
        [, $b2] = $this->http('GET', '/market.php');
        $this->check(str_contains($b2, 'Zaloguj'), 'auth.wrong_pass', 'złe hasło nie może wpuścić do gry');
        [, $b3] = $this->http('POST', '/login.php', ['username' => 'qa_tester', 'password' => $pass]);
        $this->check(str_contains($b3, 'Rynek'), 'auth.login', 'poprawne logowanie nie działa');

        // 2) przegląd stron (200 + treść + brak błędów PHP)
        $stock = Engine::row("SELECT id, price FROM stocks ORDER BY price ASC LIMIT 1");   // najtańsza spółka do testów
        $sid = (int) $stock['id'];
        foreach ([['/pulpit.php', 'Pulpit'], ['/samouczek.php', 'Samouczek'], ['/market.php', 'Rynek'], ['/ranking.php', 'Ranking'], ['/portfolio.php', 'Portfel'], ['/pomoc.php', 'Stop-Loss'], ['/wiadomosci.php', 'Kalendarz'], ['/wyzwania.php', 'Wyzwania'], ['/dziennik.php', 'Dziennik'], ['/sklep.php', 'Pakiet Analityka'], ["/stock.php?id=$sid", 'Zlecenie']] as [$path, $needle]) {
            [$c, $b] = $this->http('GET', $path);
            $this->check($c === 200 && str_contains($b, $needle), "page.$path", "code=$c, brak '$needle'");
            $this->check(!preg_match('/Fatal error|Parse error|Uncaught|Warning:/', $b), "php.$path", 'strona zawiera błąd PHP');
        }
        [$c, $b] = $this->http('GET', "/stock.php?id=$sid");
        $this->check($c === 200 && str_contains($b, 'Analiza techniczna'), 'page.ta', "brak zakładki Analiza techniczna (code=$c)");
        [$c, $b] = $this->http('GET', "/api_chart.php?id=$sid&iv=5");
        $this->check($c === 200 && str_contains($b, '"ok":true'), 'page.api_chart', "api_chart: code=$c lub brak ok:true");
        [$c, $b] = $this->http('GET', '/powiadomienia.php');
        $this->check($c === 200 && str_contains($b, 'Powiadomienia'), 'page.notifications', "powiadomienia.php: code=$c");
        [$c, $b] = $this->http('GET', '/api_notifications.php');
        $this->check($c === 200 && str_contains($b, '"ok":true'), 'page.api_notifications', "api_notifications: code=$c lub brak ok:true");
        // czat: wpis pojawia się w odczycie, drugi natychmiastowy wpis odbija się o anty-spam
        $probe = 'qa-czat-' . mt_rand(1000, 9999);
        [$c, $b] = $this->http('POST', '/api_chat.php', ['msg' => $probe]);
        $this->check($c === 200 && str_contains($b, '"ok":true'), 'chat.post', "wpis na czacie nie przeszedł (code=$c)");
        [$c, $b] = $this->http('GET', '/api_chat.php?since=0');
        $this->check($c === 200 && str_contains($b, $probe), 'chat.read', 'wpis nie pojawił się w odczycie czatu');
        [, $b] = $this->http('POST', '/api_chat.php', ['msg' => $probe . '-2']);
        $this->check(!str_contains($b, '"ok":true'), 'chat.ratelimit', 'anty-spam nie zadziałał (2 wpisy pod rząd przeszły)');
        // profil gracza (własny profil QA)
        [$c, $b] = $this->http('GET', '/gracz.php?id=' . $uid);
        $this->check($c === 200 && str_contains($b, 'Odznaki'), 'page.profile', "gracz.php: code=$c lub brak odznak");

        // 3) zlecenie oczekujące: rezerwacja i zwrot CO DO GROSZA
        $deep = max(0.01, round((float) $stock['price'] * 0.1, 2));   // 10% kursu — nie wypełni się
        $cash0 = $this->cash($uid);
        $this->http('POST', '/place_order.php', ['stock_id' => $sid, 'side' => 'buy', 'qty' => 2, 'price' => number_format($deep, 2, '.', ''), 'sl_price' => '', 'tp_price' => '']);
        $order = Engine::row("SELECT id, qty, price FROM orders WHERE user_id=? AND status='active' ORDER BY id DESC LIMIT 1", [$uid]);
        $this->check($order !== null, 'order.resting', 'zlecenie oczekujące nie powstało');
        if ($order) {
            $expect = round(2 * $deep, 2);
            $this->moneyEq($cash0 - $this->cash($uid), $expect, 'escrow.reserve', 'rezerwacja gotówki ≠ wartość zlecenia');
            $this->http('POST', '/cancel_order.php', ['order_id' => (int) $order['id']]);
            $this->moneyEq($this->cash($uid), $cash0, 'escrow.refund', 'po anulowaniu gotówka nie wróciła co do grosza');
            // szczegóły zlecenia (oś czasu) działają
            [$c, $b] = $this->http('GET', '/order.php?id=' . (int) $order['id']);
            $this->check($c === 200 && str_contains($b, 'Oś czasu'), 'page.order_detail', "order.php: code=$c lub brak osi czasu");
        }

        // 4) zakup z realizacją: cena transakcji zdjęta z gotówki dokładnie
        $ask = Engine::one("SELECT MIN(price) FROM orders WHERE stock_id=? AND side='sell' AND status='active' AND user_id<>?", [$sid, $uid]);
        if ($ask) {
            $qty0 = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]) ?: 0);
            $cash1 = $this->cash($uid);
            $this->http('POST', '/place_order.php', ['stock_id' => $sid, 'side' => 'buy', 'qty' => 1, 'price' => number_format(round((float) $ask * 1.02, 2), 2, '.', ''), 'sl_price' => '', 'tp_price' => '']);
            $tx = Engine::row("SELECT id, price FROM transactions WHERE buyer_id=? AND stock_id=? ORDER BY id DESC LIMIT 1", [$uid, $sid]);
            $this->check($tx !== null, 'trade.buy_fill', 'zakup po cenie ask nie zrealizował się');
            if ($tx) {
                $this->moneyEq($cash1 - $this->cash($uid), (float) $tx['price'], 'trade.buy_cash', 'gotówka nie spadła dokładnie o cenę transakcji');
                $qty1 = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]) ?: 0);
                $this->check($qty1 === $qty0 + 1, 'trade.buy_qty', "akcje: oczekiwano " . ($qty0 + 1) . ", jest $qty1");

                // 5) sprzedaż: wpływ = wartość MINUS dokładnie prowizja
                $bid = Engine::one("SELECT MAX(price) FROM orders WHERE stock_id=? AND side='buy' AND status='active' AND user_id<>?", [$sid, $uid]);
                if ($bid) {
                    $cash2 = $this->cash($uid);
                    $this->http('POST', '/place_order.php', ['stock_id' => $sid, 'side' => 'sell', 'qty' => 1, 'price' => number_format(round((float) $bid * 0.98, 2), 2, '.', ''), 'sl_price' => '', 'tp_price' => '']);
                    $tx2 = Engine::row("SELECT price FROM transactions WHERE seller_id=? AND stock_id=? ORDER BY id DESC LIMIT 1", [$uid, $sid]);
                    $this->check($tx2 !== null, 'trade.sell_fill', 'sprzedaż po cenie bid nie zrealizowała się');
                    if ($tx2) {
                        $val = (float) $tx2['price'];
                        $expectIn = round($val - round($val * Engine::feeRate(), 2), 2);
                        $this->moneyEq($this->cash($uid) - $cash2, $expectIn, 'trade.sell_fee', "wpływ ze sprzedaży ≠ wartość minus prowizja (oczekiwano $expectIn)");
                    }
                }
            }
        } else {
            Log::write('warn', 'qa', 'qa.skip', 'brak ofert sprzedaży — pominięto test realizacji');
        }

        // 5b) PKC ("po każdej cenie"): kupno natychmiast z arkusza — gotówka spada DOKŁADNIE
        //     o sumę wartości transakcji (escrow po najgorszej cenie + zwroty muszą się domknąć)
        if (Engine::one("SELECT COUNT(*) FROM orders WHERE stock_id=? AND side='sell' AND status='active' AND user_id<>?", [$sid, $uid])) {
            $txMax = (int) (Engine::one("SELECT COALESCE(MAX(id),0) FROM transactions") ?: 0);
            $qtyB4 = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]) ?: 0);
            $restB4 = (int) Engine::one("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='active'", [$uid]);
            $cashB4 = $this->cash($uid);
            $this->http('POST', '/place_order.php', ['stock_id' => $sid, 'side' => 'buy', 'type' => 'market', 'qty' => 2, 'price' => '', 'sl_price' => '', 'tp_price' => '']);
            $fills = Engine::all("SELECT qty, price FROM transactions WHERE buyer_id=? AND stock_id=? AND id>?", [$uid, $sid, $txMax]);
            $this->check(count($fills) > 0, 'pkc.buy_fill', 'PKC kupno nie zrealizowało żadnej transakcji');
            if ($fills) {
                $paid = 0.0; $got = 0;
                foreach ($fills as $f) { $paid += round($f['qty'] * $f['price'], 2); $got += (int) $f['qty']; }
                $this->moneyEq($cashB4 - $this->cash($uid), round($paid, 2), 'pkc.buy_cash', 'PKC: gotówka nie spadła dokładnie o sumę transakcji');
                $qtyAf = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]) ?: 0);
                $this->check($qtyAf === $qtyB4 + $got, 'pkc.buy_qty', "PKC: akcje oczekiwano " . ($qtyB4 + $got) . ", jest $qtyAf");
                // brak wiszącej reszty: PKC nie może zostawić aktywnego zlecenia
                $rest = (int) Engine::one("SELECT COUNT(*) FROM orders WHERE user_id=? AND status='active'", [$uid]);
                $this->check($rest <= $restB4, 'pkc.no_resting', "PKC zostawiło aktywne zlecenie (przed: $restB4, po: $rest)");

                // 5c) zlecenie obronne (SL/TP): rezerwacja pakietu, widoczność, anulowanie ze zwrotem
                $freeB4 = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]) ?: 0);
                $this->http('POST', '/set_sltp.php', ['stock_id' => $sid, 'qty' => 1, 'sl_price' => '0.50', 'tp_price' => '99999']);
                $stop = Engine::row("SELECT * FROM orders WHERE user_id=? AND stock_id=? AND status='pending' ORDER BY id DESC LIMIT 1", [$uid, $sid]);
                $this->check($stop !== null && (float) $stop['sl_price'] === 0.5 && (float) $stop['tp_price'] === 99999.0 && (int) $stop['qty'] === 1,
                    'stop.create', 'zlecenie obronne nie powstało lub ma złe progi');
                if ($stop) {
                    $freeA = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]) ?: 0);
                    $this->check($freeA === $freeB4 - 1, 'stop.reserve', "obronne nie zarezerwowało pakietu (wolne: $freeB4 -> $freeA)");
                    $this->http('POST', '/cancel_order.php', ['order_id' => (int) $stop['id']]);
                    $freeC = (int) (Engine::one("SELECT qty FROM wallets WHERE user_id=? AND stock_id=?", [$uid, $sid]) ?: 0);
                    $st = Engine::one("SELECT status FROM orders WHERE id=?", [$stop['id']]);
                    $this->check($freeC === $freeB4 && $st === 'cancelled', 'stop.cancel', "anulowanie obronnego nie zwróciło pakietu (wolne: $freeC, status: $st)");
                }

                // 5c) PKC sprzedaż: wpływ = suma (wartość − prowizja) per transakcja
                if ($got > 0 && Engine::one("SELECT COUNT(*) FROM orders WHERE stock_id=? AND side='buy' AND status='active' AND user_id<>?", [$sid, $uid])) {
                    $txMax2 = (int) (Engine::one("SELECT COALESCE(MAX(id),0) FROM transactions") ?: 0);
                    $cashB5 = $this->cash($uid);
                    $this->http('POST', '/place_order.php', ['stock_id' => $sid, 'side' => 'sell', 'type' => 'market', 'qty' => $got, 'price' => '', 'sl_price' => '', 'tp_price' => '']);
                    $fills2 = Engine::all("SELECT qty, price FROM transactions WHERE seller_id=? AND stock_id=? AND id>?", [$uid, $sid, $txMax2]);
                    $this->check(count($fills2) > 0, 'pkc.sell_fill', 'PKC sprzedaż nie zrealizowała żadnej transakcji');
                    if ($fills2) {
                        $in = 0.0;
                        foreach ($fills2 as $f) { $v = round($f['qty'] * $f['price'], 2); $in += $v - round($v * Engine::feeRate(), 2); }
                        $this->moneyEq($this->cash($uid) - $cashB5, round($in, 2), 'pkc.sell_fee', 'PKC: wpływ ze sprzedaży ≠ wartość minus prowizje');
                    }
                }
            }
        } else {
            Log::write('warn', 'qa', 'qa.skip', 'brak ofert w arkuszu — pominięto test PKC');
        }

        // 7) inwarianty globalne (cały świat, nie tylko QA)
        $neg = (int) Engine::one("SELECT COUNT(*) FROM users WHERE cash < -0.01 OR cash_reserved < -0.01")
             + (int) Engine::one("SELECT COUNT(*) FROM wallets WHERE qty < 0 OR qty_reserved < 0");
        $this->check($neg === 0, 'inv.negatives', "ujemne salda/ilości: $neg rekordów");

        $badCash = Engine::all(
            "SELECT u.id, u.cash_reserved, COALESCE((SELECT SUM(o.qty*o.price) FROM orders o WHERE o.user_id=u.id AND o.side='buy' AND o.status='active'),0) AS should_be
             FROM users u WHERE ABS(u.cash_reserved - COALESCE((SELECT SUM(o.qty*o.price) FROM orders o WHERE o.user_id=u.id AND o.side='buy' AND o.status='active'),0)) > 0.02");
        $this->check(count($badCash) === 0, 'inv.cash_reserved', 'rezerwacje gotówki ≠ aktywne zlecenia kupna', ['users' => array_slice($badCash, 0, 3)]);

        $badQty = Engine::all(
            "SELECT w.user_id, w.stock_id, w.qty_reserved, COALESCE((SELECT SUM(o.qty) FROM orders o WHERE o.user_id=w.user_id AND o.stock_id=w.stock_id AND o.side='sell' AND o.status IN ('active','pending')),0) AS should_be
             FROM wallets w WHERE w.qty_reserved <> COALESCE((SELECT SUM(o.qty) FROM orders o WHERE o.user_id=w.user_id AND o.stock_id=w.stock_id AND o.side='sell' AND o.status IN ('active','pending')),0)");
        $this->check(count($badQty) === 0, 'inv.qty_reserved', 'rezerwacje akcji ≠ aktywne zlecenia sprzedaży + obronne', ['wallets' => array_slice($badQty, 0, 3)]);

        // sprzątanie po sobie (też zlecenia obronne i wpisy na czacie)
        foreach (Engine::all("SELECT id FROM orders WHERE user_id=? AND status IN ('active','pending')", [$uid]) as $o) Engine::cancel((int) $o['id'], $uid);
        $pdo->prepare("UPDATE chat_messages SET deleted=1 WHERE user_id=?")->execute([$uid]);
    }

    /* ---------- pomocnicze ---------- */

    private function cash(int $uid): float { return (float) Engine::one("SELECT cash FROM users WHERE id=?", [$uid]); }

    private function check(bool $cond, string $event, string $msg, array $ctx = []): void
    {
        $this->checks++;
        if (!$cond) $this->fail($event, $msg, $ctx);
    }

    private function moneyEq(float $actual, float $expected, string $event, string $msg): void
    {
        $this->checks++;
        if (abs($actual - $expected) > 0.011) $this->fail($event, "$msg (jest " . number_format($actual, 2, '.', '') . ", oczekiwano " . number_format($expected, 2, '.', '') . ")");
    }

    private function fail(string $event, string $msg, array $ctx = []): void
    {
        $this->fails[] = "$event: $msg";
        Log::write('error', 'qa', $event, $msg, $ctx);
    }

    private function http(string $method, string $path, array $post = []): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
            CURLOPT_TIMEOUT => 20, CURLOPT_USERAGENT => 'GPW-QA-bot',
        ]);
        if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post)); }
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$code, $body];
    }
}
