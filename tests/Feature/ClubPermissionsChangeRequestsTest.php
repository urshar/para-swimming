<?php

use App\Enums\AccessScope;
use App\Enums\Module;
use App\Enums\RoleTemplate;
use App\Models\Athlete;
use App\Models\AthleteClubHistory;
use App\Models\AuditLog;
use App\Models\ChangeRequest;
use App\Models\Club;
use App\Models\User;
use App\Notifications\AthleteTransferredAway;
use App\Notifications\ChangeRequestDecided;
use App\Notifications\ChangeRequestSubmitted;
use App\Services\PermissionTemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class)->group('club-permissions-p6');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

beforeEach(function () {
    Notification::fake();
});

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Vereinsnutzer mit der Vorlage "Verein" (Athleten schreiben eigener Verein). */
function clubUser_cp6(Club $club): User
{
    return User::factory()->create(['club_id' => $club->id]);
}

function admin_cp6(): User
{
    return User::factory()->create(['is_admin' => true, 'name' => 'Verbandsadmin']);
}

function athlete_cp6(Club $club, string $first = 'Anna', string $last = 'Alt', string $birth = '2009-03-04'): Athlete
{
    $athlete = Athlete::create([
        'first_name' => $first, 'last_name' => $last, 'gender' => 'F', 'birth_date' => $birth,
        'nation_id' => makeNation_p5()->id, 'club_id' => $club->id,
    ]);
    AthleteClubHistory::create([
        'athlete_id' => $athlete->id, 'club_id' => $club->id, 'joined_at' => '2020-01-01', 'is_active' => true,
    ]);

    return $athlete;
}

/** @return array<string, string> */
function transferPayload_cp6(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Anna',
        'last_name' => 'Alt',
        'birth_date' => '2009-03-04',
        'joined_at' => '2026-11-01',
        'reason' => 'Wohnortwechsel',
    ], $overrides);
}

// ── Namensänderung ────────────────────────────────────────────────────────────

it('lässt einen Verein eine Namensänderung beantragen und informiert die Admins', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $athlete = athlete_cp6($club);
    $admin = admin_cp6();

    $this->actingAs(clubUser_cp6($club))
        ->post(route('change-requests.name.store', $athlete), [
            'first_name' => 'Anna', 'last_name' => 'Neu', 'reason' => 'Heirat',
        ])
        ->assertRedirect(route('athletes.show', $athlete));

    $request = ChangeRequest::sole();

    expect($request->type)->toBe(ChangeRequest::TYPE_NAME)
        ->and($request->status)->toBe(ChangeRequest::STATUS_OPEN)
        ->and($request->values)->toBe(['first_name' => 'Anna', 'last_name' => 'Neu', 'name_prefix' => null])
        ->and($request->club_id)->toBe($club->id)
        ->and($athlete->fresh()->last_name)->toBe('Alt');
    Notification::assertSentTo($admin, ChangeRequestSubmitted::class);
});

it('verhindert einen zweiten offenen Antrag derselben Art, nach Rückziehen geht es wieder', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $athlete = athlete_cp6($club);
    $user = clubUser_cp6($club);
    $payload = ['first_name' => 'Anna', 'last_name' => 'Neu', 'reason' => 'Heirat'];

    $this->actingAs($user)->post(route('change-requests.name.store', $athlete), $payload);
    $this->actingAs($user)->post(route('change-requests.name.store', $athlete), $payload)
        ->assertSessionHasErrors('athlete');

    $this->actingAs($user)->post(route('change-requests.withdraw', ChangeRequest::sole()))
        ->assertRedirect(route('change-requests.index'));
    $this->actingAs($user)->post(route('change-requests.name.store', $athlete), $payload)
        ->assertSessionHasNoErrors();

    expect(ChangeRequest::orderBy('id')->pluck('status')->all())->toBe([ChangeRequest::STATUS_WITHDRAWN, ChangeRequest::STATUS_OPEN]);
});

it('lehnt einen unveränderten Namen ab', function () {
    $club = makeClub_p5();
    $athlete = athlete_cp6($club);

    $this->actingAs(clubUser_cp6($club))
        ->post(route('change-requests.name.store', $athlete), ['first_name' => 'Anna', 'last_name' => 'Alt', 'reason' => 'x'])
        ->assertSessionHasErrors('first_name');

    expect(ChangeRequest::count())->toBe(0);
});

