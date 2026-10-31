<?php

use App\Models\BaseTimeVersion;
use App\Models\Cup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('cup-top-group-p1');

function makeCup_ctgc1(int $year): Cup
{
    $version = BaseTimeVersion::create(['label' => "V$year", 'valid_from' => '2012-01-01']);

    return Cup::create([
        'year' => $year, 'name' => "ÖBSV Cup $year", 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450,
    ]);
}

it('nennt die vorab nachgerechneten Cup-Jahre in der Erfolgsmeldung', function () {
    makeCup_ctgc1(2024);
    makeCup_ctgc1(2025);
    $cup = makeCup_ctgc1(2026);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->post(route('cups.classify-top-group', $cup))
        ->assertRedirect(route('cups.top-group.index', $cup))
        ->assertSessionHas('success',
            'Top-Gruppen-Klassifizierung für "ÖBSV Cup 2026" berechnet. Vorab nachgerechnet: 2024, 2025.');
});

it('zeigt in der Cup-Konfiguration nur den Status der Top-Gruppe, eingestuft wird auf der Top-Gruppe-Seite', function () {
    $cup = makeCup_ctgc1(2026);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('cups.index'))
        ->assertOk()
        ->assertSee('Noch nicht eingestuft')
        ->assertSee(route('cups.top-group.index', $cup))
        ->assertDontSee(route('cups.classify-top-group', $cup));
});
