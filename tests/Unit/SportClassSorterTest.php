<?php

use App\Support\SportClassSorter;
use Tests\TestCase;

uses(TestCase::class);

it('sortiert Sportklassen numerisch statt alphabetisch', function () {
    $sorted = collect(['S10', 'S2', 'S1', 'S9', 'SB12', 'SB2'])
        ->sortBy(fn ($sc) => SportClassSorter::key($sc))
        ->values()
        ->all();

    expect($sorted)->toBe(['S1', 'S2', 'S9', 'S10', 'SB2', 'SB12']);
})->group('qualifying-time-lists-grouping');

it('behandelt ein unerwartetes Format ohne Fehler', function () {
    expect(SportClassSorter::key('unbekannt'))->toBe('UNBEKANNT')
        ->and(SportClassSorter::key(null))->toBe('');
})->group('qualifying-time-lists-grouping');
