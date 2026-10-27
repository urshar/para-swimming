<?php

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\AthleteClubHistory;
use App\Models\AthleteKaderMembership;
use App\Models\AthleteLevelHistory;
use App\Models\AthletePerformanceNote;
use App\Models\AthleteSportClass;
use App\Models\BaseTimeVersion;
use App\Models\Club;
use App\Models\Cup;
use App\Models\CupDailyResult;
use App\Models\CupOverallResult;
use App\Models\CupTopGroupClassification;
use App\Models\Entry;
use App\Models\ExceptionCode;
use App\Models\ImportReviewItem;
use App\Models\KaderType;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\RelayEntry;
use App\Models\RelayEntryMember;
use App\Models\RelayResult;
use App\Models\RelayResultMember;
use App\Models\RelayTeamMember;
use App\Models\Result;
use App\Models\SportClassGroup;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Models\SwimRecord;
use App\Services\AthleteMergeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class)->group('athlete-merge-p1');

// Athleten zusammenführen (AthleteMergeService): Phase 1 — Umhängen je Tabelle, Kollisionen, Stammdaten-Auswahl.

function nation_am1(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]);
}

function club_am1(string $name): Club
{
    return Club::firstOrCreate(['name' => $name], ['nation_id' => nation_am1()->id]);
}

function athlete_am1(string $lastName, string $firstName, array $attributes): Athlete
{
    return Athlete::create([
        'last_name' => $lastName, 'first_name' => $firstName, 'gender' => 'F', 'birth_date' => '1990-08-21',
        'nation_id' => nation_am1()->id, ...$attributes,
    ]);
}

/** Behaltener und aufgelöster Athlet, wie bei Lizenz O-2030 (vertauschter Name). */
function pair_am1(): array
{
    return [athlete_am1('Djuza', 'Slavica', []), athlete_am1('Slavica', 'Djuza', [])];
}

function stroke_am1(): StrokeType
{
    return StrokeType::firstOrCreate(['lenex_code' => 'FREE'], ['code' => 'FREE', 'name_de' => 'Freistil', 'name_en' => 'Freestyle']);
}

function meet_am1(string $name): Meet
{
    return Meet::create(['name' => $name, 'nation_id' => nation_am1()->id, 'course' => 'SCM', 'start_date' => '2026-05-10']);
}

function event_am1(Meet $meet, int $number, int $relayCount): SwimEvent
{
    return SwimEvent::create(['meet_id' => $meet->id, 'stroke_type_id' => stroke_am1()->id, 'distance' => 50 * $number,
        'gender' => 'F', 'event_number' => $number, 'relay_count' => $relayCount]);
}

function result_am1(SwimEvent $event, Athlete $athlete): Result
{
    return Result::create(['meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
        'club_id' => club_am1('Heimverein')->id, 'sport_class' => 'S9', 'swim_time' => 3300]);
}

function record_am1(Athlete $athlete, int $time): SwimRecord
{
    return SwimRecord::create(['stroke_type_id' => stroke_am1()->id, 'nation_id' => nation_am1()->id, 'record_type' => 'AUT',
        'sport_class' => 'S9', 'gender' => 'F', 'course' => 'SCM', 'distance' => 50, 'relay_count' => 1,
        'swim_time' => $time, 'record_status' => 'APPROVED', 'is_current' => true, 'set_date' => '2026-05-10',
        'athlete_id' => $athlete->id]);
}

function sportClass_am1(Athlete $athlete, string $category, string $number): AthleteSportClass
{
    return AthleteSportClass::create(['athlete_id' => $athlete->id, 'category' => $category, 'class_number' => $number,
        'sport_class' => ($category === 'S' ? 'S' : $category).$number]);
}

function exceptionCode_am1(string $code): ExceptionCode
{
    return ExceptionCode::create(['code' => $code, 'name_de' => $code, 'name_en' => $code, 'is_active' => true]);
}

