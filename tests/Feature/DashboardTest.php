<?php

use App\Models\User;

// In dieser Anwendung gibt es kein eigenes Dashboard: /dashboard ist bewusst
// eine Weiterleitung auf die Wettkampfübersicht (routes/web.php).
// Die Tests sichern dieses Verhalten ab, statt eine Dashboard-Seite zu erwarten.

test('dashboard leitet Gäste auf die Wettkampfübersicht weiter', function () {
    $this->get(route('dashboard'))->assertRedirect('/meets');
});

test('dashboard leitet angemeldete User auf die Wettkampfübersicht weiter', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertRedirect('/meets');
});

test('Gäste landen von der Wettkampfübersicht auf der Login-Seite', function () {
    $this->get('/meets')->assertRedirect(route('login'));
});
