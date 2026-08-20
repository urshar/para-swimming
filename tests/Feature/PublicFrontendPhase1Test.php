<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('public-p1');

// ── Sprachweiterleitung ──────────────────────────────────────────────────────

it('leitet "/" ohne Cookie auf die bevorzugte Browsersprache weiter', function () {
    $this->get('/', ['Accept-Language' => 'en'])
        ->assertRedirect('/en')
        ->assertCookie('locale', 'en');
});

it('leitet "/" ohne Cookie und ohne unterstützte Browsersprache auf Deutsch weiter', function () {
    // Der Testclient (Symfony Request::create()) setzt ohne expliziten Header selbst einen
    // Accept-Language-Default ("en-us,en;q=0.5") — daher hier eine nicht unterstützte Sprache,
    // um den Fallback wirklich zu prüfen.
    $this->get('/', ['Accept-Language' => 'fr'])
        ->assertRedirect('/de')
        ->assertCookie('locale', 'de');
});

it('führt "/" nicht mehr in den angemeldeten Bereich', function () {
    $response = $this->get('/');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->not->toContain('/meets');
});

// ── Cookie-Vorrang ────────────────────────────────────────────────────────────

it('bevorzugt das Sprach-Cookie gegenüber der Browsersprache', function () {
    $this->withCookie('locale', 'en')
        ->get('/', ['Accept-Language' => 'de'])
        ->assertRedirect('/en');
});

// ── hreflang ──────────────────────────────────────────────────────────────────

it('trägt hreflang-Verweise auf die jeweils andere Sprachfassung', function () {
    $response = $this->get('/de');

    $response->assertOk()
        ->assertSee('hreflang="de"', false)
        ->assertSee('hreflang="en"', false)
        ->assertSee('href="'.url('/en').'"', false);
});
