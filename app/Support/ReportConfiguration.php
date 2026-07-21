<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * ReportConfiguration
 *
 * Unveränderliches Value Object für die Konfiguration einer Statistik- bzw.
 * Jahresbericht-Auswertung (Spec Phase 1). Bündelt Jahr, Zeitraum, die
 * ausgewählten Veranstaltungen und die aktiven Berichtsabschnitte.
 *
 * Bewusst als Value Object (nicht als Eloquent-Model/Tabelle) umgesetzt:
 *   - Statistikwerte werden live aus den Bestandsdaten berechnet, es gibt
 *     nichts zu persistieren (Spec §23 — keine redundante Statistikdatenbank).
 *   - Passt zur bestehenden readonly-Service-Architektur des Projekts.
 *
 * Die Konfiguration führt keine fachliche Berechnung durch — sie beschreibt
 * nur, WAS ausgewertet werden soll. Die eigentliche Auswertung übernehmen die
 * ab Phase 2 folgenden Statistik-Services.
 */
final readonly class ReportConfiguration
{
    /**
     * Kanonische Abschnittsschlüssel (Spec §4/§14). Einzige Quelle der
     * Wahrheit — spätere Phasen (z.B. ÖBM/ÖJM in Phase 13) erweitern diese
     * Liste hier zentral, statt Schlüssel verstreut zu hardcodieren.
     *
     * @var list<string>
     */
    public const array SECTION_KEYS = [
        'overview',
        'participants',
        'clubs',
        'athletes',
        'nations',
        'sport_classes',
        'records',
        'cup',
    ];

    /**
     * @param  list<int>  $meetIds  ausgewählte Veranstaltungen; leer = alle Meets im Zeitraum
     * @param  array<string, bool>  $sections  Abschnitt => aktiv? (nur Schlüssel aus SECTION_KEYS)
     * @param  int  $minParticipations  Schwellenwert X für "mind. X Veranstaltungs-Teilnahmen" (Spec Phase 5), Standard 2
     */
    public function __construct(
        public int $year,
        public CarbonImmutable $dateFrom,
        public CarbonImmutable $dateTo,
        public array $meetIds,
        public array $sections,
        public int $minParticipations = 2,
    ) {
        if ($this->dateFrom->greaterThan($this->dateTo)) {
            throw new InvalidArgumentException(
                'date_from darf nicht nach date_to liegen '
                ."($this->dateFrom → $this->dateTo)."
            );
        }

        if ($this->minParticipations < 1) {
            throw new InvalidArgumentException(
                "min_participations muss mindestens 1 sein ($this->minParticipations übergeben)."
            );
        }
    }

    /**
     * Baut die Konfiguration aus einem (Request-)Array gemäß Spec §4:
     *
     *   [
     *       'year' => 2024,
     *       'date_from' => '2024-01-01',
     *       'date_to' => '2024-12-31',
     *       'meet_ids' => [1, 2, 3],
     *       'sections' => ['overview' => true, ...],
     *   ]
     *
     * Zeitraum und Jahr ergänzen sich gegenseitig:
     *   - Fehlen date_from/date_to, wird der Zeitraum aus dem Jahr abgeleitet
     *     (1.1. bis 31.12.).
     *   - Fehlt year, wird es aus date_from übernommen.
     *   - Fehlt beides, wird eine Exception geworfen (keine stille Annahme).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException bei fehlendem Jahr/Zeitraum oder
     *                                  unbekanntem Abschnittsschlüssel.
     */
    public static function fromArray(array $data): self
    {
        $hasFrom = ! empty($data['date_from']);
        $hasTo = ! empty($data['date_to']);
        $hasYear = isset($data['year']) && $data['year'] !== '';

        if (! $hasYear && ! $hasFrom) {
            throw new InvalidArgumentException(
                'Konfiguration benötigt mindestens ein Jahr (year) oder einen Startzeitpunkt (date_from).'
            );
        }

        $dateFrom = $hasFrom
            ? CarbonImmutable::parse((string) $data['date_from'])->startOfDay()
            : CarbonImmutable::parse(sprintf('%04d-01-01', (int) $data['year']))->startOfDay();

        $year = $hasYear ? (int) $data['year'] : $dateFrom->year;

        $dateTo = $hasTo
            ? CarbonImmutable::parse((string) $data['date_to'])->endOfDay()
            : CarbonImmutable::parse(sprintf('%04d-12-31', $year))->endOfDay();

        $hasMin = isset($data['min_participations'])
            && $data['min_participations'] !== '';

        return new self(
            year: $year,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            meetIds: self::normalizeMeetIds($data['meet_ids'] ?? []),
            sections: self::normalizeSections($data['sections'] ?? []),
            minParticipations: $hasMin ? (int) $data['min_participations'] : 2,
        );
    }

    /**
     * Bequemer Konstruktor für ein ganzes Kalenderjahr (1.1.–31.12.).
     *
     * @param  list<int>  $meetIds
     * @param  array<string, bool>  $sections
     */
    public static function forYear(int $year, array $meetIds = [], array $sections = []): self
    {
        return self::fromArray([
            'year' => $year,
            'meet_ids' => $meetIds,
            'sections' => $sections,
        ]);
    }

    /** Ist ein bestimmter Abschnitt aktiv? Unbekannte Schlüssel gelten als inaktiv. */
    public function hasSection(string $key): bool
    {
        return $this->sections[$key] ?? false;
    }

    /**
     * Aktive Abschnitte in der kanonischen Reihenfolge (SECTION_KEYS).
     *
     * @return list<string>
     */
    public function enabledSections(): array
    {
        return array_values(array_filter(
            self::SECTION_KEYS,
            fn (string $key): bool => $this->hasSection($key),
        ));
    }

    /**
     * Ist die Auswertung auf bestimmte Veranstaltungen eingeschränkt?
     * false = alle Meets im Zeitraum werden berücksichtigt.
     */
    public function isMeetFiltered(): bool
    {
        return $this->meetIds !== [];
    }

    /**
     * Serialisierung für Views/PDF und Round-Trip-Tests.
     *
     * @return array{year: int, date_from: string, date_to: string, meet_ids: list<int>, sections: array<string, bool>, min_participations: int}
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'date_from' => $this->dateFrom->toDateString(),
            'date_to' => $this->dateTo->toDateString(),
            'meet_ids' => $this->meetIds,
            'sections' => $this->sections,
            'min_participations' => $this->minParticipations,
        ];
    }

    /**
     * Normalisiert die Meet-IDs: zu int casten, Duplikate/0-Werte entfernen,
     * Schlüssel neu indizieren (echte Liste).
     *
     * @param  mixed  $meetIds
     * @return list<int>
     */
    private static function normalizeMeetIds(mixed $meetIds): array
    {
        if (! is_array($meetIds)) {
            return [];
        }

        $ids = array_map(static fn ($id): int => (int) $id, $meetIds);
        $ids = array_filter($ids, static fn (int $id): bool => $id > 0);

        return array_values(array_unique($ids));
    }

    /**
     * Führt die übergebenen Abschnittsflags mit den Defaults zusammen. Alle
     * bekannten Abschnitte sind standardmäßig aktiv; explizit übergebene Werte
     * überschreiben den Default. Unbekannte Schlüssel führen zu einer
     * Exception (fängt Tippfehler früh ab).
     *
     * @param  mixed  $sections
     * @return array<string, bool>
     *
     * @throws InvalidArgumentException bei unbekanntem Abschnittsschlüssel.
     */
    private static function normalizeSections(mixed $sections): array
    {
        $sections = is_array($sections) ? $sections : [];

        $unknown = array_diff(array_keys($sections), self::SECTION_KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Unbekannte(r) Abschnittsschlüssel: '.implode(', ', $unknown).'. '
                .'Erlaubt: '.implode(', ', self::SECTION_KEYS).'.'
            );
        }

        $normalized = [];
        foreach (self::SECTION_KEYS as $key) {
            $normalized[$key] = ! array_key_exists($key, $sections) || $sections[$key];
        }

        return $normalized;
    }
}
