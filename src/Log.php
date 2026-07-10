<?php
/**
 * Dziennik logów gry — centralne miejsce zbierania danych do analizy błędów.
 * Piszą tu: QA-bot (asercje), silnik (wyjątki ticka), akcje graczy (zlecenia,
 * rejestracje, nieudane logowania). Dziennik NIGDY nie może wywalić gry —
 * każdy zapis jest opakowany w try/catch.
 */
final class Log
{
    public static function write(string $level, string $source, string $event, string $message = '', array $context = []): void
    {
        try {
            $tick = (int) (Engine::one("SELECT v FROM game_state WHERE k='tick'") ?: 0);
            Db::pdo()->prepare("INSERT INTO logs (ts, tick, level, source, event, message, context) VALUES (?,?,?,?,?,?,?)")
                ->execute([
                    date('Y-m-d H:i:s'), $tick, $level, $source, $event,
                    mb_substr($message, 0, 2000),
                    $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                ]);
        } catch (Throwable $e) { /* celowo cicho */ }
    }

    /** Utrzymuj dziennik w ryzach (ostatnie N wpisów). */
    public static function prune(int $keep = 20000): void
    {
        try {
            $max = (int) Db::pdo()->query("SELECT MAX(id) FROM logs")->fetchColumn();
            if ($max > $keep) Db::pdo()->exec("DELETE FROM logs WHERE id <= " . ($max - $keep));
        } catch (Throwable $e) { /* celowo cicho */ }
    }
}
