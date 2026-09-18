<?php
/**
 * Powiadomienia push (Web Push, RFC 8291/8292) — bez composera, na samym openssl/curl.
 *
 * Skąd biorą się powiadomienia: Engine::notify() zapisuje je do tabeli notifications (dzwonek w grze).
 * Push::flush() (cron/tick.php, po tickach) dosyła NOWE wpisy od zapamiętanego kursora tym graczom,
 * którzy włączyli push w przeglądarce (public/api_push.php + assets/push.js + sw.js). Wysyłka nie siedzi
 * w transakcji ticka: to HTTP do serwerów Google/Mozilla/Apple, kilkaset ms na sztukę.
 *
 * Klucze VAPID (EC P-256) generuje serwer raz i trzyma w game_state jako base64url surowych bajtów
 * (publiczny: punkt 65 B, prywatny: skalar 32 B) — PEM budujemy w locie. Bez openssl_pkey_derive /
 * aes-128-gcm na serwerze push jest po prostu niedostępny (UI o tym mówi), nic nie pada.
 */
final class Push
{
    public const MAX_FAILS = 3;     // po tylu nieudanych wysyłkach subskrypcja znika (endpoint martwy)
    public const BATCH = 60;        // maks. powiadomień dosyłanych w jednym przebiegu crona

    public static function available(): bool
    {
        return function_exists('openssl_pkey_derive') && function_exists('hash_hkdf') && function_exists('curl_init')
            && function_exists('openssl_pkey_new') && in_array('aes-128-gcm', openssl_get_cipher_methods(), true);
    }

