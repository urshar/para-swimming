<?php

use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use App\Models\KaderType;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class)->group('kader-memberships-p1');

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeAdmin_km1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_km1(): User
{
    return User::factory()->create(['is_admin' => false]);
}

function makeAthlete_km1(string $lastName): Athlete
{
    $nation = Nation::firstOrCreate(
        ['code' => 'AUT'],
        ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
    );

    return Athlete::create([
        'first_name' => 'Max',
        'last_name' => $lastName,
        'gender' => 'M',
        'nation_id' => $nation->id,
        'is_active' => true,
    ]);
}

function makeKaderType_km1(string $code, int $sortOrder): KaderType
{
    return KaderType::create(['code' => $code, 'name_de' => ucfirst(strtolower($code)), 'sort_order' => $sortOrder, 'is_active' => true]);
}

function makeMembership_km1(Athlete $athlete, KaderType $type, ?string $from, ?string $until): AthleteKaderMembership
{
    return AthleteKaderMembership::create([
        'athlete_id' => $athlete->id,
        'kader_type_id' => $type->id,
        'valid_from' => $from,
        'valid_until' => $until,
        'notes' => null,
    ]);
}

// ── Bearbeiten ───────────────────────────────────────────────────────────────

describe('Kaderzugehörigkeit bearbeiten', function () {
    it('trägt ein Enddatum nach und ändert die Kaderart', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $intl = makeKaderType_km1('INTERNATIONAL', 2);
        $membership = makeMembership_km1($athlete, $world, '2012-05-01', null);

        $this->actingAs(makeAdmin_km1())
            ->put(route('athletes.kader-memberships.update', [$athlete, $membership]), [
                'kader_type_id' => $intl->id,
                'valid_from' => '2012-05-01',
                'valid_until' => '2025-09-29',
                'notes' => 'Herabgestuft',
            ])
            ->assertRedirect(route('athletes.show', ['athlete' => $athlete, 'reiter' => 'level-kader']))
            ->assertSessionHas('success');

        $membership->refresh();

        expect($membership->kader_type_id)->toBe($intl->id)
            ->and($membership->valid_until->toDateString())->toBe('2025-09-29')
            ->and($membership->notes)->toBe('Herabgestuft');
    });

    it('passt beim Bearbeiten keine anderen Einträge an', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $older = makeMembership_km1($athlete, $world, '2012-05-01', null);
        $newer = makeMembership_km1($athlete, $world, '2020-01-01', null);

        $this->actingAs(makeAdmin_km1())
            ->put(route('athletes.kader-memberships.update', [$athlete, $newer]), [
                'kader_type_id' => $world->id,
                'valid_from' => '2021-01-01',
                'valid_until' => null,
                'notes' => null,
            ]);

        expect($older->fresh()->valid_until)->toBeNull();
    });

    it('lehnt ein Enddatum vor dem Beginn ab', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $membership = makeMembership_km1($athlete, $world, '2020-01-01', null);

        $this->actingAs(makeAdmin_km1())
            ->put(route('athletes.kader-memberships.update', [$athlete, $membership]), [
                'kader_type_id' => $world->id,
                'valid_from' => '2020-01-01',
                'valid_until' => '2019-12-31',
            ])
            ->assertSessionHasErrors('valid_until');

        expect($membership->fresh()->valid_until)->toBeNull();
    });

    it('verbietet das Bearbeiten ohne Schreibrecht Athleten (alle)', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $membership = makeMembership_km1($athlete, $world, '2020-01-01', null);

        $this->actingAs(makeClubUser_km1())
            ->put(route('athletes.kader-memberships.update', [$athlete, $membership]), [
                'kader_type_id' => $world->id,
                'valid_until' => '2025-01-01',
            ])
            ->assertForbidden();

        expect($membership->fresh()->valid_until)->toBeNull();
    });

    it('liefert 404 für den Eintrag eines anderen Athleten', function () {
        $athlete = makeAthlete_km1('Huber');
        $other = makeAthlete_km1('Maier');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $membership = makeMembership_km1($other, $world, '2020-01-01', null);

        $this->actingAs(makeAdmin_km1())
            ->put(route('athletes.kader-memberships.update', [$athlete, $membership]), [
                'kader_type_id' => $world->id,
                'valid_until' => '2025-01-01',
            ])
            ->assertNotFound();
    });

    it('zeigt das Bearbeitungsformular auf dem Reiter "Level & Kader"', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $membership = makeMembership_km1($athlete, $world, '2020-01-01', null);

        $this->actingAs(makeAdmin_km1())
            ->get(route('athletes.show', ['athlete' => $athlete, 'reiter' => 'level-kader']))
            ->assertOk()
            ->assertSee(route('athletes.kader-memberships.update', [$athlete, $membership]), false)
            ->assertSee('Kaderzugehörigkeit bearbeiten');
    });
});

// ── Anlegen: Vorgänger automatisch abschließen ───────────────────────────────

