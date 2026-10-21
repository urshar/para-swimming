<?php

use App\Models\SwimRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('record-relay-labels');

// Staffelrekorde zeigen die Disziplin mit Staffelanzahl ("4×50 m Freistil") — in der Rekordcheck-Liste und in der
// Detailansicht; die Liste nennt bei Staffeln den Verein und kennt das Mixed-Symbol.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function record_rrl(int $relayCount, string $gender = 'F', bool $withClub = true): SwimRecord
{
    return SwimRecord::query()->create(['stroke_type_id' => makeStrokeType_p5()->id, 'nation_id' => makeNation_p5()->id,
        'record_type' => 'AUT', 'sport_class' => 'S14', 'gender' => $gender, 'course' => 'SCM', 'distance' => 50,
        'relay_count' => $relayCount, 'swim_time' => 20020, 'record_status' => 'APPROVED', 'is_current' => true,
        'set_date' => '2015-05-09', 'club_id' => $withClub ? makeClub_p5()->id : null]);
}

it('beschriftet Einzel- und Staffeldisziplinen', function () {
    expect(record_rrl(1)->discipline_label)->toBe('50 m FREE')
        ->and(record_rrl(4)->discipline_label)->toBe('4×50 m FREE')
        ->and(SwimRecord::genderSymbol('X'))->toBe('⚥')
        ->and(SwimRecord::genderSymbol('F'))->toBe('♀')
        ->and(SwimRecord::genderSymbol('M'))->toBe('♂');
});

it('zeigt neue Staffelrekorde im Rekordcheck mit Staffelanzahl, Verein und Mixed-Symbol', function () {
    $record = record_rrl(4, 'X');

    $html = view('records.check-result', ['checkResult' => [
        'checked' => 1,
        'new_record_ids' => [['id' => $record->id, 'types' => ['AUT']]],
        'pending_record_ids' => [],
    ]])->render();

    expect($html)->toContain('4×50 m FREE')
        ->and($html)->toContain($record->club->display_name)
        ->and($html)->toContain('⚥')
        ->and($html)->toContain('aria-label="Mixed"');
});

it('zeigt die Staffelanzahl im Kopf der Rekord-Detailansicht', function () {
    $record = record_rrl(4);

    $this->actingAs(User::factory()->create(['is_admin' => true, 'club_id' => null]))
        ->get(route('records.show', $record))
        ->assertOk()
        ->assertSee('AUT · S14 · 4×50 m FREE');
});
