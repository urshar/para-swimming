<?php

use App\Models\BaseTimeVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class)->group('base-time-valid-on');

/**
 * Regressionstest für scopeValidOn().
 *
 * Ursprünglich lautete die Bedingung `valid_from ≤ $date`. Da eine date-Spalte je nach
 * Treiber als "2026-01-01 00:00:00" abgelegt wird, war dieser Zeichenkettenvergleich am
 * ersten Gültigkeitstag falsch — ein Wettkampf an diesem Tag fand keine Basiswert-Version
 * und bekam keine World-Aquatics-Punkte, ohne dass ein Fehler auftrat.
 */
function makeBaseTimeVersion_btvo(string $validFrom, ?string $validUntil = null): BaseTimeVersion
{
    return BaseTimeVersion::create([
        'label' => "Version ab $validFrom",
        'valid_from' => $validFrom,
        'valid_until' => $validUntil,
    ]);
}

it('findet die Version am ersten Gültigkeitstag', function () {
    $version = makeBaseTimeVersion_btvo('2026-01-01', '2026-12-31');

    expect(BaseTimeVersion::validOn('2026-01-01')->pluck('id')->all())->toBe([$version->id]);
});

it('findet die Version am letzten Gültigkeitstag', function () {
    $version = makeBaseTimeVersion_btvo('2026-01-01', '2026-12-31');

    expect(BaseTimeVersion::validOn('2026-12-31')->pluck('id')->all())->toBe([$version->id]);
});

it('findet die Version mitten im Gültigkeitszeitraum', function () {
    $version = makeBaseTimeVersion_btvo('2026-01-01', '2026-12-31');

    expect(BaseTimeVersion::validOn('2026-06-15')->pluck('id')->all())->toBe([$version->id]);
});

it('findet eine unbefristete Version', function () {
    $version = makeBaseTimeVersion_btvo('2026-01-01');

    expect(BaseTimeVersion::validOn('2030-06-15')->pluck('id')->all())->toBe([$version->id]);
});

it('findet keine Version einen Tag vor Beginn', function () {
    makeBaseTimeVersion_btvo('2026-01-01', '2026-12-31');

    expect(BaseTimeVersion::validOn('2025-12-31')->get())->toBeEmpty();
});

it('findet keine Version einen Tag nach Ende', function () {
    makeBaseTimeVersion_btvo('2026-01-01', '2026-12-31');

    expect(BaseTimeVersion::validOn('2027-01-01')->get())->toBeEmpty();
});

it('trennt aufeinanderfolgende Versionen sauber am Übergangstag', function () {
    $alt = makeBaseTimeVersion_btvo('2025-01-01', '2025-12-31');
    $neu = makeBaseTimeVersion_btvo('2026-01-01', '2026-12-31');

    expect(BaseTimeVersion::validOn('2025-12-31')->pluck('id')->all())->toBe([$alt->id])
        ->and(BaseTimeVersion::validOn('2026-01-01')->pluck('id')->all())->toBe([$neu->id]);
});
