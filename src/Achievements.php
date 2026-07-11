<?php
/**
 * Katalog osiągnięć (odznak). Przyznawanie: Engine::award($uid, $code) —
 * wpis jest unikalny per gracz, pierwszy raz daje powiadomienie 🎖️.
 * Warunki sprawdzane w naturalnych hakach silnika (transakcje, dywidendy,
 * stopy, sesje, cel gry) — tanio, tylko dla ludzi.
 */
final class Achievements
{
    public static function all(): array
    {
        return [
            'pierwsza_transakcja' => ['🤝', 'Pierwsza krew',            'Zawarłeś pierwszą transakcję na giełdzie.'],
            'trader_100'          => ['⚡', 'Wilk z GPW-gry',           'Zawarłeś 100 transakcji.'],
            'day_trader'          => ['🌪️', 'Day trader',               '20 transakcji w jednej sesji.'],
            'dywersyfikacja'      => ['🧺', 'Nie wszystkie jajka',      'Trzymasz akcje 10 różnych spółek jednocześnie.'],
            'pierwsza_dywidenda'  => ['💰', 'Pierwsza dywidenda',       'Otrzymałeś pierwszą wypłatę dywidendy.'],
            'rentier'             => ['🏖️', 'Rentier',                  'Trzymasz jednocześnie 5 spółek wypłacających dywidendę.'],
            'sl_zadzialal'        => ['🛡️', 'Uratowany przez stopa',    'Twój Stop-Loss zadziałał i uciął stratę.'],
            'tp_zadzialal'        => ['🎯', 'Zysk w kieszeni',          'Twój Take-Profit zadziałał i zrealizował zysk.'],
            'rajd_10'             => ['🚀', 'Rajd',                     '+10% kapitału w jednej sesji.'],
            'lekcja_pokory'       => ['🎢', 'Lekcja pokory',            '-10% kapitału w jednej sesji. Bywa.'],
            'kupil_w_krachu'      => ['🩸', 'Kupował, gdy lała się krew', 'Kupiłeś akcje w trakcie krachu rynkowego.'],
            'milioner'            => ['🏆', 'Milioner',                 'Osiągnąłeś cel gry.'],
        ];
    }

    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }
}