function cup_am1(): Cup
{
    $version = BaseTimeVersion::create(['label' => 'V1', 'valid_from' => '2021-01-01']);

    return Cup::create(['year' => 2026, 'name' => 'ÖBSV Cup 2026', 'base_time_version_id' => $version->id,
        'rounds_count' => 1, 'best_of_count' => 3, 'top_group_points_threshold' => 450]);
}

function service_am1(): AthleteMergeService
{
    return app(AthleteMergeService::class);
}

/** Zählt die Zeilen je Tabelle, die noch auf den Athleten verweisen. */
function references_am1(int $athleteId): array
{
    return array_filter(array_map(
        fn (string $table) => DB::table($table)->where('athlete_id', $athleteId)->count(),
        array_combine(array_keys(AthleteMergeService::TABLES), array_keys(AthleteMergeService::TABLES)),
    ));
}

// ── Grundregeln und Stammdaten ───────────────────────────────────────────────

it('lehnt das Zusammenführen eines Athleten mit sich selbst ab', function () {
    $athlete = athlete_am1('Djuza', 'Slavica', []);

    service_am1()->analyze($athlete, $athlete);
})->throws(InvalidArgumentException::class);

it('stellt abweichende Stammdaten gegenüber und schlägt bei leerem Wert den anderen vor', function () {
    $keep = athlete_am1('Djuza', 'Slavica', ['notes' => 'Aus dem Team Manager', 'email' => '']);
    $drop = athlete_am1('Slavica', 'Djuza', ['license' => 'O-2030', 'notes' => 'Aus dem LENEX-Import',
        'email' => 'slavica@example.test']);

    $fields = service_am1()->analyze($keep, $drop)['fields'];

    expect(array_keys($fields))->toBe(['first_name', 'last_name', 'license', 'notes', 'email'])
        ->and($fields['first_name'])->toBe(['keep' => 'Slavica', 'drop' => 'Djuza', 'suggestion' => 'keep'])
        ->and($fields['license'])->toBe(['keep' => null, 'drop' => 'O-2030', 'suggestion' => 'drop'])
        ->and($fields['notes']['suggestion'])->toBe('both')
        ->and($fields['email']['suggestion'])->toBe('drop');
});

it('übernimmt die gewählten Stammdaten, sonst den Vorschlag, und verbindet die Notizen', /** @throws Throwable */ function () {
    $keep = athlete_am1('Djuza', 'Slavica', ['notes' => 'Aus dem Team Manager', 'birth_date' => '1990-01-01']);
    $drop = athlete_am1('Slavica', 'Djuza', ['license' => 'O-2030', 'notes' => 'Aus dem LENEX-Import']);

    service_am1()->merge($keep, $drop, ['birth_date' => 'drop'], []);
    $keep->refresh();

    expect($keep->display_name)->toBe('Djuza, Slavica')
        ->and($keep->birth_date->toDateString())->toBe('1990-08-21')
        ->and($keep->license)->toBe('O-2030')
        ->and($keep->notes)->toBe("Aus dem Team Manager\n\nAus dem LENEX-Import")
        ->and(Athlete::withTrashed()->find($drop->id))->toBeNull();
});

it('lehnt eine ungültige Auswahl für ein Stammdatenfeld ab', /** @throws Throwable */ function () {
    $keep = athlete_am1('Djuza', 'Slavica', []);
    $drop = athlete_am1('Slavica', 'Djuza', ['license' => 'O-2030']);

    service_am1()->merge($keep, $drop, ['license' => 'both'], []);
})->throws(InvalidArgumentException::class);

// ── Ergebnisse, Meldungen, Staffeln, Rekorde ─────────────────────────────────

it('hängt Ergebnisse und Meldungen um und löscht den aufgelösten Athleten endgültig', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $meet = meet_am1('Staatsmeisterschaften');
    result_am1(event_am1($meet, 1, 1), $keep);
    $event = event_am1($meet, 2, 1);
    $result = result_am1($event, $drop);
    $entry = Entry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $drop->id,
        'club_id' => club_am1('Heimverein')->id]);

    $moved = service_am1()->merge($keep, $drop, [], []);

    expect($moved)->toBe(['results' => 1, 'entries' => 1])
        ->and($result->fresh()->athlete_id)->toBe($keep->id)
        ->and($entry->fresh()->athlete_id)->toBe($keep->id)
        ->and(Result::where('athlete_id', $keep->id)->count())->toBe(2)
        ->and(references_am1($drop->id))->toBe([])
        ->and(Athlete::withTrashed()->find($drop->id))->toBeNull();
});

