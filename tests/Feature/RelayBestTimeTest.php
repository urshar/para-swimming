<?php

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\Result;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('relay-entry-time-suggestion');

// Nutzt die globalen _p5-Helper (tests/helpers_p5.php): makeClub_p5, makeMeet_p5,
// makeAthlete_p5, makeEvent_p5, makeStrokeType_p5, makeNation_p5.

function clubUser_rbt(int $clubId): User
{
    return User::forceCreate([
        'name' => 'Club RBT',
        'email' => 'club_rbt@example.test',
        'password' => bcrypt('secret'),
        'is_admin' => false,
        'club_id' => $clubId,
    ]);
}

/**
 * Historische Einzel-Bestzeit: eigenes Meet (Datum + Kurs), eigenes Einzel-Event der
 * gewünschten Teilstrecken-Disziplin (Distanz + Stil, relay_count = 1).
 */
function resultRbt(Athlete $athlete, Club $club, int $distance, int $strokeTypeId, string $course, string $date, int $time): void
{
    $meet = Meet::create([
        'name' => 'Hist '.$date.'-'.$strokeTypeId.'-'.$time,
        'nation_id' => makeNation_p5()->id,
        'course' => $course,
        'start_date' => $date,
    ]);
    $event = makeEvent_p5($meet, [
        'distance' => $distance,
        'stroke_type_id' => $strokeTypeId,
        'relay_count' => 1,
        'gender' => $athlete->gender,
    ]);
    Result::create([
        'meet_id' => $meet->id,
        'swim_event_id' => $event->id,
        'athlete_id' => $athlete->id,
        'club_id' => $club->id,
        'swim_time' => $time,
        'status' => null,
    ]);
}

