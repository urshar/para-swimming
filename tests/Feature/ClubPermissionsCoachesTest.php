<?php

use App\Enums\AccessScope;
use App\Enums\CoachLevel;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Livewire\Admin\UserManager;
use App\Models\AuditLog;
use App\Models\Club;
use App\Models\Coach;
use App\Models\CoachQualification;
use App\Models\User;
use App\Services\PermissionTemplateService;
use App\Support\AuditHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('club-permissions-p8');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Vereinsnutzer mit der Vorlage "Verein" (Trainer lesen und schreiben eigener Verein). */
function clubUser_cp8(Club $club): User
{
    return User::factory()->create(['club_id' => $club->id]);
}

function admin_cp8(): User
{
    return User::factory()->create(['is_admin' => true]);
}

function coach_cp8(Club $club, string $last = 'Trainer', bool $active = true): Coach
{
    return Coach::create(['club_id' => $club->id, 'first_name' => 'Tina', 'last_name' => $last, 'is_active' => $active]);
}

function education_cp8(Coach $coach, CoachLevel $level, string $date = '2024-05-01'): CoachQualification
{
    return $coach->qualifications()->create([
        'kind' => CoachQualification::KIND_EDUCATION, 'level' => $level, 'completed_on' => $date,
    ]);
}

// ── Pflege durch den Verein ───────────────────────────────────────────────────

it('lässt einen Verein Trainer im eigenen Verein anlegen und bearbeiten', function () {
    $club = makeClub_p5();
    $foreign = makeClub_p5();
    $user = clubUser_cp8($club);

    $this->actingAs($user)->get(route('coaches.create'))->assertOk()->assertSee('eigener Verein');

    // Ein fremder Verein im Request wird ignoriert: "eigener Verein" legt im eigenen Verein an.
    $this->actingAs($user)->post(route('coaches.store'), [
        'club_id' => $foreign->id, 'first_name' => 'Tina', 'last_name' => 'Neu', 'email' => 'tina@example.org',
        'is_active' => '1',
    ])->assertRedirect();

    $coach = Coach::sole();
    expect($coach->club_id)->toBe($club->id)
        ->and($coach->is_active)->toBeTrue();

    $this->actingAs($user)->put(route('coaches.update', $coach), ['first_name' => 'Tina', 'last_name' => 'Geändert'])
        ->assertRedirect(route('coaches.show', $coach));

    expect($coach->fresh()->last_name)->toBe('Geändert')
        ->and($coach->fresh()->is_active)->toBeFalse();
});

it('verweigert fremde Trainer und Benutzer ohne Trainer-Rechte', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $foreignCoach = coach_cp8(makeClub_p5());
    $user = clubUser_cp8($club);

    $this->actingAs($user)->get(route('coaches.show', $foreignCoach))->assertForbidden();
    $this->actingAs($user)->get(route('coaches.edit', $foreignCoach))->assertForbidden();
    $this->actingAs($user)->post(route('coaches.qualifications.store', $foreignCoach), [
        'kind' => CoachQualification::KIND_TRAINING, 'completed_on' => '2025-01-01',
    ])->assertForbidden();

    $reader = User::factory()->create(['club_id' => $club->id]);
    app(PermissionTemplateService::class)->setPermissions($reader, [
        Module::Coaches->value => ['read' => AccessScope::Own, 'write' => AccessScope::None],
    ]);
    $this->actingAs($reader->fresh())->get(route('coaches.create'))->assertForbidden();

    $coachTemplate = User::factory()->create(['club_id' => $club->id]);
    app(PermissionTemplateService::class)->applyTemplate($coachTemplate, RoleTemplate::Coach);
    $this->actingAs($coachTemplate->fresh())->get(route('coaches.index'))->assertForbidden();
});

it('zeigt in der Liste bei "eigener Verein" nur die eigenen Trainer, Admins alle', function () {
    $club = makeClub_p5();
    coach_cp8($club, 'Eigener');
    coach_cp8(makeClub_p5(), 'Fremder');

    $this->actingAs(clubUser_cp8($club))->get(route('coaches.index'))
        ->assertOk()
        ->assertSee('Eigener')
        ->assertDontSee('Fremder');
    $this->actingAs(admin_cp8())->get(route('coaches.index'))->assertSee('Eigener')->assertSee('Fremder');
});

