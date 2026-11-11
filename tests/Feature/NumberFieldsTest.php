<?php

use App\Models\Meet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

uses(RefreshDatabase::class)->group('number-fields-p1');

// Zahlenfelder sind Textfelder ohne Pfeile (docs/open-points.md, "Zahlenfelder ohne Pfeile"). Der Browser
// prüft dadurch nichts mehr, die Prüfung liegt vollständig am Server.
// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_nf1(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

it('filtert die Veranstaltungsliste nach einem gültigen Jahr', function () {
    $meet2025 = makeMeet_p5();
    Meet::create([
        'name' => 'Altmeet',
        'nation_id' => $meet2025->nation_id,
        'course' => 'LCM',
        'start_date' => '2024-03-10',
    ]);

    $this->actingAs(admin_nf1())
        ->get(route('meets.index', ['year' => '2025']))
        ->assertOk()
        ->assertSee('Testmeet')
        ->assertDontSee('Altmeet');
});

it('ignoriert ein ungültiges Jahr im Veranstaltungsfilter', function (string $eingabe) {
    $meet2025 = makeMeet_p5();
    Meet::create([
        'name' => 'Altmeet',
        'nation_id' => $meet2025->nation_id,
        'course' => 'LCM',
        'start_date' => '2024-03-10',
    ]);

    $this->actingAs(admin_nf1())
        ->get(route('meets.index', ['year' => $eingabe]))
        ->assertOk()
        ->assertSee('Testmeet')
        ->assertSee('Altmeet');
})->with(['Buchstaben' => 'abc', 'zu kurz' => '25', 'Dezimal' => '2025.5']);

it('rendert nirgends Zahlenfelder mit Pfeilen', function () {
    $views = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $datei): bool => str_contains($datei->getContents(), 'type="number"'))
        ->map(fn (SplFileInfo $datei): string => str_replace('\\', '/', $datei->getRelativePathname()))
        ->values()
        ->all();

    expect($views)->toBe([]);
});
