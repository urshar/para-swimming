<?php

use App\Models\Athlete;
use App\Models\Nation;
use App\Models\RelayTeamMember;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\LenexResolverService;
use App\Services\TeamManagerImportService;
use App\Support\PersonName;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('person-name');

// Ganz groß geschriebene Namen aus Importen in Groß- und Kleinschreibung bringen.

function nation_pn(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

it('bringt ganz groß geschriebene Namen in Groß- und Kleinschreibung', function (?string $name, ?string $expected) {
    expect(PersonName::normalize($name))->toBe($expected);
})->with([
    ['NASSWETTER', 'Nasswetter'],
    ['SCHÜNEMANN', 'Schünemann'],
    ['TAUPE-LEHNER', 'Taupe-Lehner'],
    ['WEBER - TREIBER', 'Weber - Treiber'],
    ["O'NEIL", "O'Neil"],
    ['ÖZDEMIR', 'Özdemir'],
    ['De los Santos', 'De los Santos'],
    ['McDonald', 'McDonald'],
    ['Huber', 'Huber'],
    ['A.', 'A.'],
    [null, null],
]);

it('übernimmt Namen aus dem Team Manager in Groß- und Kleinschreibung', /** @throws Throwable */ function () {
    nation_pn();
    $member = ['MEMBERSID' => '1', 'LASTNAME' => 'TAUPE-LEHNER', 'FIRSTNAME' => 'ANNA', 'BIRTHDATE' => '2001-01-20 00:00:00',
        'GENDER' => '2', 'NATION' => 'AUT', 'CLUBSID1' => '0', 'REGISTRATIONID' => 'S-1', 'ACTIVE' => 'T',
        'HANDICAPS' => '0', 'HANDICAPSB' => '0', 'HANDICAPSM' => '0'];
    /** @var array{clubs: list<array<string, string|null>>, members: list<array<string, string|null>>} $data */
    $data = ['clubs' => [], 'members' => [$member]];
    $service = app(TeamManagerImportService::class);

    $service->import($service->preview($data), []);

    expect(Athlete::query()->sole()->only(['first_name', 'last_name']))->toBe(['first_name' => 'Anna', 'last_name' => 'Taupe-Lehner']);
});

it('legt Athleten aus dem LENEX-Import in Groß- und Kleinschreibung an', function () {
    $athlete = (new LenexResolverService)->createAthlete(['first_name' => 'Yannic', 'last_name' => 'NASSWETTER',
        'nation_id' => nation_pn()->id, 'gender' => 'M']);

    expect($athlete->last_name)->toBe('Nasswetter')
        ->and($athlete->first_name)->toBe('Yannic');
});

it('ändert vorhandene Namen über das Kommando erst nach dem Probelauf', function () {
    $nation = nation_pn();
    $upper = Athlete::query()->create(['first_name' => 'Yannic', 'last_name' => 'NASSWETTER', 'gender' => 'M', 'nation_id' => $nation->id]);
    $mixed = Athlete::query()->create(['first_name' => 'Juan', 'last_name' => 'De los Santos', 'gender' => 'M', 'nation_id' => $nation->id]);
    $stroke = StrokeType::query()->create(['code' => 'FREE', 'lenex_code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
    $record = SwimRecord::query()->create(['stroke_type_id' => $stroke->id, 'record_type' => 'AUT', 'sport_class' => 'S14',
        'gender' => 'M', 'course' => 'SCM', 'distance' => 100, 'relay_count' => 4, 'swim_time' => 30000,
        'record_status' => 'APPROVED', 'is_current' => true]);
    $member = RelayTeamMember::query()->create(['swim_record_id' => $record->id, 'position' => 1, 'first_name' => 'KLAUS', 'last_name' => 'RYBA']);

    $this->artisan('athletes:normalize-names', ['--dry-run' => true])
        ->expectsOutputToContain('Probelauf')
        ->assertSuccessful();

    expect($upper->fresh()->last_name)->toBe('NASSWETTER');

    $this->artisan('athletes:normalize-names')
        ->expectsConfirmation('2 Name(n) ändern?', 'yes')
        ->assertSuccessful();

    expect($upper->fresh()->last_name)->toBe('Nasswetter')
        ->and($mixed->fresh()->last_name)->toBe('De los Santos')
        ->and($member->fresh()->only(['first_name', 'last_name']))->toBe(['first_name' => 'Klaus', 'last_name' => 'Ryba']);
});
