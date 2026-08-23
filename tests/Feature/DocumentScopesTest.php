<?php

use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('public-p1');

function makeDocument_publicp1(array $overrides = []): Document
{
    return Document::create(array_merge([
        'category' => 'REGULATION',
        'title' => 'Dokument',
        'path' => 'documents/'.uniqid().'.pdf',
    ], $overrides));
}

it('scopePublic filtert auf öffentlich freigegebene Dokumente', function () {
    $public = makeDocument_publicp1(['is_public' => true]);
    makeDocument_publicp1(['is_public' => false]);

    expect(Document::public()->pluck('id')->all())->toBe([$public->id]);
});

it('scopePublished filtert auf aktuell sichtbare Dokumente', function () {
    $published = makeDocument_publicp1(['published_at' => now()->subDay()]);
    makeDocument_publicp1(['published_at' => null]);
    makeDocument_publicp1(['published_at' => now()->addDay()]);

    expect(Document::published()->pluck('id')->all())->toBe([$published->id]);
});

it('scopeForLocale liefert die passende Sprachfassung und sprachneutrale Dokumente', function () {
    $de = makeDocument_publicp1(['locale' => 'de']);
    makeDocument_publicp1(['locale' => 'en']);
    $neutral = makeDocument_publicp1(['locale' => null]);

    $ids = Document::forLocale('de')->pluck('id')->sort()->values()->all();

    expect($ids)->toBe(collect([$de->id, $neutral->id])->sort()->values()->all());
});
