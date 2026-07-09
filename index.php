<?php
/**
 * Wejście, gdy adres (np. gra.mppp.com.pl) wskazuje na KATALOG APLIKACJI
 * zamiast na podkatalog public/. Przekierowuje do właściwej aplikacji.
 *
 * Docelowo najczystsze jest ustawienie document roota subdomeny na .../public
 * — wtedy ten plik jest nieużywany i silnik leży poza zasięgiem internetu.
 */
header('Location: public/');
exit;