// ── Aus- und Fortbildungen ────────────────────────────────────────────────────

it('leitet die Stufe aus der höchsten Ausbildung ab, Fortbildungen zählen nicht', function () {
    $coach = coach_cp8(makeClub_p5());
    expect($coach->level)->toBe(CoachLevel::None);

    education_cp8($coach, CoachLevel::In, '2022-01-01');
    education_cp8($coach, CoachLevel::Ul, '2024-01-01');
    $coach->qualifications()->create(['kind' => CoachQualification::KIND_TRAINING, 'completed_on' => '2025-01-01']);

    expect($coach->fresh()->level)->toBe(CoachLevel::In);
});

it('lässt den Verein Aus- und Fortbildungen eintragen und löschen', function () {
    $club = makeClub_p5();
    $coach = coach_cp8($club);
    $user = clubUser_cp8($club);

    $this->actingAs($user)->post(route('coaches.qualifications.store', $coach), [
        'kind' => CoachQualification::KIND_EDUCATION, 'completed_on' => '2025-03-01',
    ])->assertSessionHasErrors('level');

    $this->actingAs($user)->post(route('coaches.qualifications.store', $coach), [
        'kind' => CoachQualification::KIND_EDUCATION, 'level' => 'TR', 'title' => 'Trainerkurs', 'completed_on' => '2025-03-01',
    ])->assertRedirect(route('coaches.show', $coach));
    // Fortbildungen tragen keine Stufe, auch wenn eine mitgeschickt wird.
    $this->actingAs($user)->post(route('coaches.qualifications.store', $coach), [
        'kind' => CoachQualification::KIND_TRAINING, 'level' => 'UL', 'completed_on' => '2025-06-01',
    ])->assertRedirect(route('coaches.show', $coach));

    $training = $coach->qualifications()->where('kind', CoachQualification::KIND_TRAINING)->sole();
    expect($coach->fresh()->level)->toBe(CoachLevel::Tr)
        ->and($training->level)->toBeNull();

    $this->actingAs($user)->get(route('coaches.show', $coach))->assertSee('Trainerkurs')->assertSee('Trainer (TR)');

    $this->actingAs($user)->delete(route('coaches.qualifications.destroy', [$coach, $training]))
        ->assertRedirect(route('coaches.show', $coach));
    expect($coach->qualifications()->count())->toBe(1);
});

it('filtert die Liste nach der höchsten Stufe', function () {
    $club = makeClub_p5();
    education_cp8(coach_cp8($club, 'Uelvorn'), CoachLevel::Ul);
    $both = coach_cp8($club, 'Trbeides');
    education_cp8($both, CoachLevel::Ul);
    education_cp8($both, CoachLevel::Tr);
    coach_cp8($club, 'Ohnekurs');

    $admin = admin_cp8();
    $this->actingAs($admin)->get(route('coaches.index', ['level' => 'UL']))
        ->assertSee('Uelvorn')->assertDontSee('Trbeides')->assertDontSee('Ohnekurs');
    $this->actingAs($admin)->get(route('coaches.index', ['level' => 'TR']))
        ->assertSee('Trbeides')->assertDontSee('Uelvorn');
    $this->actingAs($admin)->get(route('coaches.index', ['level' => 'none']))
        ->assertSee('Ohnekurs')->assertDontSee('Uelvorn');
});

// ── Löschen und Verknüpfung ───────────────────────────────────────────────────

it('löscht einen Trainer nur ohne verknüpften Benutzer', function () {
    $club = makeClub_p5();
    $user = clubUser_cp8($club);
    $linked = coach_cp8($club, 'Verknuepft');
    User::factory()->create(['club_id' => $club->id, 'coach_id' => $linked->id]);
    $free = coach_cp8($club, 'Frei');
    education_cp8($free, CoachLevel::Ul);

    $this->actingAs($user)->delete(route('coaches.destroy', $linked))->assertSessionHas('error');
    expect(Coach::find($linked->id))->not->toBeNull();

    $this->actingAs($user)->delete(route('coaches.destroy', $free))->assertRedirect();
    expect(Coach::find($free->id))->toBeNull()
        ->and(CoachQualification::count())->toBe(0);
});