it('blockiert bei Ergebnis oder Meldung beider Athleten im selben Bewerb', function () {
    [$keep, $drop] = pair_am1();
    $event = event_am1(meet_am1('Staatsmeisterschaften'), 1, 1);
    result_am1($event, $keep);
    result_am1($event, $drop);
    foreach ([$keep, $drop] as $athlete) {
        Entry::create(['meet_id' => $event->meet_id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
            'club_id' => club_am1('Heimverein')->id]);
    }

    expect(service_am1()->analyze($keep, $drop)['blocking'])->toBe([
        'Beide haben ein Ergebnis in "Staatsmeisterschaften", Bewerb 1.',
        'Beide haben eine Meldung in "Staatsmeisterschaften", Bewerb 1.',
    ])
        ->and(/** @throws Throwable */ fn () => service_am1()->merge($keep, $drop, [], []))->toThrow(RuntimeException::class)
        ->and(Result::where('athlete_id', $drop->id)->count())->toBe(1)
        ->and(Athlete::find($drop->id))->not->toBeNull();
});

it('hängt Staffelergebnisse, Staffelmeldungen und Rekord-Staffelteams um', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $meet = meet_am1('Staatsmeisterschaften');
    $event = event_am1($meet, 1, 4);
    $relay = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'club_id' => club_am1('Heimverein')->id, 'gender' => 'F', 'swim_time' => 15000]);
    $resultMember = RelayResultMember::create(['relay_result_id' => $relay->id, 'position' => 2, 'athlete_id' => $drop->id]);
    $relayEntry = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => club_am1('Heimverein')->id]);
    $entryMember = RelayEntryMember::create(['relay_entry_id' => $relayEntry->id, 'athlete_id' => $drop->id, 'position' => 2]);
    $record = record_am1($keep, 15000);
    $teamMember = RelayTeamMember::create(['swim_record_id' => $record->id, 'position' => 2, 'first_name' => 'Djuza',
        'last_name' => 'Slavica', 'athlete_id' => $drop->id]);

    service_am1()->merge($keep, $drop, [], []);

    expect($resultMember->fresh()->athlete_id)->toBe($keep->id)
        ->and($entryMember->fresh()->athlete_id)->toBe($keep->id)
        ->and($teamMember->fresh()->athlete_id)->toBe($keep->id);
});

it('blockiert, wenn beide Mitglied derselben Staffel sind', function () {
    [$keep, $drop] = pair_am1();
    $meet = meet_am1('Staatsmeisterschaften');
    $event = event_am1($meet, 1, 4);
    $relay = RelayResult::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id,
        'club_id' => club_am1('Heimverein')->id, 'gender' => 'F', 'swim_time' => 15000]);
    $relayEntry = RelayEntry::create(['meet_id' => $meet->id, 'swim_event_id' => $event->id, 'club_id' => club_am1('Heimverein')->id]);
    $record = record_am1($keep, 15000);
    foreach ([$keep, $drop] as $position => $athlete) {
        RelayResultMember::create(['relay_result_id' => $relay->id, 'position' => $position + 1, 'athlete_id' => $athlete->id]);
        RelayEntryMember::create(['relay_entry_id' => $relayEntry->id, 'athlete_id' => $athlete->id, 'position' => $position + 1]);
        RelayTeamMember::create(['swim_record_id' => $record->id, 'position' => $position + 1, 'first_name' => 'X',
            'last_name' => 'Y', 'athlete_id' => $athlete->id]);
    }

    expect(service_am1()->analyze($keep, $drop)['blocking'])->toBe([
        "Beide sind Mitglied derselben Staffel (Staffelergebnis #$relay->id).",
        "Beide sind Mitglied derselben Staffel (Staffelmeldung #$relayEntry->id).",
        "Beide sind Mitglied derselben Staffel (Rekord-Staffelteam #$record->id).",
    ]);
});

