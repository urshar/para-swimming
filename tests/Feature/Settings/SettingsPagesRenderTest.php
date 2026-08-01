<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('settings-layout');

/**
 * Regressionstest für das Layout resources/views/layouts/app.blade.php.
 *
 * Das Layout wird auf zwei Wegen befüllt: klassische Controller-Views über
 *
 * @section('content'), Livewire-Full-Page-Komponenten über $slot. Gab das Layout $slot nicht
 * aus, lieferten die Einstellungsseiten HTTP 200 mit vollständigem Rahmen, aber leerem
 * Inhaltsbereich — ohne Fehlermeldung.
 */
it('rendert den Inhalt der Livewire-Einstellungsseiten, nicht nur den Rahmen', function (
    string $route,
    string $expected,
) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route($route))
        ->assertOk()
        ->assertSee($expected);
})->with([
    'Profil' => ['profile.edit', 'Profile'],
    'Darstellung' => ['appearance.edit', 'Appearance'],
    'Sicherheit' => ['security.edit', 'Update password'],
]);

it('rendert weiterhin die klassischen Controller-Views', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)
        ->get(route('meets.index'))
        ->assertOk()
        ->assertSee('Wettkämpfe');
});
