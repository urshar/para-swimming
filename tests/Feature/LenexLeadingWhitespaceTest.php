<?php

use App\Services\LenexParserService;

uses()->group('lenex-leading-whitespace');

// ── Setup-Helpers ─────────────────────────────────────────────────────────────

/** Minimale LENEX-Datei mit beliebigem Präfix vor der XML-Deklaration. */
function lenexWithPrefix_llw(string $prefix): string
{
    return $prefix.'<?xml version="1.0" encoding="UTF-8"?>
<LENEX version="2.0"><MEETS><MEET name="Landesmeisterschaften Wien" city="Wien" nation="AUT" course="SCM">
<SESSIONS><SESSION number="1" date="2022-11-13" /></SESSIONS>
</MEET></MEETS></LENEX>';
}

/** @throws Exception */
function loadWithPrefix_llw(string $prefix): SimpleXMLElement
{
    $path = tempnam(sys_get_temp_dir(), 'llw').'.lef';
    file_put_contents($path, lenexWithPrefix_llw($prefix));

    try {
        return app(LenexParserService::class)->loadXml($path);
    } finally {
        unlink($path);
    }
}

// ── Führender Leerraum ────────────────────────────────────────────────────────

it('liest eine Datei mit Tab vor der XML-Deklaration', /** @throws Exception */ function () {
    $xml = loadWithPrefix_llw("\t");

    expect($xml->getName())->toBe('LENEX')
        ->and((string) $xml->MEETS->MEET[0]['name'])->toBe('Landesmeisterschaften Wien');
});

it('liest eine Datei mit UTF-8-BOM und Zeilenumbruch vor der XML-Deklaration', /** @throws Exception */ function () {
    $xml = loadWithPrefix_llw("\xEF\xBB\xBF\r\n  ");

    expect($xml->getName())->toBe('LENEX')
        ->and((string) $xml->MEETS->MEET[0]['city'])->toBe('Wien');
});

it('liest eine Datei mit UTF-8-BOM direkt vor der XML-Deklaration weiterhin', /** @throws Exception */ function () {
    expect(loadWithPrefix_llw("\xEF\xBB\xBF")->getName())->toBe('LENEX');
});