it('hängt Rekorde um und meldet doppelte Rekorde als Hinweis', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $twin = record_am1($keep, 3300);
    $duplicate = record_am1($drop, 3300);
    $other = record_am1($drop, 3200);

    $hints = service_am1()->analyze($keep, $drop)['hints'];
    service_am1()->merge($keep, $drop, [], []);

    expect($hints)->toBe(["Doppelter Rekord nach dem Zusammenführen: #$twin->id und #$duplicate->id (AUT, 50 m, S9, SCM)."])
        ->and(SwimRecord::where('athlete_id', $keep->id)->pluck('id')->sort()->values()->all())
        ->toBe([$twin->id, $duplicate->id, $other->id]);
});

// ── Notizen und Prüfliste ────────────────────────────────────────────────────

it('hängt Leistungsnotizen und Prüflisten-Einträge samt Athleten-IDs im JSON um', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $other = athlete_am1('Andere', 'Athletin', []);
    $note = AthletePerformanceNote::create(['athlete_id' => $drop->id, 'noted_on' => '2026-05-10',
        'category' => AthletePerformanceNote::CATEGORY_TRAINING, 'note' => 'Wende verbessert']);
    // JSON-Inhalt vorab in Variablen: Inline in create() hält PhpStorm die Schlüssel für Model-Attribute ("guarded").
    $details = ['key' => 'k1', 'list' => ['athlete_id' => $drop->id,
        'relay_members' => [['position' => 1, 'db_id' => $other->id], ['position' => 2, 'db_id' => $drop->id]]]];
    $relayDetails = ['key' => 'k2', 'list' => ['athlete_id' => $other->id,
        'relay_members' => [['position' => 1, 'db_id' => $drop->id]]]];
    $item = ImportReviewItem::create(['type' => ImportReviewItem::TYPE_LIST_MISMATCH, 'athlete_id' => $drop->id,
        'status' => ImportReviewItem::STATUS_OPEN, 'details' => $details]);
    $relayItem = ImportReviewItem::create(['type' => ImportReviewItem::TYPE_LIST_MISMATCH, 'athlete_id' => $other->id,
        'status' => ImportReviewItem::STATUS_OPEN, 'details' => $relayDetails]);

    service_am1()->merge($keep, $drop, [], []);
    $item->refresh();

    expect($note->fresh()->athlete_id)->toBe($keep->id)
        ->and($item->athlete_id)->toBe($keep->id)
        ->and($item->details['list']['athlete_id'])->toBe($keep->id)
        ->and(array_column($item->details['list']['relay_members'], 'db_id'))->toBe([$other->id, $keep->id])
        ->and($relayItem->fresh()->athlete_id)->toBe($other->id)
        ->and($relayItem->fresh()->details['list']['relay_members'][0]['db_id'])->toBe($keep->id);
});

// ── Sportklassen und Ausnahme-Codes ──────────────────────────────────────────

it('übernimmt fehlende Sportklassen, verwirft gleiche und verlangt für abweichende eine Auswahl', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    sportClass_am1($keep, 'S', '9');
    sportClass_am1($keep, 'SM', '9');
    sportClass_am1($drop, 'S', '8');
    sportClass_am1($drop, 'SB', '7');
    sportClass_am1($drop, 'SM', '9');

    expect(service_am1()->analyze($keep, $drop)['sport_classes'])->toBe(['S' => ['keep' => 'S9 (INTL)', 'drop' => 'S8 (INTL)']])
        ->and(/** @throws Throwable */ fn () => service_am1()->merge($keep, $drop, [], []))->toThrow(InvalidArgumentException::class);

    service_am1()->merge($keep, $drop, [], ['S' => 'drop']);

    expect(AthleteSportClass::where('athlete_id', $keep->id)->orderBy('category')->pluck('sport_class')->all())
        ->toBe(['S8', 'SB7', 'SM9'])
        ->and(AthleteSportClass::count())->toBe(3);
});

