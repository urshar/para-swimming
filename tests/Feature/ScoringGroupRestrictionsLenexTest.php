<?php

use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Services\LenexExportService;
use App\Services\LenexParserService;
use App\Services\LenexResolverService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('scoring-group-restrictions-p3');

// LENEX-Import und -Export der Einschränkung nach Landesverband und Level (docs/specs/scoring-group-restrictions.md,
// Phase 3). Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** LENEX-Import einer Datei, optional in eine bestehende Veranstaltung (Exception → RuntimeException). */
function importLenex_sgr3(string $xml, ?int $meetId): void
{
    $path = tempnam(sys_get_temp_dir(), 'lenex_sgr3').'.lef';
    file_put_contents($path, $xml);

    try {
        (new LenexParserService)->import($path, new LenexResolverService, $meetId);
    } catch (Exception $e) {
        throw new RuntimeException('LENEX-Import fehlgeschlagen: '.$e->getMessage(), previous: $e);
    } finally {
        unlink($path);
    }
}

/** LENEX-Ergebnisexport (DOMException → RuntimeException). */
function exportLenex_sgr3(Meet $meet): SimpleXMLElement
{
    try {
        return simplexml_load_string((new LenexExportService)->build($meet, 'results'));
    } catch (DOMException $e) {
        throw new RuntimeException('LENEX-Export fehlgeschlagen: '.$e->getMessage(), previous: $e);
    }
}

/** Strukturdatei nach dem Muster der LM Wien 2018: LM-, Jugend- und Gäste-Gruppen, dazu Level-Gruppen. */
function lmStructure_sgr3(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="LM-Test" city="Wien" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2018-11-10"><EVENTS>
  <EVENT eventid="1059" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS>
      <AGEGROUP agegroupid="1063" agemax="-1" agemin="-1" name="LM Wien SB 01 - 09" handicap="1,2,3,4,5,6,7,8,9" />
      <AGEGROUP agegroupid="1061" agemax="18" agemin="-1" name="LM Wien Jugend SB 01 - 09" handicap="1,2,3,4,5,6,7,8,9" />
      <AGEGROUP agegroupid="1069" agemax="-1" agemin="-1" name="Gäste SB 01 - 15" handicap="1,2,3,4,5,6,7,8,9,10,11,12,13,14,15" />
      <AGEGROUP agegroupid="1070" agemax="-1" agemin="-1" name="B-Wertung" levels="b, Elite Kader" />
      <AGEGROUP agegroupid="1071" agemax="-1" agemin="-1" name="Level A bis B" levelmin="A" levelmax="B" />
    </AGEGROUPS>
  </EVENT>
</EVENTS></SESSION></SESSIONS></MEET></MEETS></LENEX>';
}

// ── Namenserkennung ───────────────────────────────────────────────────────────

it('erkennt den Landesverband im Namen einer LM-Gruppe', function () {
    expect(ScoringGroup::associationFromName('LM Wien SB 01 - 09'))->toBe('WBSV')
        ->and(ScoringGroup::associationFromName('lm wien Jugend'))->toBe('WBSV')
        ->and(ScoringGroup::associationFromName('Wr. LM S01 - S10'))->toBe('WBSV')
        ->and(ScoringGroup::associationFromName('Wiener Landesmeisterschaft'))->toBe('WBSV')
        ->and(ScoringGroup::associationFromName('LM NÖ: S1 - S10'))->toBe('NOEVSV')
        ->and(ScoringGroup::associationFromName('LM Stmk. Jugend'))->toBe('STBSV')
        ->and(ScoringGroup::associationFromName('Landesmeisterschaften Tirol'))->toBe('TBSV')
        ->and(ScoringGroup::associationFromName('LM OOEBSV'))->toBe('OOEBSV')
        ->and(ScoringGroup::associationFromName('ÖSTM: S01 - S08'))->toBeNull()
        ->and(ScoringGroup::associationFromName('LM Wienerwald'))->toBeNull()
        ->and(ScoringGroup::associationFromName('Gäste SB 01 - 15'))->toBeNull()
        ->and(ScoringGroup::isGuestName('Gäste SB 01 - 15'))->toBeTrue()
        ->and(ScoringGroup::isGuestName('Gastschwimmer'))->toBeTrue()
        ->and(ScoringGroup::isGuestName('LM Wien'))->toBeFalse();
});

// ── Import ────────────────────────────────────────────────────────────────────

