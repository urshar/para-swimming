<?php

use App\Models\User;

// In dieser Anwendung gibt es kein eigenes Dashboard: /dashboard ist das Ziel nach dem Login und leitet auf den
// ersten freigegebenen Bereich weiter (DashboardController, Spec club-permissions Phase 3). Für Benutzer ohne
// freigegebenen Bereich siehe ClubPermissionsNavigationTest.

test('dashboard leitet Gäste auf die Wettkampfübersicht weiter', function () {
    $this->get(route('dashboard'))->assertRedirect(route('meets.index'));
});

test('dashboard leitet angemeldete User auf die Wettkampfübersicht weiter', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect(route('meets.index'));
});

test('Gäste landen von der Wettkampfübersicht auf der Login-Seite', function () {
    $this->get('/meets')->assertRedirect(route('login'));
});
