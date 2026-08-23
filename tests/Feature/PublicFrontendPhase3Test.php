<?php

use App\Models\Document;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class)->group('public-p3');

// ── Setup-Helpers ────────────────────────────────────────────────────────────

function makeAdmin_p3(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function makeClubUser_p3(): User
{
    return User::factory()->create(['is_admin' => false, 'club_id' => null]);
}

function makeNation_p3(): Nation
{
    return Nation::firstOrCreate(['code' => 'AUT'], ['name_de' => 'Österreich', 'name_en' => 'Austria']);
}

function makeMeet_p3(array $overrides = []): Meet
{
    // course explizit statt dem Schema-Default überlassen: Meet::create() spiegelt
    // DB-seitige Defaults nicht ins In-Memory-Objekt zurück — ein Formular-Roundtrip mit
    // $meet->course würde sonst mit "Feld erforderlich" scheitern.
    return Meet::create(array_merge([
        'name' => 'Testwettkampf',
        'city' => 'Wien',
        'nation_id' => makeNation_p3()->id,
        'course' => 'LCM',
        'start_date' => now()->addDays(30)->toDateString(),
    ], $overrides));
}

function makeDocument_p3(?Meet $meet, array $overrides = []): Document
{
    return Document::create(array_merge([
        'documentable_type' => $meet ? Meet::class : null,
        'documentable_id' => $meet?->id,
        'category' => 'INVITATION',
        'title' => 'Ausschreibung',
        'path' => 'documents/'.uniqid().'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1000,
        'is_public' => false,
        'sort_order' => 1,
    ], $overrides));
}

// ── Zugriff ──────────────────────────────────────────────────────────────────

it('lässt Club-User nicht auf die Dokumentenverwaltung zu', function () {
    $meet = makeMeet_p3();

    $this->actingAs(makeClubUser_p3())
        ->get(route('admin.documents.index'))
        ->assertForbidden();

    $this->actingAs(makeClubUser_p3())
        ->get(route('admin.meets.documents.index', $meet))
        ->assertForbidden();
});

it('lässt Gäste nicht auf die Dokumentenverwaltung zu', function () {
    $this->get(route('admin.documents.index'))->assertRedirect(route('login'));
});

it('lässt Admins auf die Dokumentenverwaltung zu', function () {
    $this->actingAs(makeAdmin_p3())
        ->get(route('admin.documents.index'))
        ->assertOk();
});

// ── Listen-Trennung ──────────────────────────────────────────────────────────

it('zeigt in der Meet-Liste nur Dokumente dieses Meets, in der allgemeinen Liste nur Dokumente ohne Zuordnung', function () {
    $meet = makeMeet_p3();
    makeDocument_p3($meet, ['title' => 'Meet-Dokument']);
    makeDocument_p3(null, ['title' => 'Regelment ohne Zuordnung', 'category' => 'REGULATION']);

    $this->actingAs(makeAdmin_p3())
        ->get(route('admin.meets.documents.index', $meet))
        ->assertOk()
        ->assertSee('Meet-Dokument')
        ->assertDontSee('Regelment ohne Zuordnung');

    $this->actingAs(makeAdmin_p3())
        ->get(route('admin.documents.index'))
        ->assertOk()
        ->assertSee('Regelment ohne Zuordnung')
        ->assertDontSee('Meet-Dokument');
});

// ── Upload ───────────────────────────────────────────────────────────────────

it('legt ein Dokument an einer Veranstaltung an und berechnet mime_type/size_bytes aus der Datei', function () {
    Storage::fake('local');
    $meet = makeMeet_p3();
    $file = UploadedFile::fake()->create('ausschreibung.pdf', 240, 'application/pdf');

    $this->actingAs(makeAdmin_p3())
        ->post(route('admin.meets.documents.store', $meet), [
            'title' => 'Ausschreibung',
            'category' => 'INVITATION',
            'locale' => 'de',
            'file' => $file,
            'is_public' => '1',
        ])
        ->assertRedirect(route('admin.meets.documents.index', $meet));

    $document = Document::sole();

    expect($document->documentable_type)->toBe(Meet::class)
        ->and($document->documentable_id)->toBe($meet->id)
        ->and($document->mime_type)->toBe('application/pdf')
        ->and($document->size_bytes)->toBe(240 * 1024)
        ->and($document->is_public)->toBeTrue();

    Storage::disk('local')->assertExists($document->path);
});

it('legt ein Dokument ohne Veranstaltungsbezug an (Regelmente & Formulare)', function () {
    Storage::fake('local');
    $file = UploadedFile::fake()->create('formular.pdf', 50, 'application/pdf');

    $this->actingAs(makeAdmin_p3())
        ->post(route('admin.documents.store'), [
            'title' => 'Nachmeldeformular',
            'category' => 'FORM',
            'file' => $file,
        ])
        ->assertRedirect(route('admin.documents.index'));

    $document = Document::sole();

    expect($document->documentable_type)->toBeNull()
        ->and($document->documentable_id)->toBeNull();
});

// ── Sprachvarianten (§4.1: category + sort_order als Paarungsschlüssel) ───────

it('übernimmt beim Anlegen die sort_order der gewählten Sprachvariante', function () {
    Storage::fake('local');
    $meet = makeMeet_p3();
    $original = makeDocument_p3($meet, ['locale' => 'de', 'sort_order' => 3]);
    $file = UploadedFile::fake()->create('invitation.pdf', 100, 'application/pdf');

    $this->actingAs(makeAdmin_p3())
        ->post(route('admin.meets.documents.store', $meet), [
            'title' => 'Invitation',
            'category' => 'INVITATION',
            'locale' => 'en',
            'file' => $file,
            'pair_with_document_id' => $original->id,
        ])
        ->assertRedirect();

    $created = Document::where('locale', 'en')->sole();

    expect($created->sort_order)->toBe(3);
});

it('reiht ein Dokument ohne gewählte Sprachvariante hinter der höchsten vorhandenen sort_order der Kategorie ein', function () {
    Storage::fake('local');
    $meet = makeMeet_p3();
    makeDocument_p3($meet, ['category' => 'RESULTS', 'sort_order' => 5]);
    $file = UploadedFile::fake()->create('ergebnisse.pdf', 100, 'application/pdf');

    $this->actingAs(makeAdmin_p3())
        ->post(route('admin.meets.documents.store', $meet), [
            'title' => 'Ergebnisse Tag 2',
            'category' => 'RESULTS',
            'file' => $file,
        ])
        ->assertRedirect();

    $created = Document::where('title', 'Ergebnisse Tag 2')->sole();

    expect($created->sort_order)->toBe(6);
});

it('ignoriert eine Sprachvariante aus einer fremden Veranstaltung', function () {
    Storage::fake('local');
    $meetA = makeMeet_p3(['name' => 'Meet A']);
    $meetB = makeMeet_p3(['name' => 'Meet B']);
    $foreign = makeDocument_p3($meetB, ['sort_order' => 9]);
    $file = UploadedFile::fake()->create('ausschreibung.pdf', 100, 'application/pdf');

    $this->actingAs(makeAdmin_p3())
        ->post(route('admin.meets.documents.store', $meetA), [
            'title' => 'Ausschreibung A',
            'category' => 'INVITATION',
            'file' => $file,
            'pair_with_document_id' => $foreign->id,
        ])
        ->assertRedirect();

    $created = Document::where('title', 'Ausschreibung A')->sole();

    // Fällt auf die normale Einreihung zurück, statt die sort_order des fremden Meets zu
    // übernehmen: erstes INVITATION-Dokument für meetA, also 1 — nicht 9.
    expect($created->sort_order)->toBe(1);
});

// ── Bearbeiten und Löschen ─────────────────────────────────────────────────────

it('ersetzt beim Bearbeiten die Datei und löscht die alte von der Disk', function () {
    Storage::fake('local');
    $meet = makeMeet_p3();
    $document = makeDocument_p3($meet);
    Storage::disk('local')->put($document->path, 'Alter Inhalt');
    $oldPath = $document->path;
    $newFile = UploadedFile::fake()->create('neu.pdf', 300, 'application/pdf');

    $this->actingAs(makeAdmin_p3())
        ->put(route('admin.documents.update', $document), [
            'title' => $document->title,
            'category' => $document->category,
            'file' => $newFile,
        ])
        ->assertRedirect(route('admin.meets.documents.index', $meet));

    $document->refresh();

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($document->path);
    expect($document->size_bytes)->toBe(300 * 1024);
});

it('behält beim Bearbeiten ohne neue Datei die bestehende Datei', function () {
    Storage::fake('local');
    $meet = makeMeet_p3();
    $document = makeDocument_p3($meet);
    Storage::disk('local')->put($document->path, 'Inhalt');

    $this->actingAs(makeAdmin_p3())
        ->put(route('admin.documents.update', $document), [
            'title' => 'Neuer Titel',
            'category' => $document->category,
        ])
        ->assertRedirect();

    $document->refresh();

    expect($document->title)->toBe('Neuer Titel');
    Storage::disk('local')->assertExists($document->path);
});

it('löscht beim Entfernen eines Dokuments die Datenbankzeile und die Datei', function () {
    Storage::fake('local');
    $meet = makeMeet_p3();
    $document = makeDocument_p3($meet);
    Storage::disk('local')->put($document->path, 'Inhalt');

    $this->actingAs(makeAdmin_p3())
        ->delete(route('admin.documents.destroy', $document))
        ->assertRedirect(route('admin.meets.documents.index', $meet));

    expect(Document::find($document->id))->toBeNull();
    Storage::disk('local')->assertMissing($document->path);
});

it('ignoriert is_published und livetiming_url, wenn ein Nicht-Admin sie über einen rohen Request mitschickt', function () {
    $clubUser = makeClubUser_p3();
    $meet = makeMeet_p3(['is_published' => false]);

    $this->actingAs($clubUser)
        ->put(route('meets.update', $meet), [
            'name' => $meet->name,
            'city' => $meet->city,
            'nation_id' => $meet->nation_id,
            'course' => $meet->course,
            'start_date' => $meet->start_date->toDateString(),
            'is_published' => '1',
            'livetiming_url' => 'https://sneaked-in.example.test',
        ])
        ->assertRedirect(route('meets.show', $meet));

    $meet->refresh();

    expect($meet->is_published)->toBeFalse()
        ->and($meet->livetiming_url)->toBeNull();
});

// ── is_published / livetiming_url im Meet-Formular (Reaktion auf den Bug-Report) ──

it('macht ein Meet über den is_published-Schalter im Formular auf der öffentlichen Liste sichtbar', function () {
    $meet = makeMeet_p3(['is_published' => false]);

    $this->get(route('public.meets.index', ['locale' => 'de']))
        ->assertDontSee($meet->name);

    $this->actingAs(makeAdmin_p3())
        ->put(route('meets.update', $meet), [
            'name' => $meet->name,
            'city' => $meet->city,
            'nation_id' => $meet->nation_id,
            'course' => $meet->course,
            'start_date' => $meet->start_date->toDateString(),
            'is_published' => '1',
            'livetiming_url' => 'https://livetiming.example.test/meet',
        ])
        ->assertRedirect(route('meets.show', $meet));

    $meet->refresh();

    expect($meet->is_published)->toBeTrue()
        ->and($meet->livetiming_url)->toBe('https://livetiming.example.test/meet');

    $this->get(route('public.meets.index', ['locale' => 'de']))
        ->assertSee($meet->name);
});

it('speichert den Meldeschluss über das Meet-Formular', function () {
    // Regression: entries_deadline fehlte in validateMeet()'s Regelliste — das Formularfeld
    // gab es zwar, aber $request->validate() ließ es stillschweigend fallen, sodass es nie
    // gespeichert wurde und auf der öffentlichen Seite dauerhaft als "—" erschien.
    $meet = makeMeet_p3(['entries_deadline' => null]);
    // start_date ist ein mutabler Carbon-Cast — subDays() auf $meet->start_date würde das
    // Attribut selbst verändern; deshalb hier auf einen eigenen Wert kopiert.
    $deadline = $meet->start_date->clone()->subDays(14)->toDateString();

    $this->actingAs(makeAdmin_p3())
        ->put(route('meets.update', $meet), [
            'name' => $meet->name,
            'city' => $meet->city,
            'nation_id' => $meet->nation_id,
            'course' => $meet->course,
            'start_date' => $meet->start_date->toDateString(),
            'entries_deadline' => $deadline,
        ])
        ->assertRedirect(route('meets.show', $meet));

    $meet->refresh();

    expect($meet->entries_deadline?->toDateString())->toBe($deadline);
});