describe('relay-best-time Endpoint', function () {
    it('summiert Freistil-Bestzeiten (Jahres + absolut) je Kurs', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5(); // start_date 2025-06-15 → Jahresfenster 2024-01-01 .. 2025-06-14
        $meet->update(['is_open' => true]);

        $free = makeStrokeType_p5();
        $relayEvent = makeEvent_p5($meet, [
            'stroke_type_id' => $free->id,
            'distance' => 50,
            'relay_count' => 4,
        ]);

        $a = collect(range(1, 4))->map(fn () => makeAthlete_p5($club));

        // LCM Freistil 50m im Jahresfenster (2024): 30.00 / 31.00 / 32.00 / 33.00
        resultRbt($a[0], $club, 50, $free->id, 'LCM', '2024-08-10', 3000);
        resultRbt($a[1], $club, 50, $free->id, 'LCM', '2024-08-10', 3100);
        resultRbt($a[2], $club, 50, $free->id, 'LCM', '2024-08-10', 3200);
        resultRbt($a[3], $club, 50, $free->id, 'LCM', '2024-08-10', 3300);
        // Ältere, schnellere Zeit von Schwimmer 1 (nur absolut relevant): 29.00
        resultRbt($a[0], $club, 50, $free->id, 'LCM', '2023-05-01', 2900);

        $this->actingAs(clubUser_rbt($club->id))
            ->getJson(route('club-entries.relay.relay-best-time', [
                'meet' => $meet,
                'event_id' => $relayEvent->id,
                'athlete_ids' => $a->pluck('id')->all(),
            ]))
            ->assertOk()
            ->assertJson([
                'LCM' => [
                    'year' => ['raw' => 12600, 'formatted' => '02:06.00', 'missing' => 0, 'total' => 4],
                    'absolute' => ['raw' => 12500, 'formatted' => '02:05.00', 'missing' => 0, 'total' => 4],
                ],
                'SCM' => [
                    'year' => ['raw' => null, 'formatted' => 'NT', 'missing' => 4, 'total' => 4],
                    'absolute' => ['raw' => null, 'formatted' => 'NT', 'missing' => 4, 'total' => 4],
                ],
            ]);
    });

    it('liefert je Kurs eine per-Schwimmer-Aufschlüsselung (legs) für die gemischte Summe', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);

        $free = makeStrokeType_p5();
        $relayEvent = makeEvent_p5($meet, ['stroke_type_id' => $free->id, 'distance' => 50, 'relay_count' => 4]);

        $a1 = makeAthlete_p5($club);
        $a2 = makeAthlete_p5($club);
        resultRbt($a1, $club, 50, $free->id, 'LCM', '2024-08-10', 3000); // Jahresfenster
        resultRbt($a1, $club, 50, $free->id, 'LCM', '2023-05-01', 2900); // älter → nur absolut
        resultRbt($a2, $club, 50, $free->id, 'LCM', '2024-08-10', 3100);

        $json = $this->actingAs(clubUser_rbt($club->id))
            ->getJson(route('club-entries.relay.relay-best-time', [
                'meet' => $meet,
                'event_id' => $relayEvent->id,
                'athlete_ids' => [$a1->id, $a2->id],
            ]))
            ->assertOk()
            ->json();

        $legs = $json['LCM']['legs'];
        expect($legs)->toHaveCount(2)
            ->and($legs[0]['athlete_id'])->toBe($a1->id)
            ->and($legs[0]['year']['raw'])->toBe(3000)
            ->and($legs[0]['year']['date'])->toBe('10.08.2024')
            ->and($legs[0]['absolute']['raw'])->toBe(2900)
            ->and($legs[1]['athlete_id'])->toBe($a2->id)
            ->and($legs[1]['year']['raw'])->toBe(3100);
    });

    it('weist eine Teilsumme mit fehlenden Zeiten aus', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);

        $free = makeStrokeType_p5();
        $relayEvent = makeEvent_p5($meet, [
            'stroke_type_id' => $free->id,
            'distance' => 50,
            'relay_count' => 4,
        ]);

        $a1 = makeAthlete_p5($club);
        $a2 = makeAthlete_p5($club);
        $a3 = makeAthlete_p5($club); // hat KEINE Zeit

        resultRbt($a1, $club, 50, $free->id, 'LCM', '2024-08-10', 3000);
        resultRbt($a2, $club, 50, $free->id, 'LCM', '2024-08-10', 3100);

        // Drei Positionen belegt, aber nur zwei mit Zeit → Summe 6100, 2 von 4 fehlen
        $this->actingAs(clubUser_rbt($club->id))
            ->getJson(route('club-entries.relay.relay-best-time', [
                'meet' => $meet,
                'event_id' => $relayEvent->id,
                'athlete_ids' => [$a1->id, $a2->id, $a3->id],
            ]))
            ->assertOk()
            ->assertJson([
                'LCM' => [
                    'absolute' => ['raw' => 6100, 'missing' => 2, 'total' => 4],
                ],
            ]);
    });

    it('ordnet bei Lagenstaffeln den Stil der Startposition zu', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);

        $medley = makeStrokeType_p5('MEDLEY');
        $back = makeStrokeType_p5('BACK');
        $breast = makeStrokeType_p5('BREAST');
        $fly = makeStrokeType_p5('FLY');
        $free = makeStrokeType_p5();

        $relayEvent = makeEvent_p5($meet, [
            'stroke_type_id' => $medley->id,
            'distance' => 50,
            'relay_count' => 4,
        ]);

        $a1 = makeAthlete_p5($club); // Position 1 → Rücken
        $a2 = makeAthlete_p5($club); // Position 2 → Brust
        $a3 = makeAthlete_p5($club); // Position 3 → Schmetterling
        $a4 = makeAthlete_p5($club); // Position 4 → Freistil

        // Schwimmer 1 hat sowohl Rücken (30.00) als auch eine schnellere Freistil-Zeit (10.00):
        // Für Position 1 MUSS die Rücken-Zeit gezogen werden, nicht die Freistil-Zeit.
        resultRbt($a1, $club, 50, $back->id, 'LCM', '2024-08-10', 3000);
        resultRbt($a1, $club, 50, $free->id, 'LCM', '2024-08-10', 1000);
        resultRbt($a2, $club, 50, $breast->id, 'LCM', '2024-08-10', 3200);
        resultRbt($a3, $club, 50, $fly->id, 'LCM', '2024-08-10', 3400);
        resultRbt($a4, $club, 50, $free->id, 'LCM', '2024-08-10', 3600);

        // 3000 + 3200 + 3400 + 3600 = 13200 (02:12.00); bei falschem Stil-Mapping käme etwas anderes.
        $this->actingAs(clubUser_rbt($club->id))
            ->getJson(route('club-entries.relay.relay-best-time', [
                'meet' => $meet,
                'event_id' => $relayEvent->id,
                'athlete_ids' => [$a1->id, $a2->id, $a3->id, $a4->id],
            ]))
            ->assertOk()
            ->assertJson([
                'LCM' => [
                    'absolute' => ['raw' => 13200, 'formatted' => '02:12.00', 'missing' => 0, 'total' => 4],
                ],
            ]);
    });

    it('nutzt bei Lagen-Staffeln (jeder alle Stile) die Einzel-Lagenzeit', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);

        $imrelay = makeStrokeType_p5('IMRELAY');
        $medley = makeStrokeType_p5('MEDLEY');

        $relayEvent = makeEvent_p5($meet, [
            'stroke_type_id' => $imrelay->id,
            'distance' => 100,
            'relay_count' => 4,
        ]);

        $a1 = makeAthlete_p5($club);
        $a2 = makeAthlete_p5($club);

        // Einzel-Lagen (MEDLEY) 100m: 1:10.00 + 1:12.00 = 2:22.00; nur 2 von 4 belegt.
        resultRbt($a1, $club, 100, $medley->id, 'LCM', '2024-08-10', 7000);
        resultRbt($a2, $club, 100, $medley->id, 'LCM', '2024-08-10', 7200);

        $this->actingAs(clubUser_rbt($club->id))
            ->getJson(route('club-entries.relay.relay-best-time', [
                'meet' => $meet,
                'event_id' => $relayEvent->id,
                'athlete_ids' => [$a1->id, $a2->id],
            ]))
            ->assertOk()
            ->assertJson([
                'LCM' => [
                    'absolute' => ['raw' => 14200, 'formatted' => '02:22.00', 'missing' => 2, 'total' => 4],
                ],
            ]);
    });
});

