<?php

use App\Models\Document;
use App\Models\Meet;
use App\Models\Nation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('public-p8');

function makeRegulation_p8(array $overrides = []): Document
{
    return Document::create(array_merge([
        'category' => 'REGULATION',
        'title' => 'Wettkampfordnung',
        'path' => 'documents/'.uniqid().'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 240_000,
        'is_public' => true,
        'published_at' => now()->subDay(),
    ], $overrides));
}

// ── Sichtbarkeit ──────────────────────────────────────────────────────────────

it('zeigt nur öffentliche, veröffentlichte Dokumente ohne Veranstaltungsbezug', function () {
    makeRegulation_p8(['title' => 'Sichtbares Reglement']);
    makeRegulation_p8(['title' => 'Nicht öffentlich', 'is_public' => false]);
    makeRegulation_p8(['title' => 'Nicht veröffentlicht', 'published_at' => null]);
    makeRegulation_p8(['title' => 'Zukünftig', 'published_at' => now()->addDay()]);

    $this->get(route('public.regulations.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Sichtbares Reglement')
        ->assertDontSee('Nicht öffentlich')
        ->assertDontSee('Nicht veröffentlicht')
        ->assertDontSee('Zukünftig');
});

it('zeigt keine Dokumente einer Veranstaltung (documentable gesetzt)', function () {
    $nation = Nation::create(['code' => 'AUT', 'name_de' => 'Österreich', 'name_en' => 'Austria']);
    $meet = Meet::create([
        'name' => 'Testwettkampf',
        'city' => 'Wien',
        'nation_id' => $nation->id,
        'start_date' => now()->subDays(10)->toDateString(),
        'is_published' => true,
    ]);
    Document::create([
        'documentable_type' => Meet::class,
        'documentable_id' => $meet->id,
        'category' => 'REGULATION',
        'title' => 'Meet-gebundenes Reglement',
        'path' => 'documents/'.uniqid().'.pdf',
        'is_public' => true,
        'published_at' => now()->subDay(),
    ]);

    $this->get(route('public.regulations.index', ['locale' => 'de']))
        ->assertOk()
        ->assertDontSee('Meet-gebundenes Reglement');
});

it('zeigt nur die Kategorien Reglement und Formular, keine meet-typischen Kategorien', function () {
    makeRegulation_p8(['category' => 'INVITATION', 'title' => 'Fehlgeleitete Ausschreibung']);

    $this->get(route('public.regulations.index', ['locale' => 'de']))
        ->assertOk()
        ->assertDontSee('Fehlgeleitete Ausschreibung');
});

// ── Gruppierung ───────────────────────────────────────────────────────────────

it('gruppiert nach Kategorie, Reglemente vor Formularen', function () {
    makeRegulation_p8(['category' => 'FORM', 'title' => 'Meldeformular', 'sort_order' => 1]);
    makeRegulation_p8(['category' => 'REGULATION', 'title' => 'Wettkampfordnung', 'sort_order' => 1]);

    $content = $this->get(route('public.regulations.index', ['locale' => 'de']))->getContent();

    expect(strpos($content, 'Reglement'))->toBeLessThan(strpos($content, 'Meldeformular'))
        ->and(strpos($content, 'Meldeformular'))->toBeGreaterThan(strpos($content, 'Wettkampfordnung'));
});

// ── Sprachauflösung (App\Support\DocumentLocaleGroup, geteilt mit Phase 2) ─────

it('zeigt die aktive Sprache und verlinkt die andere, wenn beide existieren', function () {
    makeRegulation_p8(['title' => 'Wettkampfordnung DE', 'locale' => 'de', 'sort_order' => 1]);
    makeRegulation_p8(['title' => 'Wettkampfordnung EN', 'locale' => 'en', 'sort_order' => 1]);

    $this->get(route('public.regulations.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Wettkampfordnung DE')
        ->assertDontSee('Wettkampfordnung EN');
});

it('zeigt die Sprache je Dokument in einer eigenen Spalte', function () {
    // Unterschiedliche sort_order, sonst gälten beide als Sprachpaar desselben Dokuments
    // (category+sort_order ist der Paarungsschlüssel, siehe DocumentLocaleGroup) und nur eine
    // Fassung würde als eigene Zeile erscheinen.
    makeRegulation_p8(['title' => 'Deutsche Fassung', 'locale' => 'de', 'sort_order' => 1]);
    makeRegulation_p8(['title' => 'Sprachneutrale Fassung', 'locale' => null, 'sort_order' => 2]);

    $this->get(route('public.regulations.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSeeInOrder(['Deutsche Fassung', 'Deutsch'])
        ->assertSeeInOrder(['Sprachneutrale Fassung', 'sprachneutral']);
});

// ── Downloads (Public\DocumentDownloadController, bereits generisch aus Phase 2) ─

it('liefert ein Reglement ganz ohne Veranstaltungsbezug aus', function () {
    Storage::fake('local');
    $path = 'documents/regelment-test.pdf';
    Storage::disk('local')->put($path, 'PDF-Inhalt');
    $document = makeRegulation_p8(['path' => $path]);

    $this->get(route('public.documents.download', ['locale' => 'de', 'document' => $document]))
        ->assertOk();
});

// ── Navigation ───────────────────────────────────────────────────────────────

it('führt die Reglemente-Seite in der Hauptnavigation', function () {
    $this->get(route('public.home', ['locale' => 'de']))
        ->assertOk()
        ->assertSee(route('public.regulations.index', ['locale' => 'de']), false);
});