it('übernimmt den Namen bei Freigabe und informiert den Antragsteller', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $athlete = athlete_cp6($club);
    $user = clubUser_cp6($club);
    $this->actingAs($user)->post(route('change-requests.name.store', $athlete),
        ['first_name' => 'Anna', 'last_name' => 'Neu', 'name_prefix' => 'von', 'reason' => 'Heirat']);
    $request = ChangeRequest::sole();

    $this->actingAs(admin_cp6())
        ->post(route('admin.change-requests.approve', $request), ['response' => 'Passt.'])
        ->assertRedirect(route('admin.change-requests.index'));

    $athlete->refresh();
    $request->refresh();

    expect($athlete->last_name)->toBe('Neu')
        ->and($athlete->name_prefix)->toBe('von')
        ->and($request->status)->toBe(ChangeRequest::STATUS_APPROVED)
        ->and($request->decided_by_name)->toBe('Verbandsadmin')
        ->and($request->response)->toBe('Passt.')
        ->and(AuditLog::forAthlete($athlete)->where('auditable_type', Athlete::class)->where('event', 'updated')->exists())->toBeTrue()
        ->and(AuditLog::forAthlete($athlete)->where('auditable_type', ChangeRequest::class)->count())->toBe(2);
    Notification::assertSentTo($user, ChangeRequestDecided::class);
});

it('verlangt beim Ablehnen eine Antwort an den Verein', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $athlete = athlete_cp6($club);
    $user = clubUser_cp6($club);
    $this->actingAs($user)->post(route('change-requests.name.store', $athlete),
        ['first_name' => 'Anna', 'last_name' => 'Neu', 'reason' => 'Heirat']);
    $request = ChangeRequest::sole();
    $admin = admin_cp6();

    $this->actingAs($admin)->post(route('admin.change-requests.reject', $request))
        ->assertSessionHasErrors('response');
    $this->actingAs($admin)->post(route('admin.change-requests.reject', $request), ['response' => 'Nachweis fehlt.'])
        ->assertRedirect(route('admin.change-requests.index'));

    expect($request->fresh()->status)->toBe(ChangeRequest::STATUS_REJECTED)
        ->and($athlete->fresh()->last_name)->toBe('Alt');
    Notification::assertSentTo($user, ChangeRequestDecided::class);

    $this->actingAs($admin)->post(route('admin.change-requests.approve', $request))
        ->assertSessionHasErrors('decision');
});

// ── Ummeldung ─────────────────────────────────────────────────────────────────

it('stellt eine Ummeldung nur bei genau einem Treffer, sonst mit neutraler Meldung', function () {
    $from = makeClub_p5();
    $to = makeClub_p5();
    athlete_cp6($from);
    athlete_cp6(makeClub_p5(), 'Bert', 'Doppel', '2008-01-01');
    athlete_cp6(makeClub_p5(), 'Bert', 'Doppel', '2008-01-01');
    $user = clubUser_cp6($to);
    $neutral = 'Kein eindeutiger Athlet gefunden – bitte Angaben prüfen oder an den Verband wenden.';

    $this->actingAs($user)->post(route('change-requests.transfer.store'), transferPayload_cp6(['birth_date' => '2009-03-05']))
        ->assertSessionHasErrors(['athlete' => $neutral]);
    $this->actingAs($user)->post(route('change-requests.transfer.store'),
        transferPayload_cp6(['first_name' => 'Bert', 'last_name' => 'Doppel', 'birth_date' => '2008-01-01']))
        ->assertSessionHasErrors(['athlete' => $neutral]);

    expect(ChangeRequest::count())->toBe(0);

    $this->actingAs($user)->post(route('change-requests.transfer.store'), transferPayload_cp6(['last_name' => ' alt ']))
        ->assertRedirect(route('change-requests.index'));

    $request = ChangeRequest::sole();

    expect($request->type)->toBe(ChangeRequest::TYPE_TRANSFER)
        ->and($request->club_id)->toBe($to->id)
        ->and($request->from_club_id)->toBe($from->id)
        ->and($request->values)->toBe(['joined_at' => '2026-11-01']);
});

it('lehnt eine Ummeldung eines Athleten des eigenen Vereins ab', function () {
    $club = makeClub_p5();
    athlete_cp6($club);

    $this->actingAs(clubUser_cp6($club))->post(route('change-requests.transfer.store'), transferPayload_cp6())
        ->assertSessionHasErrors(['athlete' => 'Der Athlet gehört bereits zum eigenen Verein.']);
});

it('meldet bei Freigabe um, mit geändertem Eintrittsdatum, und informiert den bisherigen Verein', /** @throws Throwable */ function () {
    $from = makeClub_p5();
    $to = makeClub_p5();
    $athlete = athlete_cp6($from);
    $requester = clubUser_cp6($to);
    $oldClubWriter = clubUser_cp6($from);
    $oldClubReader = User::factory()->create(['club_id' => $from->id, 'role_template' => RoleTemplate::Empty]);
    app(PermissionTemplateService::class)->setPermissions($oldClubReader, [
        Module::Athletes->value => ['read' => AccessScope::Own, 'write' => AccessScope::None],
    ]);
    $this->actingAs($requester)->post(route('change-requests.transfer.store'), transferPayload_cp6());
    $request = ChangeRequest::sole();

    $this->actingAs(admin_cp6())
        ->post(route('admin.change-requests.approve', $request), ['joined_at' => '2026-12-01'])
        ->assertRedirect(route('admin.change-requests.index'));

    $athlete->refresh();

    expect($athlete->club_id)->toBe($to->id)
        ->and(AthleteClubHistory::where('athlete_id', $athlete->id)->where('is_active', true)->sole()->joined_at->format('Y-m-d'))->toBe('2026-12-01')
        ->and($request->fresh()->values)->toBe(['joined_at' => '2026-12-01'])
        ->and(AuditLog::forAthlete($athlete)->where('event', 'transferred')->exists())->toBeTrue();
    Notification::assertSentTo($requester, ChangeRequestDecided::class);
    Notification::assertSentTo($oldClubWriter, AthleteTransferredAway::class);
    Notification::assertNotSentTo($oldClubReader, AthleteTransferredAway::class);
});

