<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('form-tooltip-hints');

// Nutzt makeMeet_p5() aus tests/helpers_p5.php.

function admin_fth(): User
{
    return User::forceCreate([
        'name' => 'Admin FTH',
        'email' => 'admin_fth@example.test',
        'password' => bcrypt('secret'),
        'is_admin' => true,
    ]);
}

it('zeigt die Disziplin-Hinweise als fokussierbaren Info-Tooltip statt permanentem Text', function () {
    $meet = makeMeet_p5();

    $html = $this->actingAs(admin_fth())
        ->get(route('meets.events.create', $meet))
        ->assertOk()
        ->getContent();

    // Info-Icon-Tooltip mit Hinweistext im aria-label (barrierefrei), fokussierbar.
    expect($html)
        ->toContain('aria-label="Hinweis: 1 = Einzel"')
        ->toContain('aria-label="Hinweis: Leerzeichen-getrennt"')
        ->toContain('tabindex="0"')
        ->toContain('data-flux-tooltip');
});

it('rendert die x-hint-Komponente als Flux-Tooltip', function () {
    $html = view('components.hint', ['content' => 'MM:SS.hh — Testformat'])->render();

    expect($html)
        ->toContain('aria-label="Hinweis: MM:SS.hh — Testformat"')
        ->toContain('role="img"')
        ->toContain('tabindex="0"');
});