it('behält bei Auswahl "keep" die Sportklasse des behaltenen Athleten', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    sportClass_am1($keep, 'S', '9');
    sportClass_am1($drop, 'S', '8');

    service_am1()->merge($keep, $drop, [], ['S' => 'keep']);

    expect(AthleteSportClass::pluck('sport_class')->all())->toBe(['S9']);
});

it('vereinigt die Ausnahme-Codes beider Athleten', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $br3 = exceptionCode_am1('BR3');
    $h = exceptionCode_am1('H');
    $keep->exceptions()->attach($br3->id, ['category' => 'SB']);
    $drop->exceptions()->attach($br3->id, ['category' => 'SB']);
    $drop->exceptions()->attach($br3->id, ['category' => 'SM']);
    $drop->exceptions()->attach($h->id, ['category' => null]);

    service_am1()->merge($keep, $drop, [], []);

    expect(DB::table('athlete_exceptions')
        ->join('exception_codes', 'exception_codes.id', '=', 'athlete_exceptions.exception_code_id')
        ->where('athlete_id', $keep->id)
        ->get(['exception_codes.code', 'athlete_exceptions.category'])
        ->map(fn (object $row) => $row->code.'/'.$row->category)->sort()->values()->all())
        ->toBe(['BR3/SB', 'BR3/SM', 'H/']);
});

// ── Histories ────────────────────────────────────────────────────────────────

it('übernimmt Klassifikationen samt Ausnahmen und verwirft exakte Duplikate', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $code = exceptionCode_am1('BR3');
    $attributes = ['classified_at' => '2024-03-01', 'result_s' => 'S9', 'classification_status' => 'CONFIRMED'];
    AthleteClassification::create(['athlete_id' => $keep->id, ...$attributes]);
    $duplicate = AthleteClassification::create(['athlete_id' => $drop->id, ...$attributes]);
    $withException = AthleteClassification::create(['athlete_id' => $drop->id, ...$attributes]);
    $withException->exceptions()->attach($code->id, ['category' => 'SB']);
    $older = AthleteClassification::create(['athlete_id' => $drop->id, 'classified_at' => '2019-05-01', 'result_s' => 'S8']);

    service_am1()->merge($keep, $drop, [], []);

    expect(AthleteClassification::find($duplicate->id))->toBeNull()
        ->and($withException->fresh()->athlete_id)->toBe($keep->id)
        ->and($withException->fresh()->exceptions()->pluck('code')->all())->toBe(['BR3'])
        ->and($older->fresh()->athlete_id)->toBe($keep->id)
        ->and(AthleteClassification::where('athlete_id', $keep->id)->count())->toBe(3);
});

it('führt die Vereins-History zusammen, schließt ältere offene Einträge und setzt den Verein', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $old = club_am1('Alter Verein');
    $new = club_am1('Neuer Verein');
    $keep->update(['club_id' => $old->id]);
    $keepOpen = AthleteClubHistory::create(['athlete_id' => $keep->id, 'club_id' => $old->id, 'joined_at' => '2015-01-01',
        'is_active' => true]);
    AthleteClubHistory::create(['athlete_id' => $drop->id, 'club_id' => $old->id, 'joined_at' => '2015-01-01',
        'is_active' => true]);
    $dropOpen = AthleteClubHistory::create(['athlete_id' => $drop->id, 'club_id' => $new->id, 'joined_at' => '2022-09-01',
        'is_active' => true]);

    $hints = service_am1()->analyze($keep, $drop)['hints'];
    service_am1()->merge($keep, $drop, [], []);
    $keepOpen->refresh();
    $dropOpen->refresh();

    expect($hints)->toBe(['Vereinszeiträume überschneiden sich: Alter Verein (2015-01-01 – heute) und Neuer Verein (2022-09-01 – heute).'])
        ->and(AthleteClubHistory::where('athlete_id', $keep->id)->count())->toBe(2)
        ->and($keepOpen->left_at->toDateString())->toBe('2022-08-31')
        ->and($keepOpen->is_active)->toBeFalse()
        ->and($dropOpen->athlete_id)->toBe($keep->id)
        ->and($dropOpen->is_active)->toBeTrue()
        ->and($keep->fresh()->club_id)->toBe($new->id);
});

