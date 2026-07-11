<?php
/**
 * Wysyłka e-maili (reset hasła itd.) przez PHP mail() — standard na polskich
 * hostingach współdzielonych. Gdy mail() zawiedzie (np. środowisko deweloperskie
 * bez sendmaila), pełna treść ląduje w dzienniku logów — nic nie ginie po cichu,
 * a testy mogą odczytać link z logów.
 *
 * Nadawca: config 'mail_from' albo no-reply@<domena z app_url>.
 */
final class Mailer
{
    public static function from(): string
    {
        $cfg = require __DIR__ . '/../config.php';
        if (!empty($cfg['mail_from'])) return (string) $cfg['mail_from'];
        $host = (string) parse_url((string) ($cfg['app_url'] ?? ''), PHP_URL_HOST) ?: 'localhost';
        return "no-reply@$host";
    }

    /** Wyślij tekstowy e-mail. Zwraca true, gdy mail() przyjął wiadomość. */
    public static function send(string $to, string $subject, string $body): bool
    {
        $from = self::from();
        $headers = "From: GPW-gra <$from>\r\n"
                 . "MIME-Version: 1.0\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n"
                 . "Content-Transfer-Encoding: 8bit";
        $encSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $ok = false;
        try { $ok = @mail($to, $encSubject, $body, $headers); } catch (\Throwable $e) { $ok = false; }
        // ślad w logach: sukces z zamaskowanym adresem; porażka z treścią (odzyskiwalna ręcznie)
        $masked = preg_replace('/(?<=.).(?=[^@]*@)/', '*', $to);
        if ($ok) Log::write('info', 'auth', 'mail.sent', "$subject -> $masked");
        else Log::write('warn', 'auth', 'mail.fallback', "$subject -> $masked (mail() zawiódł — treść w kontekście)", ['body' => $body]);
        return $ok;
    }
}
