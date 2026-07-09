<?php
/**
 * Jedno miejsce konfiguracji.
 * Kolejność źródeł sekretów (od najwyższego priorytetu):
 *   1. config.local.php   — plik NA SERWERZE, poza repo (patrz .gitignore). Zalecane na hostingu.
 *   2. zmienne środowiskowe (getenv) — np. ustawione w cPanel.
 *   3. domyślne wartości (SQLite lokalnie) — do pracy bez konfiguracji.
 * Sekretów NIGDY nie commitujemy do repozytorium.
 */
$config = [
    // 'sqlite' (lokalnie) albo 'mysql' (na hostingu)
    'db_driver' => getenv('DB_DRIVER') ?: 'sqlite',

    'sqlite' => ['path' => __DIR__ . '/data/tycoon.sqlite'],

    'mysql' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'name' => getenv('DB_NAME') ?: 'gpw',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
    ],

    'starting_cash' => 100000.0,   // startowa gotówka nowego gracza
];

// Nadpisz sekretami z pliku serwerowego (poza repo), jeśli istnieje.
if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) $config = array_replace_recursive($config, $local);
}

return $config;
