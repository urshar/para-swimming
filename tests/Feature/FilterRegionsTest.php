<?php

use App\Models\Championship;
use App\Models\QualifyingTimeList;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('filter-regions');

// Die Auto-Submit-Filter laden die Liste ohne Neuladen nach (resources/js/filter-refresh.js) und
// tauschen dabei jedes Element mit data-filter-region gegen das gleichnamige der neuen Antwort aus.
// Fehlt ein Bereich in der Antwort, lädt die Seite doch wieder komplett neu. Deshalb muss jeder
// Bereich auf jeder Seite genau einmal gerendert werden, mit und ohne aktiven Filter.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_fr(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function regionCount_fr(TestResponse $response, string $region): int
{
    return substr_count($response->getContent(), 'data-filter-region="'.$region.'"');
}

/**
 * Seite je Datensatz: [Route-Parameter-Fabrik, erwartete Bereiche, Filter-Query für "gefiltert"].
 *
 * @return array<string, array{0: string, 1: Closure(): array<string, mixed>, 2: list<string>, 3: array<string, string>}>
 */
function filterPages_fr(): array
{
    $standard = ['reset', 'list'];

    return [
        'Athleten' => ['athletes.index', fn () => [], $standard, ['search' => 'Zzz']],
        'Vereine' => ['clubs.index', fn () => [], $standard, ['search' => 'Zzz']],
        'Klassifizierer' => ['classifiers.index', fn () => [], $standard, ['search' => 'Zzz']],
        'Trainer' => ['coaches.index', fn () => [], $standard, ['search' => 'Zzz']],
        'Ergebnisse' => ['results.index', fn () => [], ['meet-tools', 'reset', 'list'], ['search' => 'Zzz']],
        'Veranstaltungen' => ['meets.index', fn () => [], $standard, ['search' => 'Zzz']],
        'Meldungen' => ['entries.index', fn () => [], ['tiles', 'reset', 'list'], ['search' => 'Zzz']],
        'Staffelmeldungen' => ['relay-entries.index', fn () => [], ['tiles', 'reset', 'list'], ['search' => 'Zzz']],
        'Ergebnis-Sammelansicht' => [
            'meets.results-overview', fn () => ['meet' => makeMeet_p5()], ['pdf', 'reset', 'list'], ['search' => 'Zzz'],
        ],
        'Auswahl-Rangliste' => [
            'championships.selection',
            fn () => ['championship' => Championship::query()->create([
                'name' => 'EM 2026',
                'short_name' => 'EM 2026',
                'type' => Championship::TYPE_EC,
                'year' => 2026,
                'course' => Championship::COURSE_LCM,
                'qualification_start' => '2025-01-01',
                'qualification_end' => '2026-12-31',
            ])],
            $standard,
            ['limit' => '3'],
        ],
        'Qualifikationen' => [
            'qualifying-time-lists.qualifications',
            fn () => ['qualifyingTimeList' => QualifyingTimeList::create([
                'year' => 2027, 'is_active' => true,
                'qualification_period_start' => '2026-05-12', 'qualification_period_end' => '2027-05-29',
            ])],
            ['header', 'reset', 'list'],
            ['search' => 'Zzz'],
        ],
    ];
}

it('rendert jeden Filter-Bereich genau einmal, ohne und mit aktivem Filter', function (
    string $route, Closure $params, array $regions, array $filter,
) {
    $admin = admin_fr();
    $routeParams = $params();

    $plain = $this->actingAs($admin)->get(route($route, $routeParams))->assertOk();
    $filtered = $this->actingAs($admin)->get(route($route, [...$routeParams, ...$filter]))->assertOk();

    foreach ($regions as $region) {
        expect(regionCount_fr($plain, $region))->toBe(1, "$region ohne Filter")
            ->and(regionCount_fr($filtered, $region))->toBe(1, "$region mit Filter");
    }

    // Ohne Filter ist der "Zurücksetzen"-Bereich leer, mit Filter enthält er den Knopf.
    expect($plain->getContent())->not->toMatch('/[Zz]urücksetzen/u')
        ->and($filtered->getContent())->toMatch('/[Zz]urücksetzen/u');
})->with(filterPages_fr());
