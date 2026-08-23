<?php

namespace App\Support;

/**
 * Filterzustand der öffentlichen Startberechtigungs-Anzeige (Spec public-frontend §5, Phase 7).
 *
 * Bewusst ohne Namenssuche (anders als QualifyingTimeListController::filteredQualifications() im
 * internen Bereich, das ein "search"-Feld über Vor-/Nachname anbietet) — §2.3 Regel 3 der Spec
 * verbietet serverseitige Volltextsuche über Personen projektweit, nicht nur bei Cup-Wertung und
 * Jahresbestleistungen. Nur geschlossene Auswahlfelder (Bewerb, Geschlecht, Sportklasse,
 * Behinderungsgruppe, Verein). sportClassGroupId ergänzt sportClass um eine gröbere Auswahl nach
 * Behinderungsgruppe (PI/VI/MI/T21/HI) — Rückmeldung: "wenn alle Sportklassen gewählt ist, dass
 * man sich nur die Sportklassengruppen ebenfalls ansehen kann, so wie bei der Jahresbestleistung
 * die Klasse". Beide Felder wirken unabhängig voneinander (kein UI-Zwang, nur eines von beiden zu
 * setzen) — eine widersprüchliche Kombination liefert einfach keine Treffer.
 *
 * Unbekannte Werte fallen auf den Standard zurück (analog PublicRecordFilter) statt eine leere
 * Liste zu erzeugen.
 */
final readonly class PublicQualificationFilter
{
    private const array GENDERS = ['M', 'F'];

    public function __construct(
        public ?int $strokeTypeId = null,
        public ?int $distance = null,
        public string $gender = '',
        public string $sportClass = '',
        public ?int $clubId = null,
        public ?int $sportClassGroupId = null,
    ) {}

    /** @param  array<string, mixed>  $query */
    public static function fromQuery(array $query): self
    {
        $gender = (string) ($query['gender'] ?? '');
        if (! in_array($gender, self::GENDERS, true)) {
            $gender = '';
        }

        return new self(
            self::toInt($query['stroke_type_id'] ?? null),
            self::toInt($query['distance'] ?? null),
            $gender,
            strtoupper(trim((string) ($query['sport_class'] ?? ''))),
            self::toInt($query['club_id'] ?? null),
            self::toInt($query['sport_class_group_id'] ?? null),
        );
    }

    /** @return array<string, string> */
    public function toQuery(): array
    {
        return array_filter([
            'stroke_type_id' => $this->strokeTypeId !== null ? (string) $this->strokeTypeId : '',
            'distance' => $this->distance !== null ? (string) $this->distance : '',
            'gender' => $this->gender,
            'sport_class' => $this->sportClass,
            'club_id' => $this->clubId !== null ? (string) $this->clubId : '',
            'sport_class_group_id' => $this->sportClassGroupId !== null ? (string) $this->sportClassGroupId : '',
        ], static fn (string $value): bool => $value !== '');
    }

    /** Nur echte, positive Ganzzahlen — alles andere (leer, Text, 0, negativ) wird zu "kein Filter". */
    private static function toInt(mixed $value): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed !== false && $parsed > 0 ? $parsed : null;
    }
}
