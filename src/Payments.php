<?php
/**
 * Płatności za Tokeny Maklera — PayU REST API (BLIK, karty, przelewy).
 *
 * ZASADA: sprzedajemy TYLKO tokeny (informacja/wygoda/kosmetyka), nigdy PLN
 * w grze. Realizacja jest w pełni audytowalna: payment_orders trzyma każdy
 * krok (new -> pending -> completed/cancelled), a przyznanie tokenów ląduje
 * w token_ledger jak każda inna operacja.
 *
 * Konfiguracja (sekrety NA SERWERZE w config.local.php, budowanym przez CI
 * z GitHub Secrets — nigdy w repo):
 *   'payu' => [
 *     'pos_id' => '...', 'md5' => '...' (drugi klucz),
 *     'client_id' => '...', 'client_secret' => '...',
 *     'sandbox' => true|false,
 *   ]
 * Bez kompletu kluczy sklep pokazuje cennik z dopiskiem "wkrótce" i nic
 * nie wysyła. Standardowy checkout PayU zawiera BLIK domyślnie.
 */
final class Payments
{
    /** Pakiety doładowań: klucz => [tokeny łącznie (z bonusem), cena w groszach, nazwa, dopisek] */
    public const PACKAGES = [
        'start'    => [20,  999,  'Pakiet Startowy',  ''],
        'inwestor' => [55,  1999, 'Pakiet Inwestora', '+10% gratis'],
        'rekin'    => [150, 4999, 'Pakiet Rekina',    '+25% gratis'],
    ];

    /** Nadpisanie konfiguracji w testach (bez dotykania config.local.php). */
    public static ?array $testCfg = null;

    public static function config(): array
    {
        if (self::$testCfg !== null) return self::$testCfg;
        $cfg = require __DIR__ . '/../config.php';
        return is_array($cfg['payu'] ?? null) ? $cfg['payu'] : [];
    }

    /** Czy operator płatności jest podpięty (komplet kluczy)? */
    public static function enabled(): bool
    {
        $c = self::config();
        foreach (['pos_id', 'md5', 'client_id', 'client_secret'] as $k) {
            if (trim((string) ($c[$k] ?? '')) === '') return false;
        }
        return true;
    }

    private static function endpoint(): string
    {
        return !empty(self::config()['sandbox']) ? 'https://secure.snd.payu.com' : 'https://secure.payu.com';
    }

    /** Publiczny adres gry (continueUrl/notifyUrl) — z config.app_url (katalog public). */
    private static function baseUrl(): string
    {
        $cfg = require __DIR__ . '/../config.php';
        return rtrim((string) ($cfg['app_url'] ?? ''), '/');
    }