// ── Rechte und Anzeige ────────────────────────────────────────────────────────

it('verweigert Anträge für fremde Athleten, reinen Lesern und das Entscheiden durch Vereine', /** @throws Throwable */ function () {
    $club = makeClub_p5();
    $foreign = athlete_cp6(makeClub_p5(), 'Fritz', 'Fremd');
    $own = athlete_cp6($club);
    $user = clubUser_cp6($club);
    $reader = User::factory()->create(['club_id' => $club->id, 'role_template' => RoleTemplate::Empty]);
    app(PermissionTemplateService::class)->setPermissions($reader, [
        Module::Athletes->value => ['read' => AccessScope::Own, 'write' => AccessScope::None],
    ]);
    $request = ChangeRequest::create([
        'type' => ChangeRequest::TYPE_NAME, 'athlete_id' => $own->id, 'club_id' => makeClub_p5()->id,
        'values' => ['first_name' => 'X', 'last_name' => 'Y'], 'reason' => 'r',
    ]);

    $this->actingAs($user)->get(route('change-requests.name.create', $foreign))->assertForbidden();
    $this->actingAs($reader)->get(route('change-requests.index'))->assertForbidden();
    $this->actingAs($reader->fresh())->get(route('change-requests.transfer.create'))->assertForbidden();
    $this->actingAs($user)->post(route('change-requests.withdraw', $request))->assertForbidden();
    $this->actingAs($user)->get(route('admin.change-requests.index'))->assertForbidden();
    $this->actingAs($user)->post(route('admin.change-requests.approve', $request))->assertForbidden();
    $this->actingAs(admin_cp6())->get(route('change-requests.transfer.create'))->assertForbidden();
});

it('zeigt Admins den Eingang mit Zähler und Vereinen nur ihre Anträge', function () {
    $club = makeClub_p5();
    $other = makeClub_p5();
    $athlete = athlete_cp6($club);
    $user = clubUser_cp6($club);
    $this->actingAs($user)->post(route('change-requests.name.store', $athlete),
        ['first_name' => 'Anna', 'last_name' => 'Neu', 'reason' => 'Heirat']);
    $this->actingAs(clubUser_cp6($other))->post(route('change-requests.transfer.store'), transferPayload_cp6());
    $admin = admin_cp6();

    $this->actingAs($admin)->get(route('admin.change-requests.index'))
        ->assertOk()
        ->assertSee('Namensänderung')
        ->assertSee('Ummeldung')
        ->assertSee('Anträge');
    $this->actingAs($admin)->get(route('admin.change-requests.show', ChangeRequest::where('type', ChangeRequest::TYPE_NAME)->sole()))
        ->assertOk()
        ->assertSee('Heirat')
        ->assertSee('Freigeben');

    $this->actingAs($user)->get(route('change-requests.index'))
        ->assertOk()
        ->assertSee('Namensänderung')
        ->assertDontSee('Ummeldung</', false);
    $this->actingAs($user)->get(route('athletes.show', $athlete))
        ->assertOk()
        ->assertSee('Offener Antrag: Namensänderung')
        ->assertDontSee('Offener Antrag: Ummeldung')
        ->assertSee(route('change-requests.name.create', $athlete));
});

it('rendert die drei E-Mails auf Deutsch mit Link', function () {
    $from = makeClub_p5();
    $to = makeClub_p5();
    $athlete = athlete_cp6($from);
    $request = ChangeRequest::create([
        'type' => ChangeRequest::TYPE_TRANSFER, 'athlete_id' => $athlete->id, 'club_id' => $to->id,
        'from_club_id' => $from->id, 'values' => ['joined_at' => '2026-11-01'], 'reason' => 'Wohnortwechsel',
        'status' => ChangeRequest::STATUS_APPROVED, 'response' => 'Willkommen.',
    ]);

    $submitted = (string) (new ChangeRequestSubmitted($request))->toMail()->render();
    $decided = (string) (new ChangeRequestDecided($request))->toMail()->render();
    $away = (string) (new AthleteTransferredAway($request))->toMail()->render();

    expect($submitted)->toContain('Wohnortwechsel')
        ->and($submitted)->toContain(route('admin.change-requests.show', $request))
        ->and($decided)->toContain('freigegeben')
        ->and($decided)->toContain('Willkommen.')
        ->and($away)->toContain('01.11.2026')
        ->and($away)->toContain('umgemeldet');
});