    public static function b64url(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function b64urlDecode(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4)); }

    /** Klucze VAPID ['public' => b64url(65 B), 'private' => b64url(32 B)]; $create = wygeneruj, gdy brak. */
    public static function keys(bool $create = false): ?array
    {
        $pub = Engine::one("SELECT v FROM game_state WHERE k='vapid_public'");
        $prv = Engine::one("SELECT v FROM game_state WHERE k='vapid_private'");
        if ($pub && $prv) return ['public' => (string) $pub, 'private' => (string) $prv];
        if (!$create || !self::available()) return null;
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($key === false) return null;
        $d = openssl_pkey_get_details($key);
        if (!isset($d['ec']['x'], $d['ec']['y'], $d['ec']['d'])) return null;
        $pubRaw = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $prvRaw = str_pad($d['ec']['d'], 32, "\0", STR_PAD_LEFT);
        Engine::setState('vapid_public', self::b64url($pubRaw));
        Engine::setState('vapid_private', self::b64url($prvRaw));
        Log::write('info', 'engine', 'push.keys', 'wygenerowano klucze VAPID');
        return ['public' => self::b64url($pubRaw), 'private' => self::b64url($prvRaw)];
    }

    /** PEM klucza publicznego P-256 z surowego punktu (65 B, 0x04||X||Y). */
    public static function publicPem(string $raw65): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $raw65;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** PEM (PKCS#8) klucza prywatnego P-256 ze skalara d (32 B) i punktu publicznego (65 B). */
    public static function privatePem(string $d32, string $pub65): string
    {
        $der = hex2bin('308187020100301306072a8648ce3d020106082a8648ce3d030107046d306b0201010420') . $d32 . hex2bin('a144034200') . $pub65;
        return "-----BEGIN PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    /** Podpis DER (SEQUENCE{INTEGER r, INTEGER s}) -> surowe r||s (64 B), jak wymaga JWT ES256. */
    public static function derToRaw(string $der): string
    {
        $pos = 2;
        if ((ord($der[1]) & 0x80) !== 0) $pos = 2 + (ord($der[1]) & 0x7f);
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            if ($der[$pos] !== "\x02") throw new RuntimeException('zły podpis DER');
            $len = ord($der[$pos + 1]); $pos += 2;
            $int = ltrim(substr($der, $pos, $len), "\0"); $pos += $len;
            $out .= str_pad($int, 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** Token JWT (ES256) dla serwera push danej subskrypcji; $audience = schemat://host endpointu. */
    public static function vapidJwt(string $audience): ?string
    {
        $k = self::keys(true);
        if (!$k) return null;
        if (!class_exists('Mailer')) require_once __DIR__ . '/Mailer.php';
        $hdr = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $claims = self::b64url(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => 'mailto:' . Mailer::from()]));
        $pk = openssl_pkey_get_private(self::privatePem(self::b64urlDecode($k['private']), self::b64urlDecode($k['public'])));
        if ($pk === false) return null;
        if (!openssl_sign("$hdr.$claims", $der, $pk, OPENSSL_ALGO_SHA256)) return null;
        return "$hdr.$claims." . self::b64url(self::derToRaw($der));
    }

    /**
     * Szyfrowanie treści (RFC 8291, aes128gcm): ECDH z kluczem subskrypcji + HKDF + AES-128-GCM.
     * Zwraca gotowe ciało żądania (nagłówek salt|rs|idlen|klucz nadawcy + szyfrogram + tag).
     */
    public static function encrypt(string $payload, string $p256dh, string $auth): string
    {
        $uaPub = self::b64urlDecode($p256dh); $authSecret = self::b64urlDecode($auth);
        if (strlen($uaPub) !== 65 || strlen($authSecret) < 16) throw new RuntimeException('zły klucz subskrypcji');
        $local = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ld = openssl_pkey_get_details($local);
        $asPub = "\x04" . str_pad($ld['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ld['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $uaKey = openssl_pkey_get_public(self::publicPem($uaPub));
        if ($uaKey === false) throw new RuntimeException('nieczytelny klucz subskrypcji');
        $shared = openssl_pkey_derive($uaKey, $local, 32);
        if ($shared === false) throw new RuntimeException('ECDH nieudane');
        $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPub . $asPub, $authSecret);
        $salt  = random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        if ($ct === false) throw new RuntimeException('szyfrowanie nieudane');
        return $salt . pack('N', 4096) . chr(strlen($asPub)) . $asPub . $ct . $tag;
    }

    /** Wyślij jedno powiadomienie na jedną subskrypcję. Zwraca [ok, kod HTTP albo opis błędu]. */
    public static function sendTo(array $sub, array $data): array
    {
        $endpoint = (string) $sub['endpoint'];
        $scheme = (string) parse_url($endpoint, PHP_URL_SCHEME); $host = (string) parse_url($endpoint, PHP_URL_HOST);
        if ($scheme !== 'https' || $host === '') return [false, 'zły endpoint'];
        $jwt = self::vapidJwt("$scheme://$host");
        if ($jwt === null) return [false, 'brak kluczy VAPID'];
        $k = self::keys();
        try { $body = self::encrypt(json_encode($data, JSON_UNESCAPED_UNICODE), (string) $sub['p256dh'], (string) $sub['auth']); }
        catch (\Throwable $e) { return [false, 'szyfrowanie: ' . $e->getMessage()]; }
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Authorization: vapid t=' . $jwt . ', k=' . $k['public'], 'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm', 'TTL: 86400', 'Urgency: normal', 'Content-Length: ' . strlen($body)],
        ]);
        $res = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($res === false) return [false, 'curl: ' . $err];
        return [$code >= 200 && $code < 300, (string) $code];
    }

    /* ---------- subskrypcje ---------- */

    public static function subscribe(int $uid, string $endpoint, string $p256dh, string $auth): bool
    {
        if (!preg_match('~^https://[^\s]{10,1500}$~', $endpoint) || strlen($p256dh) < 80 || strlen($auth) < 16) return false;
        $pdo = Db::pdo();
        $hash = hash('sha256', $endpoint);
        $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint_hash=?")->execute([$hash]);   // ta sama przeglądarka po ponownym zapisie
        $pdo->prepare("INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth, created_at) VALUES (?,?,?,?,?,?)")
            ->execute([$uid, $hash, $endpoint, $p256dh, $auth, Db::now()]);
        return true;
    }

    public static function unsubscribe(int $uid, ?string $endpoint = null): int
    {
        $st = $endpoint !== null
            ? Db::pdo()->prepare("DELETE FROM push_subscriptions WHERE user_id=? AND endpoint_hash=?")
            : Db::pdo()->prepare("DELETE FROM push_subscriptions WHERE user_id=?");
        $st->execute($endpoint !== null ? [$uid, hash('sha256', $endpoint)] : [$uid]);
        return $st->rowCount();
    }

    public static function count(int $uid): int
    {
        return (int) Engine::one("SELECT COUNT(*) FROM push_subscriptions WHERE user_id=?", [$uid]);
    }

    /** Wyślij do wszystkich subskrypcji gracza; martwe (404/410, 3 błędy z rzędu) znikają. Zwraca [wysłane, błędy]. */
    public static function sendUser(int $uid, string $title, string $body, string $url = '', string $tag = ''): array
    {
        $sent = 0; $fail = 0;
        $pdo = Db::pdo();
        foreach (Engine::all("SELECT * FROM push_subscriptions WHERE user_id=?", [$uid]) as $sub) {
            [$ok, $info] = self::sendTo($sub, ['title' => $title, 'body' => $body, 'url' => $url ?: 'powiadomienia.php', 'tag' => $tag]);
            if ($ok) {
                $sent++;
                $pdo->prepare("UPDATE push_subscriptions SET fails=0, last_ok=? WHERE id=?")->execute([Db::now(), (int) $sub['id']]);
            } else {
                $fail++;
                $dead = in_array($info, ['404', '410'], true) || (int) $sub['fails'] + 1 >= self::MAX_FAILS;
                if ($dead) $pdo->prepare("DELETE FROM push_subscriptions WHERE id=?")->execute([(int) $sub['id']]);
                else $pdo->prepare("UPDATE push_subscriptions SET fails=fails+1 WHERE id=?")->execute([(int) $sub['id']]);
                Log::write('warn', 'engine', 'push.fail', "push #{$sub['id']} gracza #$uid: $info" . ($dead ? ' — subskrypcja usunięta' : ''));
            }
        }
        return [$sent, $fail];
    }

    /**
     * Dosyłka nowych powiadomień z tabeli notifications (od kursora push_last_notif_id) subskrybentom.
     * Pierwsze uruchomienie tylko ustawia kursor (bez zalewania starymi wpisami). Zwraca [wysłane, błędy].
     */
    public static function flush(): array
    {
        if (!self::available()) return [0, 0];
        $cur = Engine::one("SELECT v FROM game_state WHERE k='push_last_notif_id'");
        $max = (int) (Engine::one("SELECT COALESCE(MAX(id),0) FROM notifications") ?: 0);
        if ($cur === false || $cur === null || $cur === '') { Engine::setState('push_last_notif_id', (string) $max); return [0, 0]; }
        $cur = (int) $cur;
        if ($cur > $max) { Engine::setState('push_last_notif_id', (string) $max); return [0, 0]; }   // tabela wyczyszczona (reinstalacja)
        if ((int) Engine::one("SELECT COUNT(*) FROM push_subscriptions") === 0) { Engine::setState('push_last_notif_id', (string) $max); return [0, 0]; }
        $rows = Engine::all("SELECT n.id, n.user_id, n.type, n.message, n.link FROM notifications n
                             WHERE n.id > ? AND EXISTS (SELECT 1 FROM push_subscriptions p WHERE p.user_id = n.user_id)
                             ORDER BY n.id ASC LIMIT " . self::BATCH, [$cur]);
        $sent = 0; $fail = 0; $last = $cur;
        $t0 = microtime(true);
        foreach ($rows as $n) {
            [$s, $f] = self::sendUser((int) $n['user_id'], 'Makleria', (string) $n['message'], (string) ($n['link'] ?: 'powiadomienia.php'), 'n' . (int) $n['id']);
            $sent += $s; $fail += $f; $last = (int) $n['id'];
            if (microtime(true) - $t0 > 20) break;   // budżet czasu: reszta w następnym przebiegu crona
        }
        // gdy przejrzeliśmy wszystko w paczce (albo nie było nic do wysłania), kursor skacze na koniec tabeli
        if (count($rows) < self::BATCH && $last === (int) ($rows ? end($rows)['id'] : $cur)) $last = max($last, $max);
        Engine::setState('push_last_notif_id', (string) $last);
        if ($sent + $fail > 0) Log::write('info', 'engine', 'push.flush', "push: $sent wysłanych, $fail błędów");
        return [$sent, $fail];
    }
}
