<?php

use App\Support\NameSearch;

uses()->group('name-search');

it('zerlegt den Suchbegriff an Kommas und Leerzeichen', function () {
    expect(NameSearch::terms('Aigner'))->toBe(['Aigner'])
        ->and(NameSearch::terms('Aigner, M'))->toBe(['Aigner', 'M'])
        ->and(NameSearch::terms('  Aigner ,Matteo  '))->toBe(['Aigner', 'Matteo'])
        ->and(NameSearch::terms("Matteo\tAigner"))->toBe(['Matteo', 'Aigner'])
        ->and(NameSearch::terms('Taupe-Lehner'))->toBe(['Taupe-Lehner'])
        ->and(NameSearch::terms(' , '))->toBe([])
        ->and(NameSearch::terms(''))->toBe([]);
});

it('prüft im Speicher, dass jeder Suchteil vorkommt, ohne Groß- und Kleinschreibung', function () {
    expect(NameSearch::matches('Matteo Aigner', 'Aigner'))->toBeTrue()
        ->and(NameSearch::matches('Matteo Aigner', 'aigner, m'))->toBeTrue()
        ->and(NameSearch::matches('Matteo Aigner', 'Aigner Matteo'))->toBeTrue()
        ->and(NameSearch::matches('Matteo Aigner', 'Matteo Aigner'))->toBeTrue()
        ->and(NameSearch::matches('Jürgen Österle', 'österle, JÜ'))->toBeTrue()
        ->and(NameSearch::matches('Matteo Aigner', 'Aigner, X'))->toBeFalse()
        ->and(NameSearch::matches('Matteo Aigner', ''))->toBeTrue();
});