describe('relay-athletes: Filter-Daten', function () {
    it('liefert nur aktive Athleten und je Athlet Geschlecht + Sportklassen', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);
        $relayEvent = makeEvent_p5($meet, ['stroke_type_id' => makeStrokeType_p5()->id, 'relay_count' => 4, 'gender' => 'M']);

        $active = makeAthlete_p5($club, 'M', ['S14']);
        $inactive = makeAthlete_p5($club, 'M', ['S14']);
        $inactive->update(['is_active' => false]);

        $json = $this->actingAs(clubUser_rbt($club->id))
            ->getJson(route('club-entries.relay.relay-athletes', ['meet' => $meet, 'event_id' => $relayEvent->id]))
            ->assertOk()
            ->json();

        expect($json)->toHaveCount(1)
            ->and($json[0]['id'])->toBe($active->id)
            ->and($json[0]['gender'])->toBe('M')
            ->and($json[0]['classes'])->toContain('S14');
    });
});

describe('Staffel-Formulare: Vorschlags-Panel', function () {
    it('verdrahtet Panel + Endpoint im create-relay-Formular', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);
        makeEvent_p5($meet, ['stroke_type_id' => makeStrokeType_p5()->id, 'relay_count' => 4]);

        $html = $this->actingAs(clubUser_rbt($club->id))
            ->get(route('club-entries.relay.create', $meet))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('relay-best-time')
            ->toContain('applyRelayTime(')
            ->toContain('Vorschlag Jahresbestzeit')
            ->toContain('Vorschlag absolute Bestzeit')
            ->toContain('Zeiten fehlen')
            // Gemischte Summe + per-Schwimmer-Umschalter
            ->toContain('Gemischte Summe')
            ->toContain('setLegMode(')
            ->toContain('legTimeLabel(')
            // Picker-Filter (Geschlecht + Sportklassen-Mehrfachauswahl)
            ->toContain('Alle Geschlechter')
            ->toContain('Sportklassen:')
            ->toContain('toggleClass(')
            ->toContain('filteredAvailableAthletes(');
    });

    it('verdrahtet Panel + Endpoint im edit-relay-Formular', function () {
        $club = makeClub_p5();
        $meet = makeMeet_p5();
        $meet->update(['is_open' => true]);
        $relayEvent = makeEvent_p5($meet, ['stroke_type_id' => makeStrokeType_p5()->id, 'relay_count' => 4]);

        $relayEntry = RelayEntry::create([
            'meet_id' => $meet->id,
            'swim_event_id' => $relayEvent->id,
            'club_id' => $club->id,
            'entry_course' => 'LCM',
            'status' => 'pending',
        ]);

        $html = $this->actingAs(clubUser_rbt($club->id))
            ->get(route('club-entries.relay.edit', [$meet, $relayEntry]))
            ->assertOk()
            ->getContent();

        expect($html)
            ->toContain('relay-best-time')
            ->toContain('applyRelayTime(')
            ->toContain('Vorschlag Jahresbestzeit')
            ->toContain('Zeiten fehlen')
            ->toContain('Gemischte Summe')
            ->toContain('setLegMode(')
            ->toContain('legTimeLabel(');
    });
});