it('setzt beim Import Landesverband aus dem Namen und Level aus der Datei', function () {
    makeNation_p5();
    makeStrokeType_p5();
    $club = makeClub_p5();
    makeAthlete_p5($club)->update(['level' => 'A']);
    makeAthlete_p5($club)->update(['level' => 'B']);
    makeAthlete_p5($club)->update(['level' => 'T']);

    importLenex_sgr3(lmStructure_sgr3(), null);

    $groups = SwimEvent::firstOrFail()->scoringGroups()->get()->keyBy('lenex_agegroup_id');
    expect($groups['1063']->restrictionLabel())->toBe('nur WBSV')
        ->and($groups['1061']->restrictionLabel())->toBe('nur WBSV')
        ->and($groups['1069']->restrictionLabel())->toBe('ohne WBSV')
        ->and($groups['1069']->regional_mode)->toBe(ScoringGroup::REGION_EXCEPT)
        ->and($groups['1070']->levels)->toBe('b,Elite Kader')
        ->and($groups['1071']->levels)->toBe('A,B'); // T liegt außerhalb von A bis B
});

it('lässt manuell gesetzte Angaben beim erneuten Import stehen', function () {
    makeNation_p5();
    makeStrokeType_p5();

    importLenex_sgr3(lmStructure_sgr3(), null);
    $event = SwimEvent::firstOrFail();
    // Manuell: LM auch für Niederösterreich, Gäste ohne Einschränkung, Level der B-Wertung ändert die Datei.
    $event->scoringGroups()->where('lenex_agegroup_id', '1063')->update(['regional_associations' => 'NOEVSV,WBSV']);
    $event->scoringGroups()->where('lenex_agegroup_id', '1071')->update(['levels' => 'T']);

    importLenex_sgr3(lmStructure_sgr3(), Meet::value('id'));

    $groups = $event->scoringGroups()->get()->keyBy('lenex_agegroup_id');
    expect($groups)->toHaveCount(5)
        ->and($groups['1063']->regional_associations)->toBe('NOEVSV,WBSV')
        ->and($groups['1069']->regional_associations)->toBe('WBSV')
        ->and($groups['1070']->levels)->toBe('b,Elite Kader')
        ->and($groups['1071']->levels)->toBe('T'); // Bereich trifft ohne vergebene Level nichts → bleibt
});

it('setzt Gäste-Gruppen ohne LM-Gruppe nicht', function () {
    $event = makeEvent_p5(makeMeet_p5());
    $guest = ScoringGroup::create(['swim_event_id' => $event->id, 'name' => 'Gäste', 'gender' => 'A']);
    $open = ScoringGroup::create(['swim_event_id' => $event->id, 'name' => 'ÖM: S1 - S10', 'gender' => 'A']);

    LenexParserService::restrictByName([$guest, $open]);

    expect($guest->fresh()->regional_associations)->toBeNull();
});

// ── Export ────────────────────────────────────────────────────────────────────

it('exportiert die Level der Wertungsgruppe und Ranglisten nur der Zugehörigen', function () {
    $meet = makeMeet_p5();
    $event = makeEvent_p5($meet);
    ScoringGroup::create([
        'swim_event_id' => $event->id, 'name' => 'LM Wien', 'gender' => 'A', 'sort_order' => 1,
        'regional_associations' => 'WBSV', 'regional_mode' => ScoringGroup::REGION_ONLY, 'levels' => 'B,Elite Kader',
    ]);
    $wien = makeClub_p5();
    $wien->update(['regional_association' => 'WBSV']);
    $salzburg = makeClub_p5();
    $salzburg->update(['regional_association' => 'SBSV']);
    $result = function (Club $club, int $points) use ($meet, $event): Result {
        $athlete = makeAthlete_p5($club);
        $athlete->update(['level' => 'B']);

        return Result::create([
            'meet_id' => $meet->id, 'swim_event_id' => $event->id, 'athlete_id' => $athlete->id,
            'club_id' => $club->id, 'swim_time' => 10000 - $points, 'points' => $points, 'sport_class' => 'S9',
        ]);
    };
    $result($salzburg, 700);
    $wienResult = $result($wien, 600);

    $ageGroup = exportLenex_sgr3($meet)->MEETS->MEET->SESSIONS->SESSION->EVENTS->EVENT->AGEGROUPS->AGEGROUP;

    expect((string) $ageGroup['levels'])->toBe('B,Elite Kader')
        ->and($ageGroup->RANKINGS->RANKING)->toHaveCount(1)
        ->and((string) $ageGroup->RANKINGS->RANKING['resultid'])->toBe((string) $wienResult->id)
        ->and((string) $ageGroup->RANKINGS->RANKING['place'])->toBe('1');
});
