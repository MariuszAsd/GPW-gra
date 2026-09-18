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
            'trader_100'          => ['⚡', 'Wilk z Maklerii',           'Zawarłeś 100 transakcji.'],
            'day_trader'          => ['🌪️', 'Day trader',               '20 transakcji w jednej sesji.'],
            'dywersyfikacja'      => ['🧺', 'Nie wszystkie jajka',      'Trzymasz akcje 10 różnych spółek jednocześnie.'],
            'pierwsza_dywidenda'  => ['💰', 'Pierwsza dywidenda',       'Otrzymałeś pierwszą wypłatę dywidendy.'],
            'rentier'             => ['🏖️', 'Rentier',                  'Trzymasz jednocześnie 5 spółek wypłacających dywidendę.'],
            'sl_zadzialal'        => ['🛡️', 'Uratowany przez stopa',    'Twój Stop-Loss zadziałał i uciął stratę.'],
            'tp_zadzialal'        => ['🎯', 'Zysk w kieszeni',          'Twój Take-Profit zadziałał i zrealizował zysk.'],
            'stopbuy_zadzialal'   => ['⏫', 'Łowca wybić',              'Twój stop-buy złapał wybicie — kupno aktywowało się po przebiciu progu.'],
            'rajd_10'             => ['🚀', 'Rajd',                     '+10% kapitału w jednej sesji.'],
            'lekcja_pokory'       => ['🎢', 'Lekcja pokory',            '-10% kapitału w jednej sesji. Bywa.'],
            'kupil_w_krachu'      => ['🩸', 'Kupował, gdy lała się krew', 'Kupiłeś akcje w trakcie krachu rynkowego.'],
            // drabinka: progi stopy zwrotu od kapitału startowego (Engine::LADDER) — odznaka + tokeny za szczebel
            'drabinka_10'         => ['📈', '+10%',                     'Kapitał wyższy o 10% od startowego.'],
            'drabinka_25'         => ['📈', '+25%',                     'Kapitał wyższy o 25% od startowego.'],
            'drabinka_50'         => ['📈', '+50%',                     'Kapitał wyższy o 50% od startowego.'],
            'drabinka_100'        => ['🔥', 'Podwojenie',               'Podwoiłeś kapitał startowy.'],
            'drabinka_150'        => ['🔥', '+150%',                    'Kapitał wyższy o 150% od startowego.'],
            'drabinka_200'        => ['🔥', 'Potrojenie',               'Potroiłeś kapitał startowy.'],
            'drabinka_300'        => ['💎', '+300%',                    'Kapitał czterokrotnie wyższy od startowego.'],
            'drabinka_500'        => ['💎', '+500%',                    'Kapitał sześciokrotnie wyższy od startowego.'],
            'milioner'            => ['🏆', 'Milioner',                 'Szczyt drabinki: +900% od startu — milion ze stu tysięcy.'],
            'zwyciezca_wyzwania'  => ['🏅', 'Zwycięzca wyzwania',       'Wygrałeś wyzwanie inwestycyjne.'],
        ];
    }

    public static function get(string $code): ?array
    {
        return self::all()[$code] ?? null;
    }
}
