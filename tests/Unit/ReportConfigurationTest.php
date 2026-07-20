<?php

use App\Support\ReportConfiguration;
use Carbon\CarbonImmutable;

// ── fromArray: vollständige Konfiguration (Spec §4 Beispiel) ──────────────────

it('baut die Konfiguration aus dem vollständigen Spec-Beispiel', function () {
    $config = ReportConfiguration::fromArray([
        'year' => 2024,
        'date_from' => '2024-01-01',
        'date_to' => '2024-12-31',
        'meet_ids' => [1, 2, 3],
        'sections' => [
            'overview' => true,
            'participants' => true,
            'clubs' => true,
            'athletes' => true,
            'nations' => true,
            'sport_classes' => true,
            'records' => true,
            'cup' => true,
        ],
    ]);

    expect($config->year)->toBe(2024)
        ->and($config->dateFrom->toDateString())->toBe('2024-01-01')
        ->and($config->dateTo->toDateString())->toBe('2024-12-31')
        ->and($config->meetIds)->toBe([1, 2, 3])
        ->and($config->enabledSections())->toBe(ReportConfiguration::SECTION_KEYS);
});

// ── Zeitraum/Jahr leiten sich gegenseitig ab ─────────────────────────────────

it('leitet den Zeitraum aus dem Jahr ab, wenn keine Daten übergeben werden', function () {
    $config = ReportConfiguration::fromArray(['year' => 2023]);

    expect($config->dateFrom->toDateString())->toBe('2023-01-01')
        ->and($config->dateTo->toDateString())->toBe('2023-12-31');
});

it('leitet das Jahr aus date_from ab, wenn kein Jahr übergeben wird', function () {
    $config = ReportConfiguration::fromArray([
        'date_from' => '2022-03-01',
        'date_to' => '2022-09-30',
    ]);

    expect($config->year)->toBe(2022);
});

it('deckt mit startOfDay/endOfDay den ganzen Zeitraum inklusive Randtagen ab', function () {
    $config = ReportConfiguration::forYear(2024);

    expect($config->dateFrom->format('Y-m-d H:i:s'))->toBe('2024-01-01 00:00:00')
        ->and($config->dateTo->format('Y-m-d H:i:s'))->toBe('2024-12-31 23:59:59');
});

// ── Meet-IDs: Normalisierung ─────────────────────────────────────────────────

it('normalisiert Meet-IDs (int-Cast, Duplikate/0 entfernen, neu indizieren)', function () {
    $config = ReportConfiguration::fromArray([
        'year' => 2024,
        'meet_ids' => ['3', 3, '0', 0, '5', 7],
    ]);

    expect($config->meetIds)->toBe([3, 5, 7])
        ->and($config->isMeetFiltered())->toBeTrue();
});

it('gilt ohne Meet-IDs als nicht eingeschränkt', function () {
    $config = ReportConfiguration::forYear(2024);

    expect($config->meetIds)->toBe([])
        ->and($config->isMeetFiltered())->toBeFalse();
});

// ── Sections: Defaults, Overrides, Validierung ───────────────────────────────

it('aktiviert ohne Angabe alle bekannten Abschnitte per Default', function () {
    $config = ReportConfiguration::forYear(2024);

    expect($config->sections)->toHaveCount(count(ReportConfiguration::SECTION_KEYS))
        ->and($config->enabledSections())->toBe(ReportConfiguration::SECTION_KEYS);
});

it('überschreibt einzelne Abschnitte und behält den Rest auf Default', function () {
    $config = ReportConfiguration::fromArray([
        'year' => 2024,
        'sections' => ['cup' => false, 'records' => false],
    ]);

    expect($config->hasSection('cup'))->toBeFalse()
        ->and($config->hasSection('records'))->toBeFalse()
        ->and($config->hasSection('overview'))->toBeTrue()
        ->and($config->enabledSections())->not->toContain('cup')
        ->and($config->enabledSections())->not->toContain('records')
        ->and($config->enabledSections())->toContain('overview');
});

it('behandelt unbekannte Abschnitte in hasSection() als inaktiv', function () {
    $config = ReportConfiguration::forYear(2024);

    expect($config->hasSection('does_not_exist'))->toBeFalse();
});

it('wirft bei unbekanntem Abschnittsschlüssel', function () {
    ReportConfiguration::fromArray([
        'year' => 2024,
        'sections' => ['tippfehler' => true],
    ]);
})->throws(InvalidArgumentException::class);

// ── Validierung des Konstruktors ─────────────────────────────────────────────

it('wirft, wenn date_from nach date_to liegt', function () {
    new ReportConfiguration(
        year: 2024,
        dateFrom: CarbonImmutable::parse('2024-12-31'),
        dateTo: CarbonImmutable::parse('2024-01-01'),
        meetIds: [],
        sections: [],
    );
})->throws(InvalidArgumentException::class);

it('wirft, wenn weder Jahr noch Zeitraum angegeben ist', function () {
    ReportConfiguration::fromArray([]);
})->throws(InvalidArgumentException::class);

// ── Serialisierung / Round-Trip ──────────────────────────────────────────────

it('serialisiert per toArray() verlustfrei zurück', function () {
    $original = ReportConfiguration::fromArray([
        'year' => 2024,
        'date_from' => '2024-01-01',
        'date_to' => '2024-12-31',
        'meet_ids' => [2, 4],
        'sections' => ['cup' => false],
    ]);

    $array = $original->toArray();
    $rebuilt = ReportConfiguration::fromArray($array);

    expect($array['year'])->toBe(2024)
        ->and($array['date_from'])->toBe('2024-01-01')
        ->and($array['date_to'])->toBe('2024-12-31')
        ->and($array['meet_ids'])->toBe([2, 4])
        ->and($rebuilt->toArray())->toBe($array);
});
