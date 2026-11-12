<?php

use App\Models\Athlete;
use App\Models\ChangeRequest;
use App\Models\ImportReviewItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('navigation-group-badge');

// Nutzt die global geladenen _p5-Helper (tests/helpers_p5.php).

function admin_ngb(): User
{
    return User::factory()->create(['is_admin' => true, 'club_id' => null]);
}

function openChangeRequest_ngb(): ChangeRequest
{
    $athlete = Athlete::create([
        'first_name' => 'Anna', 'last_name' => 'Alt', 'gender' => 'F', 'birth_date' => '2009-03-04',
        'nation_id' => makeNation_p5()->id,
    ]);

    return ChangeRequest::create([
        'type' => ChangeRequest::TYPE_NAME, 'athlete_id' => $athlete->id, 'values' => ['last_name' => 'Neu'],
        'reason' => 'Heirat', 'status' => ChangeRequest::STATUS_OPEN,
    ]);
}

it('zeigt den Zähler an der Gruppen-Überschrift nur bei gesetztem Badge', function () {
    $withBadge = $this->blade('<flux:navlist.group heading="Rekorde" expandable :expanded="false" badge="3">x</flux:navlist.group>');
    $withoutBadge = $this->blade('<flux:navlist.group heading="Rekorde" expandable :expanded="false">x</flux:navlist.group>');

    $withBadge->assertSee('data-flux-navlist-group-badge', false)
        ->assertSeeInOrder(['Rekorde', '3', 'x']);
    $withoutBadge->assertDontSee('data-flux-navlist-group-badge', false);
});

it('zeigt offene Prüfliste und Anträge auch an den eingeklappten Menügruppen', function () {
    ImportReviewItem::create(['type' => ImportReviewItem::TYPE_RELAY_NO_CLUB, 'status' => ImportReviewItem::STATUS_OPEN,
        'source' => 'Test', 'details' => ['label' => 'Testeintrag']]);
    openChangeRequest_ngb();
    openChangeRequest_ngb();

    $html = $this->actingAs(admin_ngb())->get(route('admin.documents.index'))->assertOk()->getContent();

    expect(substr_count($html, ' data-flux-navlist-group-badge='))->toBe(2)
        ->and($html)->toMatch('/Rekorde<\/span>\s*.*?data-flux-navlist-group-badge[^>]*>\s*1\s*<\/span>/s')
        ->and($html)->toMatch('/Verwaltung<\/span>\s*.*?data-flux-navlist-group-badge[^>]*>\s*2\s*<\/span>/s');
});

it('zeigt ohne offene Einträge keinen Gruppen-Zähler', function () {
    $this->actingAs(admin_ngb())->get(route('admin.documents.index'))
        ->assertOk()
        ->assertDontSee('data-flux-navlist-group-badge', false);
});