it('übernimmt ohne Vereins-History den Verein des aufgelösten Athleten, wenn der behaltene keinen hat', /** @throws Throwable */ function () {
    $keep = athlete_am1('Muhrhofer', 'Katrin', []);
    $drop = athlete_am1('Muhrhofer', 'Katrin', ['club_id' => club_am1('Wr. Gehörlosen SC')->id]);

    service_am1()->merge($keep, $drop, [], []);

    expect($keep->fresh()->club_id)->toBe(club_am1('Wr. Gehörlosen SC')->id);
});

it('führt die Level-History zusammen und setzt das jüngste Level', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $keep->update(['level' => 'B']);
    AthleteLevelHistory::create(['athlete_id' => $keep->id, 'level' => 'B', 'changed_at' => '2020-01-01']);
    AthleteLevelHistory::create(['athlete_id' => $drop->id, 'level' => 'B', 'changed_at' => '2020-01-01']);
    AthleteLevelHistory::create(['athlete_id' => $drop->id, 'level' => 'A', 'previous_level' => 'B', 'changed_at' => '2024-01-01']);

    service_am1()->merge($keep, $drop, [], []);

    expect(AthleteLevelHistory::where('athlete_id', $keep->id)->count())->toBe(2)
        ->and($keep->fresh()->level)->toBe('A');
});

it('führt Kaderzugehörigkeiten zusammen und verwirft exakte Duplikate', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $kader = KaderType::create(['code' => 'WELTKLASSE', 'name_de' => 'Weltklasse', 'is_active' => true]);
    $period = ['kader_type_id' => $kader->id, 'valid_from' => '2025-01-01', 'valid_until' => '2025-12-31'];
    AthleteKaderMembership::create(['athlete_id' => $keep->id, ...$period]);
    AthleteKaderMembership::create(['athlete_id' => $drop->id, ...$period]);
    AthleteKaderMembership::create(['athlete_id' => $drop->id, ...$period, 'valid_from' => '2026-01-01',
        'valid_until' => '2026-12-31']);

    service_am1()->merge($keep, $drop, [], []);

    expect(AthleteKaderMembership::where('athlete_id', $keep->id)->count())->toBe(2)
        ->and(AthleteKaderMembership::count())->toBe(2);
});

// ── Cup-Wertungen und Qualifikationen ────────────────────────────────────────

it('hängt Cup-Wertungen um und verwirft kollidierende Zeilen des aufgelösten Athleten', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $cup = cup_am1();
    $club = club_am1('Heimverein');
    $group = SportClassGroup::create(['code' => 'PI', 'name_de' => 'PI', 'is_active' => true]);
    $meet = meet_am1('Cup Runde 1');
    $otherMeet = meet_am1('Cup Runde 2');
    $keepResult = result_am1(event_am1($meet, 1, 1), $keep);
    $dropResult = result_am1(event_am1($meet, 2, 1), $drop);
    $dropOtherResult = result_am1(event_am1($otherMeet, 1, 1), $drop);
    $daily = fn (Athlete $athlete, Meet $m, Result $r) => CupDailyResult::create(['cup_id' => $cup->id, 'meet_id' => $m->id,
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'result_id' => $r->id, 'sport_class_group_id' => $group->id,
        'gender' => 'F', 'points' => 500, 'calculated_at' => now()]);
    $daily($keep, $meet, $keepResult);
    $daily($drop, $meet, $dropResult);
    $movedDaily = $daily($drop, $otherMeet, $dropOtherResult);
    foreach ([$keep, $drop] as $athlete) {
        CupTopGroupClassification::create(['cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'is_top_group' => false,
            'calculated_at' => now()]);
        CupOverallResult::create(['cup_id' => $cup->id, 'athlete_id' => $athlete->id, 'club_id' => $club->id,
            'sport_class_group_id' => $group->id, 'gender' => 'F', 'total_points' => 500, 'rounds_counted' => 1,
            'counted_meet_ids' => [$meet->id], 'calculated_at' => now()]);
    }

    expect(service_am1()->analyze($keep, $drop)['cup_ids'])->toBe([$cup->id]);

    service_am1()->merge($keep, $drop, [], []);

    expect(CupTopGroupClassification::where('athlete_id', $keep->id)->count())->toBe(1)
        ->and(CupOverallResult::where('athlete_id', $keep->id)->count())->toBe(1)
        ->and(CupDailyResult::where('athlete_id', $keep->id)->count())->toBe(2)
        ->and($movedDaily->fresh()->athlete_id)->toBe($keep->id)
        ->and(CupDailyResult::count())->toBe(2);
});