    /**
     * Utwórz zamówienie i zarejestruj je w PayU. Zwraca [ok, redirectUri|komunikat].
     * Przepływ: rekord 'new' -> OAuth -> POST /api/v2_1/orders -> 'pending' + przekierowanie
     * gracza na stronę płatności PayU (BLIK/karta/przelew do wyboru u operatora).
     */
    public static function createOrder(int $uid, string $package, string $customerIp): array
    {
        if (!isset(self::PACKAGES[$package])) return [false, 'Nie ma takiego pakietu.'];
        if (!self::enabled()) return [false, 'Płatności online jeszcze nie są aktywne — tokeny przyznaje administrator.'];
        [$tokens, $grosz, $name] = self::PACKAGES[$package];

        $pdo = Db::pdo();
        $pdo->prepare("INSERT INTO payment_orders (user_id, package, tokens, amount_grosz, status, provider, created_at)
                       VALUES (?,?,?,?, 'new', 'payu', ?)")
            ->execute([$uid, $package, $tokens, $grosz, Db::now()]);
        $oid = (int) $pdo->lastInsertId();
        $extId = 'MAK-' . $oid . '-' . substr(bin2hex(random_bytes(4)), 0, 8);

        $c = self::config();
        try {
            $auth = self::http('POST', self::endpoint() . '/pl/standard/user/oauth/authorize',
                http_build_query(['grant_type' => 'client_credentials', 'client_id' => $c['client_id'], 'client_secret' => $c['client_secret']]),
                ['Content-Type: application/x-www-form-urlencoded']);
            $token = (string) (json_decode($auth, true)['access_token'] ?? '');
            if ($token === '') throw new RuntimeException('brak access_token z PayU');

            $body = json_encode([
                'extOrderId'    => $extId,
                'notifyUrl'     => self::baseUrl() . '/platnosc_notify.php',
                'continueUrl'   => self::baseUrl() . '/platnosc.php?o=' . $oid,
                'customerIp'    => $customerIp !== '' ? $customerIp : '127.0.0.1',
                'merchantPosId' => (string) $c['pos_id'],
                'description'   => "Makleria: $name ($tokens Tokenów Maklera)",
                'currencyCode'  => 'PLN',
                'totalAmount'   => (string) $grosz,
                'products'      => [['name' => $name, 'unitPrice' => (string) $grosz, 'quantity' => '1']],
            ], JSON_UNESCAPED_UNICODE);
            $resp = self::http('POST', self::endpoint() . '/api/v2_1/orders?', $body,
                ['Content-Type: application/json', "Authorization: Bearer $token"], false);
            $j = json_decode($resp, true);
            $redirect = (string) ($j['redirectUri'] ?? '');
            if ($redirect === '') throw new RuntimeException('PayU nie zwrócił redirectUri: ' . mb_substr($resp, 0, 200));

            // ext_ref = orderId nadany przez PayU; nasz extOrderId odtwarzamy z id zamówienia
            $pdo->prepare("UPDATE payment_orders SET status='pending', ext_ref=? WHERE id=?")
                ->execute([mb_substr((string) ($j['orderId'] ?? $extId), 0, 64), $oid]);
            Log::write('info', 'player', 'pay.create', "zamówienie #$oid: $name", ['user_id' => $uid, 'grosz' => $grosz]);
            return [true, $redirect];
        } catch (Throwable $e) {
            $pdo->prepare("UPDATE payment_orders SET status='cancelled' WHERE id=?")->execute([$oid]);
            Log::write('error', 'player', 'pay.create_fail', $e->getMessage(), ['order' => $oid]);
            return [false, 'Nie udało się rozpocząć płatności — spróbuj za chwilę lub napisz do administratora.'];
        }
    }

    /**
     * Webhook PayU (notifyUrl): weryfikacja podpisu i realizacja zamówienia.
     * Zwraca [kod HTTP, treść odpowiedzi]. Idempotentne — powtórka notyfikacji
     * (PayU wysyła je do skutku) nie przyzna tokenów drugi raz.
     */
    public static function handleNotify(string $rawBody, string $signatureHeader): array
    {
        if (!self::enabled()) return [503, 'payments disabled'];
        if (!self::verifySignature($rawBody, $signatureHeader)) {
            Log::write('warn', 'engine', 'pay.bad_signature', 'notyfikacja z błędnym podpisem', ['len' => strlen($rawBody)]);
            return [401, 'bad signature'];
        }
        $j = json_decode($rawBody, true);
        $order = $j['order'] ?? null;
        if (!is_array($order)) return [400, 'no order'];
        $extId = (string) ($order['extOrderId'] ?? '');
        $status = (string) ($order['status'] ?? '');
        // nasz extOrderId = MAK-{id}-{losowe}
        if (!preg_match('/^MAK-(\d+)-/', $extId, $m)) return [400, 'unknown extOrderId'];
        $oid = (int) $m[1];

        if ($status === 'COMPLETED') self::complete($oid);
        elseif ($status === 'CANCELED') {
            Db::pdo()->prepare("UPDATE payment_orders SET status='cancelled' WHERE id=? AND status IN ('new','pending')")->execute([$oid]);
        }
        return [200, '{"status":"OK"}'];   // PENDING/WAITING_FOR_CONFIRMATION: tylko potwierdzamy odbiór
    }

    /** Podpis OpenPayu-Signature: signature=<md5(body + drugi klucz)>;algorithm=MD5;... */
    public static function verifySignature(string $rawBody, string $header): bool
    {
        if (!preg_match('/signature=([a-f0-9]+)/i', $header, $m)) return false;
        $expected = md5($rawBody . (string) (self::config()['md5'] ?? ''));
        return hash_equals($expected, strtolower($m[1]));
    }

    /** Realizacja opłaconego zamówienia (ATOMOWO idempotentna). Zwraca true przy pierwszym przyznaniu. */
    public static function complete(int $orderId): bool
    {
        $pdo = Db::pdo();
        $st = $pdo->prepare("UPDATE payment_orders SET status='completed', paid_at=? WHERE id=? AND status IN ('new','pending')");
        $st->execute([Db::now(), $orderId]);
        if ($st->rowCount() === 0) return false;   // już zrealizowane albo nieznane — nic nie przyznajemy
        $o = Engine::row("SELECT * FROM payment_orders WHERE id=?", [$orderId]);
        if (!$o) return false;
        [, , $name] = self::PACKAGES[$o['package']] ?? [0, 0, $o['package']];
        if (!class_exists('Tokens')) require_once __DIR__ . '/Tokens.php';
        Tokens::grant((int) $o['user_id'], (int) $o['tokens'], 'purchase', "zakup: $name");
        Engine::journal((int) $o['user_id'], 'token', "🪙 Opłacono $name — +" . (int) $o['tokens'] . " Tokenów Maklera. Dziękujemy!", 'sklep.php');
        Log::write('info', 'engine', 'pay.complete', "zamówienie #$orderId opłacone: $name", ['user_id' => $o['user_id'], 'grosz' => $o['amount_grosz']]);
        return true;
    }

    private static function http(string $method, string $url, string $body, array $headers, bool $followRedirects = true): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $followRedirects,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $body,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) { $err = curl_error($ch); curl_close($ch); throw new RuntimeException("PayU HTTP: $err"); }
        curl_close($ch);
        return (string) $resp;
    }
}
