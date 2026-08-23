<?php

use App\Models\Document;
use App\Models\Meet;
use App\Models\Nation;
use App\Services\Public\PublicMeetService;
use App\Support\DocumentLocaleGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('public-p2');

function makeNation_publicp2(): Nation
{
    // firstOrCreate statt create: makeMeet_publicp2() ruft dies für jedes Meet ohne eigene
    // nation_id auf — ein zweiter create() mit demselben code verletzte die Unique-Constraint.
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function makeMeet_publicp2(array $overrides = []): Meet
{
    return Meet::create(array_merge([
        'name' => 'Testwettkampf',
        'city' => 'Wien',
        'nation_id' => makeNation_publicp2()->id,
        'start_date' => now()->subDays(10)->toDateString(),
        'is_published' => true,
    ], $overrides));
}

function makeDocument_publicp2(Meet $meet, array $overrides = []): Document
{
    return Document::create(array_merge([
        'documentable_type' => Meet::class,
        'documentable_id' => $meet->id,
        'category' => 'INVITATION',
        'title' => 'Ausschreibung',
        'path' => 'documents/'.uniqid().'.pdf',
        'is_public' => true,
        'published_at' => now()->subDay(),
    ], $overrides));
}

// ── Sichtbarkeit unveröffentlichter Meets ────────────────────────────────────

it('listet unveröffentlichte Meets weder in der Übersicht noch im Archiv', function () {
    $published = makeMeet_publicp2(['name' => 'Sichtbarer Wettkampf']);
    makeMeet_publicp2(['name' => 'Unsichtbarer Wettkampf', 'is_published' => false]);

    $this->get(route('public.meets.index', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Sichtbarer Wettkampf')
        ->assertDontSee('Unsichtbarer Wettkampf');

    $this->get(route('public.meets.archive', ['locale' => 'de']))
        ->assertOk()
        ->assertSee('Sichtbarer Wettkampf')
        ->assertDontSee('Unsichtbarer Wettkampf');

    expect($published->is_published)->toBeTrue();
});

it('liefert 404 für die Detailseite eines unveröffentlichten Meets', function () {
    $meet = makeMeet_publicp2(['is_published' => false]);

    $this->get(route('public.meets.show', ['locale' => 'de', 'meet' => $meet]))
        ->assertNotFound();
});

// ── Dokument-Download ─────────────────────────────────────────────────────────

it('liefert ein Dokument ohne published_at nicht aus', function () {
    Storage::fake('local');

    $meet = makeMeet_publicp2();
    $document = makeDocument_publicp2($meet, ['published_at' => null]);
    Storage::disk('local')->put($document->path, 'Inhalt');

    $this->get(route('public.documents.download', ['locale' => 'de', 'document' => $document]))
        ->assertNotFound();
});

it('liefert ein veröffentlichtes, öffentliches Dokument einer veröffentlichten Veranstaltung aus', function () {
    Storage::fake('local');

    $meet = makeMeet_publicp2();
    $document = makeDocument_publicp2($meet);
    Storage::disk('local')->put($document->path, 'Inhalt');

    $this->get(route('public.documents.download', ['locale' => 'de', 'document' => $document]))
        ->assertOk();
});

it('sperrt ein Dokument einer unveröffentlichten Veranstaltung, auch wenn das Dokument selbst öffentlich ist', function () {
    Storage::fake('local');

    $meet = makeMeet_publicp2(['is_published' => false]);
    $document = makeDocument_publicp2($meet);
    Storage::disk('local')->put($document->path, 'Inhalt');

    $this->get(route('public.documents.download', ['locale' => 'de', 'document' => $document]))
        ->assertNotFound();
});

it('liefert Dokumente nicht über einen direkten Pfadzugriff aus', function () {
    Storage::fake('local');

    $meet = makeMeet_publicp2();
    $document = makeDocument_publicp2($meet);
    Storage::disk('local')->put($document->path, 'Inhalt');

    // Laravel registriert für den `local`-Disk (serve => true, visibility privat) automatisch
    // eine Route unter /storage/{path} — ohne gültige Signatur liefert sie 403 (Testumgebung)
    // bzw. 404 (Produktion), nie den Inhalt. Beides ist hier "nicht erfolgreich".
    $response = $this->get('/storage/'.$document->path);

    expect($response->status())->toBeGreaterThanOrEqual(400);
});

// ── Sprachauflösung (§4.1) ────────────────────────────────────────────────────

it('zeigt die passende Sprachfassung, wenn nur diese existiert', function () {
    $meet = makeMeet_publicp2();
    makeDocument_publicp2($meet, ['locale' => 'de']);

    $groups = DocumentLocaleGroup::forMeet($meet, 'de');

    expect($groups)->toHaveCount(1)
        ->and($groups->first()->document->locale)->toBe('de')
        ->and($groups->first()->alternate)->toBeNull();
});

it('zeigt die andere Sprachfassung, wenn nur diese existiert', function () {
    $meet = makeMeet_publicp2();
    makeDocument_publicp2($meet, ['locale' => 'en']);

    $groups = DocumentLocaleGroup::forMeet($meet, 'de');

    expect($groups)->toHaveCount(1)
        ->and($groups->first()->document->locale)->toBe('en')
        ->and($groups->first()->alternate)->toBeNull();
});

it('zeigt die sprachneutrale Fassung, wenn nur diese existiert', function () {
    $meet = makeMeet_publicp2();
    makeDocument_publicp2($meet, ['locale' => null]);

    $groupsDe = DocumentLocaleGroup::forMeet($meet, 'de');
    $groupsEn = DocumentLocaleGroup::forMeet($meet, 'en');

    expect($groupsDe->first()->document->locale)->toBeNull()
        ->and($groupsEn->first()->document->locale)->toBeNull();
});

it('zeigt die aktive Sprache und verlinkt die andere, wenn beide existieren', function () {
    $meet = makeMeet_publicp2();
    makeDocument_publicp2($meet, ['locale' => 'de', 'sort_order' => 1]);
    makeDocument_publicp2($meet, ['locale' => 'en', 'sort_order' => 1]);

    $groups = DocumentLocaleGroup::forMeet($meet, 'de');

    expect($groups)->toHaveCount(1)
        ->and($groups->first()->document->locale)->toBe('de')
        ->and($groups->first()->alternate?->locale)->toBe('en');
});

// ── PublicMeetService ─────────────────────────────────────────────────────────

it('gibt kommende Veranstaltungen chronologisch aufsteigend und begrenzt zurück', function () {
    makeMeet_publicp2(['name' => 'In 20 Tagen', 'start_date' => now()->addDays(20)->toDateString()]);
    makeMeet_publicp2(['name' => 'In 5 Tagen', 'start_date' => now()->addDays(5)->toDateString()]);
    makeMeet_publicp2(['name' => 'In 10 Tagen', 'start_date' => now()->addDays(10)->toDateString()]);

    $upcoming = (new PublicMeetService)->upcoming(2);

    expect($upcoming->pluck('name')->all())->toBe(['In 5 Tagen', 'In 10 Tagen']);
});

it('gibt vergangene Veranstaltungen chronologisch absteigend zurück', function () {
    makeMeet_publicp2(['name' => 'Vor 20 Tagen', 'start_date' => now()->subDays(20)->toDateString()]);
    makeMeet_publicp2(['name' => 'Vor 5 Tagen', 'start_date' => now()->subDays(5)->toDateString()]);

    $recentPast = (new PublicMeetService)->recentPast();

    expect($recentPast->pluck('name')->all())->toBe(['Vor 5 Tagen', 'Vor 20 Tagen']);
});

it('gruppiert das Archiv nach Jahr, neuestes zuerst', function () {
    makeMeet_publicp2(['name' => 'Alt', 'start_date' => '2019-06-01']);
    makeMeet_publicp2(['name' => 'Neu', 'start_date' => '2024-06-01']);

    $grouped = (new PublicMeetService)->archiveGroupedByYear();

    expect($grouped->keys()->all())->toBe([2024, 2019])
        ->and($grouped->get(2024)->first()->name)->toBe('Neu');
});
