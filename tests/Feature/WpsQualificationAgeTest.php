<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Nation;
use App\Support\AthleteAge;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('wps-qual-age');

function athlete_age(?string $geburtsdatum): Athlete
{
    $nation = Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
    $club = Club::query()->create(['name' => 'WAT', 'nation_id' => $nation->id]);

    return Athlete::query()->create([
        'club_id' => $club->id,
        'nation_id' => $nation->id,
        'first_name' => 'Test',
        'last_name' => 'Athlet',
        'birth_date' => $geburtsdatum,
        'gender' => 'F',
    ]);
}

it('rechnet das Alter zum 31. Dezember des Bezugsjahres', function () {
    // Geburtstag am 31.12. — zählt das ganze Jahr über bereits als ein Jahr älter.
    $silvesterkind = athlete_age('2000-12-31');
    $jahresanfang = athlete_age('2000-01-01');

    expect(AthleteAge::atEndOf($silvesterkind, 2026))->toBe(26)
        ->and(AthleteAge::atEndOf($jahresanfang, 2026))->toBe(26);
});

it('bezieht sich auf das Jahr der Meisterschaft, nicht auf das laufende', function () {
    $athlet = athlete_age('2000-05-01');

    // Eine Auswertung der EM 2026 soll auch 2028 dieselbe Altersangabe zeigen.
    expect(AthleteAge::atEndOf($athlet, 2026))->toBe(26)
        ->and(AthleteAge::atEndOf($athlet, 2024))->toBe(24);
});

it('nennt Alter und Geburtsjahr nebeneinander', function () {
    expect(AthleteAge::label(athlete_age('2000-05-01'), 2026))->toBe('26 Jahre (2000)')
        ->and(AthleteAge::birthYear(athlete_age('2000-05-01')))->toBe(2000);
});

it('liefert ohne Geburtsdatum null statt null Jahre', function () {
    $ohneDatum = athlete_age(null);

    // "0 Jahre ()" sähe nach einem Fehler aus, wo schlicht eine Angabe fehlt.
    expect(AthleteAge::label($ohneDatum, 2026))->toBeNull()
        ->and(AthleteAge::atEndOf($ohneDatum, 2026))->toBeNull()
        ->and(AthleteAge::birthYear($ohneDatum))->toBeNull()
        ->and(AthleteAge::label(null, 2026))->toBeNull();
});