describe('Kaderzugehörigkeit eintragen', function () {
    it('schließt den laufenden Vorgänger am Vortag des neuen Beginns ab', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $intl = makeKaderType_km1('INTERNATIONAL', 2);
        $previous = makeMembership_km1($athlete, $world, '2012-05-01', null);

        $this->actingAs(makeAdmin_km1())
            ->post(route('athletes.kader-memberships.store', $athlete), [
                'kader_type_id' => $intl->id,
                'valid_from' => '2025-09-30',
            ])
            ->assertSessionHas('success', 'Kaderzugehörigkeit eingetragen. Abgeschlossen: Weltklasse (bis 29.09.2025).');

        $new = AthleteKaderMembership::where('kader_type_id', $intl->id)->firstOrFail();

        expect($previous->fresh()->valid_until->toDateString())->toBe('2025-09-29')
            ->and($new->valid_from->toDateString())->toBe('2025-09-30')
            ->and($new->valid_until)->toBeNull()
            ->and($athlete->kaderMemberships()->activeOn('2025-09-29')->pluck('kader_type_id')->all())->toBe([$world->id])
            ->and($athlete->kaderMemberships()->activeOn('2025-10-01')->pluck('kader_type_id')->all())->toBe([$intl->id]);
    });

    it('nimmt ohne "Gültig ab" das heutige Datum', function () {
        Carbon::setTestNow('2026-11-08 10:00:00');

        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $intl = makeKaderType_km1('INTERNATIONAL', 2);
        $previous = makeMembership_km1($athlete, $world, '2012-05-01', null);

        $this->actingAs(makeAdmin_km1())
            ->post(route('athletes.kader-memberships.store', $athlete), [
                'kader_type_id' => $intl->id,
            ]);

        $new = AthleteKaderMembership::where('kader_type_id', $intl->id)->firstOrFail();

        expect($new->valid_from->toDateString())->toBe('2026-11-08')
            ->and($previous->fresh()->valid_until->toDateString())->toBe('2026-11-07');

        Carbon::setTestNow();
    });

    it('lässt bereits beendete und später beginnende Einträge unverändert', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $intl = makeKaderType_km1('INTERNATIONAL', 2);
        $ended = makeMembership_km1($athlete, $world, '2010-01-01', '2015-12-31');
        $later = makeMembership_km1($athlete, $world, '2030-01-01', null);

        $this->actingAs(makeAdmin_km1())
            ->post(route('athletes.kader-memberships.store', $athlete), [
                'kader_type_id' => $intl->id,
                'valid_from' => '2025-01-01',
            ])
            ->assertSessionHas('success', 'Kaderzugehörigkeit eingetragen.');

        expect($ended->fresh()->valid_until->toDateString())->toBe('2015-12-31')
            ->and($later->fresh()->valid_until)->toBeNull();
    });

    it('betrifft keine Einträge anderer Athleten', function () {
        $athlete = makeAthlete_km1('Huber');
        $other = makeAthlete_km1('Maier');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $otherMembership = makeMembership_km1($other, $world, '2012-05-01', null);

        $this->actingAs(makeAdmin_km1())
            ->post(route('athletes.kader-memberships.store', $athlete), [
                'kader_type_id' => $world->id,
                'valid_from' => '2025-01-01',
            ]);

        expect($otherMembership->fresh()->valid_until)->toBeNull();
    });
});

// ── Löschen: Vorgänger wieder öffnen ─────────────────────────────────────────

describe('Kaderzugehörigkeit löschen', function () {
    it('setzt den automatisch abgeschlossenen Vorgänger wieder auf laufend', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $intl = makeKaderType_km1('INTERNATIONAL', 2);
        $previous = makeMembership_km1($athlete, $world, '2012-05-01', '2025-09-29');
        $newest = makeMembership_km1($athlete, $intl, '2025-09-30', null);

        $this->actingAs(makeAdmin_km1())
            ->delete(route('athletes.kader-memberships.destroy', [$athlete, $newest]))
            ->assertSessionHas('success', 'Kaderzugehörigkeit gelöscht. Wieder laufend: Weltklasse (laufend).');

        expect(AthleteKaderMembership::find($newest->id))->toBeNull()
            ->and($previous->fresh()->valid_until)->toBeNull();
    });

    it('lässt einen absichtlich früher beendeten Vorgänger beendet', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $previous = makeMembership_km1($athlete, $world, '2012-05-01', '2018-12-31');
        $newest = makeMembership_km1($athlete, $world, '2025-01-01', null);

        $this->actingAs(makeAdmin_km1())
            ->delete(route('athletes.kader-memberships.destroy', [$athlete, $newest]))
            ->assertSessionHas('success', 'Kaderzugehörigkeit gelöscht.');

        expect($previous->fresh()->valid_until->toDateString())->toBe('2018-12-31');
    });

    it('öffnet nichts wieder, wenn ein älterer Eintrag gelöscht wird', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $intl = makeKaderType_km1('INTERNATIONAL', 2);
        $first = makeMembership_km1($athlete, $world, '2010-01-01', '2014-12-31');
        $middle = makeMembership_km1($athlete, $intl, '2015-01-01', '2019-12-31');
        makeMembership_km1($athlete, $world, '2020-01-01', null);

        $this->actingAs(makeAdmin_km1())
            ->delete(route('athletes.kader-memberships.destroy', [$athlete, $middle]));

        expect($first->fresh()->valid_until->toDateString())->toBe('2014-12-31');
    });

    it('macht Anlegen und Löschen zusammen rückgängig', function () {
        $athlete = makeAthlete_km1('Huber');
        $world = makeKaderType_km1('WELTKLASSE', 1);
        $intl = makeKaderType_km1('INTERNATIONAL', 2);
        $previous = makeMembership_km1($athlete, $world, '2012-05-01', null);
        $admin = makeAdmin_km1();

        $this->actingAs($admin)
            ->post(route('athletes.kader-memberships.store', $athlete), [
                'kader_type_id' => $intl->id,
                'valid_from' => '2025-09-30',
            ]);
        $new = AthleteKaderMembership::where('kader_type_id', $intl->id)->firstOrFail();

        $this->actingAs($admin)
            ->delete(route('athletes.kader-memberships.destroy', [$athlete, $new]));

        expect($previous->fresh()->valid_until)->toBeNull()
            ->and($athlete->kaderMemberships()->count())->toBe(1);
    });
});