it('verknüpft in der Benutzerverwaltung einen Trainer des Vereins und schlägt die Vorlage vor', function () {
    $club = makeClub_p5();
    $coach = coach_cp8($club);
    $foreignCoach = coach_cp8(makeClub_p5(), 'Fremd');

    Livewire::actingAs(admin_cp8())->test(UserManager::class)
        ->call('openCreate')
        ->set('name', 'Tina Trainer')
        ->set('email', 'tina@example.test')
        ->set('password', 'Secret123!')
        ->set('club_id', $club->id)
        ->assertSet('role_template', RoleTemplate::Club->value)
        ->set('coach_id', $foreignCoach->id)
        ->call('save')
        ->assertHasErrors('coach_id')
        ->set('coach_id', $coach->id)
        ->assertSet('role_template', RoleTemplate::Coach->value)
        ->call('save')
        ->assertHasNoErrors();

    $user = User::where('email', 'tina@example.test')->sole();
    expect($user->coach_id)->toBe($coach->id)
        ->and($user->role_template)->toBe(RoleTemplate::Coach);

    // Ein zweiter Benutzer kann denselben Trainer nicht übernehmen; ein Vereinswechsel löst die Verknüpfung.
    Livewire::actingAs(admin_cp8())->test(UserManager::class)
        ->call('openCreate')
        ->set('name', 'Zweit')
        ->set('email', 'zweit@example.test')
        ->set('password', 'Secret123!')
        ->set('club_id', $club->id)
        ->set('coach_id', $coach->id)
        ->call('save')
        ->assertHasErrors('coach_id');

    Livewire::actingAs(admin_cp8())->test(UserManager::class)
        ->call('openEdit', $user->id)
        ->assertSet('coach_id', $coach->id)
        ->set('club_id', makeClub_p5()->id)
        ->assertSet('coach_id', null)
        ->call('save')
        ->assertHasNoErrors();
    expect($user->fresh()->coach_id)->toBeNull();
});

// ── Vereinsseite und Protokoll ────────────────────────────────────────────────

it('zeigt die Trainer auf der Vereinsseite nur mit Leserecht für diesen Verein', function () {
    $club = makeClub_p5();
    coach_cp8($club, 'Sichtbar');

    // Seit Phase 10b im Reiter "Trainer" (P10b-2); ohne Leserecht gibt es den Reiter nicht.
    $this->actingAs(clubUser_cp8($club))->get(route('clubs.show', [$club, 'reiter' => 'trainer']))->assertSee('Sichtbar');
    $this->actingAs(clubUser_cp8(makeClub_p5()))->get(route('clubs.show', [$club, 'reiter' => 'trainer']))
        ->assertOk()
        ->assertDontSee('Sichtbar');
});

it('protokolliert Trainer und Ausbildungen im Verlauf des Vereins', function () {
    $club = makeClub_p5();
    $coach = coach_cp8($club, 'Protokoll');
    education_cp8($coach, CoachLevel::Ul);

    $entries = collect(AuditHistory::build(AuditLog::forClub($club), true)['entries']);

    expect($entries->pluck('subject')->all())->toContain('Trainer: Protokoll Tina', 'Aus-/Fortbildung: Protokoll Tina')
        ->and($entries->pluck('changes')->flatten(1)->pluck('new'))->toContain('Übungsleiter (ÜL)', 'Ausbildung');
});

it('zeigt das ÖBSV Level eines Athleten weiter unter eigenem Namen, nicht als Trainerstufe', function () {
    $athlete = makeAthlete_p5(makeClub_p5());
    $athlete->update(['level' => 'TR']);

    $changes = collect(AuditHistory::build(AuditLog::forAthlete($athlete), true)['entries'])
        ->pluck('changes')->flatten(1)->firstWhere('label', 'ÖBSV Level');

    expect($changes)->not->toBeNull()
        ->and($changes['new'])->toBe('TR');
});