it('hängt Qualifikationen um und verwirft kollidierende des aufgelösten Athleten', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    $meet = meet_am1('Staatsmeisterschaften');
    $list = QualifyingTimeList::create(['year' => 2026, 'is_active' => true, 'qualification_period_start' => '2026-01-01',
        'qualification_period_end' => '2026-12-31']);
    $time = fn (int $distance) => QualifyingTime::create(['qualifying_time_list_id' => $list->id,
        'stroke_type_id' => stroke_am1()->id, 'distance' => $distance, 'gender' => 'F', 'sport_class' => 'S9',
        'value_centiseconds' => 4000, 'source' => QualifyingTime::SOURCE_CALCULATED]);
    $time50 = $time(50);
    $time100 = $time(100);
    $qualify = fn (Athlete $athlete, QualifyingTime $qualifyingTime, Result $result) => Qualification::create([
        'meet_id' => $meet->id, 'qualifying_time_list_id' => $list->id, 'qualifying_time_id' => $qualifyingTime->id,
        'athlete_id' => $athlete->id, 'result_id' => $result->id, 'sport_class' => 'S9',
        'swim_time_centiseconds' => 3300, 'qualified_at' => '2026-05-10']);
    $qualify($keep, $time50, result_am1(event_am1($meet, 1, 1), $keep));
    $qualify($drop, $time50, result_am1(event_am1($meet, 2, 1), $drop));
    $moved = $qualify($drop, $time100, result_am1(event_am1($meet, 3, 1), $drop));

    expect(service_am1()->analyze($keep, $drop)['qualifying_time_list_ids'])->toBe([$list->id]);

    service_am1()->merge($keep, $drop, [], []);

    expect(Qualification::where('athlete_id', $keep->id)->count())->toBe(2)
        ->and($moved->fresh()->athlete_id)->toBe($keep->id)
        ->and(Qualification::count())->toBe(2);
});

// ── Transaktion und Protokoll ────────────────────────────────────────────────

it('rollt bei einem Fehler alles zurück', function () {
    [$keep, $drop] = pair_am1();
    $result = result_am1(event_am1(meet_am1('Staatsmeisterschaften'), 1, 1), $drop);
    sportClass_am1($drop, 'S', '9');
    Athlete::saving(function () {
        throw new RuntimeException('Testfehler');
    });

    expect(/** @throws Throwable */ fn () => service_am1()->merge($keep, $drop, [], []))->toThrow(RuntimeException::class, 'Testfehler')
        ->and($result->fresh()->athlete_id)->toBe($drop->id)
        ->and(AthleteSportClass::where('athlete_id', $drop->id)->count())->toBe(1)
        ->and(Athlete::find($drop->id))->not->toBeNull();
});

it('protokolliert das Zusammenführen im Log', /** @throws Throwable */ function () {
    [$keep, $drop] = pair_am1();
    result_am1(event_am1(meet_am1('Staatsmeisterschaften'), 1, 1), $drop);
    $log = Log::spy();

    service_am1()->merge($keep, $drop, [], []);

    $log->shouldHaveReceived('info')->once()->withArgs(fn (string $message, array $context) => $message === 'Athleten zusammengeführt'
        && $context['keep']['id'] === $keep->id
        && $context['drop'] === ['id' => $drop->id, 'name' => 'Slavica, Djuza']
        && $context['moved'] === ['results' => 1]);
});
