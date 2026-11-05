<?php

use App\Models\User;
use App\Services\LenexParserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('lenex-import-upload-feedback');

// Ungültiges XML nennt die Fundstelle in der Datei, und die Upload-Buttons zeigen ohne Datei keinen Spinner.

function admin_liuf(): User
{
    return User::forceCreate([
        'name' => 'Admin',
        'email' => 'admin-liuf@example.test',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);
}

/** LENEX mit unmaskiertem "&" in Zeile 3 (ohne Präfix). */
function brokenLenex_liuf(string $prefix): string
{
    return $prefix.'<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="3.0">
<MEETS><MEET name="A & B" /></MEETS>
</LENEX>';
}

function loadError_liuf(string $prefix): string
{
    $path = tempnam(sys_get_temp_dir(), 'liuf');
    file_put_contents($path, brokenLenex_liuf($prefix));

    try {
        app(LenexParserService::class)->loadXml($path);

        return '';
    } catch (Exception $e) {
        return $e->getMessage();
    } finally {
        unlink($path);
    }
}

it('nennt Zeile und Spalte bei ungültigem XML', function () {
    expect(loadError_liuf(''))->toBe('XML-Fehler: Zeile 3, Spalte 23: xmlParseEntityRef: no name');
});

it('zählt entfernte Leerzeilen vor der XML-Deklaration mit', function () {
    expect(loadError_liuf("\xEF\xBB\xBF\r\n\r\n"))->toStartWith('XML-Fehler: Zeile 5, Spalte 23:');
});

it('zeigt keine Fehler eines früheren Ladevorgangs an', function () {
    libxml_use_internal_errors(true);
    simplexml_load_string('<kaputt>');

    expect(loadError_liuf(''))->toBe('XML-Fehler: Zeile 3, Spalte 23: xmlParseEntityRef: no name');
});

it('meldet die Fundstelle beim Hochladen', function () {
    Storage::fake('local');

    $response = $this->actingAs(admin_liuf())->post(route('lenex.import.store'), [
        'lenex_file' => UploadedFile::fake()->createWithContent('kaputt.lef', brokenLenex_liuf('')),
    ]);

    $response->assertSessionHasErrors([
        'lenex_file' => 'Import fehlgeschlagen: XML-Fehler: Zeile 3, Spalte 23: xmlParseEntityRef: no name',
    ]);
});

it('rendert die Upload-Buttons ohne Flux-Spinner', function (string $routeName, string $label) {
    $this->actingAs(admin_liuf())->get(route($routeName))
        ->assertOk()
        ->assertSee($label)
        ->assertDontSee('data-flux-loading-indicator', false);
})->with([
    'Ergebnisse' => ['lenex.import', 'Datei importieren'],
    'Rekorde' => ['records.import', 'Vorschau anzeigen'],
]);
