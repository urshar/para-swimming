<?php

use App\Support\SportClassRanges;

uses()->group('sport-class-ranges');

it('fasst aufeinanderfolgende Sportklassen zu Bereichen zusammen', function () {
    expect(SportClassRanges::format('S1 S2 S3 S4 S5 S6 S7 S9 S10 S11 S12 S13 S14 S15 S21'))
        ->toBe('S1-S7, S9-S15, S21');
});

it('behandelt reine Zahlen ohne Präfix', function () {
    expect(SportClassRanges::format('1 2 3 5'))->toBe('1-3, 5');
});

it('gruppiert nach Präfix in Reihenfolge des ersten Auftretens', function () {
    expect(SportClassRanges::format('SB1 SB2 SB4 S1 S2'))->toBe('SB1-SB2, SB4, S1-S2');
});

it('normalisiert Reihenfolge und Duplikate', function () {
    expect(SportClassRanges::format('S3 S1 S2 S2'))->toBe('S1-S3');
});

it('liefert für leere Eingabe einen leeren String', function () {
    expect(SportClassRanges::format(null))->toBe('')
        ->and(SportClassRanges::format(''))->toBe('')
        ->and(SportClassRanges::format('   '))->toBe('');
});
