<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class)->group('lenex-import-error-feedback');

// Ein fehlgeschlagener LENEX-Import meldet den Fehler sichtbar (zentrale Flash-Meldung), statt still auf die
// Upload-Seite zurückzufallen. Die Import-Session bleibt dabei erhalten.

function admin_lief(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function resultXml_lief(): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0"><MEETS><MEET name="Fehlertest" city="Wien" nation="AUT" course="SCM" startdate="2014-11-22">
<SESSIONS><SESSION number="1" date="2014-11-22"><EVENTS>
  <EVENT eventid="1058" number="1" round="TIM"><SWIMSTYLE distance="50" relaycount="1" stroke="FREE" />
    <AGEGROUPS><AGEGROUP agegroupid="1" gender="F" handicap="9" /></AGEGROUPS></EVENT>
</EVENTS></SESSION></SESSIONS>
<CLUBS><CLUB name="SV Test" code="SVT" nation="AUT"><ATHLETES>
  <ATHLETE athleteid="1" firstname="Anna" lastname="Huber" birthdate="2000-01-01" gender="F">
    <RESULTS><RESULT eventid="1058" resultid="501" swimtime="00:00:40.00" /></RESULTS></ATHLETE>
</ATHLETES></CLUB></CLUBS></MEET></MEETS></LENEX>';
}

function sessionKey_lief(TestResponse $response): string
{
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

    return $query['session'];
}

it('zeigt einen Fehler im Import-Lauf auf der Wettkampfauswahl an und behält die Import-Session', function () {
    Storage::fake('local');
    $admin = admin_lief();
    $upload = $this->actingAs($admin)->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('ergebnisse.lef', resultXml_lief()),
    ]);
    $sessionKey = sessionKey_lief($upload);

    // Ein nicht (mehr) vorhandener Wettkampf lässt den Lauf scheitern.
    $run = $this->actingAs($admin)->post(route('lenex.import.run'), ['import_session' => $sessionKey, 'meet_id' => '999999']);

    $run->assertRedirect(route('lenex.import.confirm-meet', ['session' => $sessionKey]))
        ->assertSessionHas('error', fn (string $message) => str_starts_with($message, 'Import fehlgeschlagen:'));

    expect(session($sessionKey))->not->toBeNull();

    $this->actingAs($admin)->get($run->headers->get('Location'))
        ->assertOk()
        ->assertSee('Import fehlgeschlagen:')
        ->assertSee('Import starten');
});

it('meldet eine abgelaufene Import-Session sichtbar auf der Upload-Seite', function () {
    $admin = admin_lief();

    $response = $this->actingAs($admin)->post(route('lenex.import.run'), ['import_session' => 'lenex_import_weg', 'meet_id' => '']);

    $response->assertRedirect(route('lenex.import'))
        ->assertSessionHas('error', 'Import-Session abgelaufen. Bitte Datei erneut hochladen.');

    $this->actingAs($admin)->get(route('lenex.import'))
        ->assertOk()
        ->assertSee('Import-Session abgelaufen. Bitte Datei erneut hochladen.');
});
