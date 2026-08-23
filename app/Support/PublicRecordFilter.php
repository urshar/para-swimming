<?php

namespace App\Support;

use App\Models\Club;

/**
 * Filterzustand der öffentlichen Rekordliste (Spec public-frontend §5.2, Phase 5).
 *
 * Geteilt von Ansicht, LENEX- und PDF-Export — Muster von QualificationOverviewFilter:
 * zweimal ausprogrammiert liefen Bildschirm und Export sonst auseinander.
 *
 * Rekordebene und Altersklasse sind laut Spec zwei getrennte Filterachsen, die sich erst
 * gemeinsam zum tatsächlichen record_type zusammensetzen: "AUT", "AUT.JR", "AUT.<LV>",
 * "AUT.<LV>.JR". Internationale Rekorde (WR/ER/OR) sind außerhalb des öffentlichen Umfangs —
 * §5.2 nennt für die öffentliche Liste nur die AUT-Werte.
 */
final readonly class PublicRecordFilter
{
    /** Bahnlängen, die öffentlich zur Auswahl stehen — SCY ist laut Domain-Glossar für AUT-Wertungen nicht relevant. */
    private const array COURSES = ['LCM', 'SCM'];

    private const array GENDERS = ['M', 'F'];

    public function __construct(
        // Nur die Klassifizierungsnummer (z. B. "9"), nicht der volle sport_class-Code:
        // S9/SB9/SM9 sind dieselbe Klassifizierung in unterschiedlichen Lagen — siehe
        // PublicRecordService::forFilter().
        public string $sportClass = '',
        public string $gender = '',
        public string $course = '',
        public string $association = '', // '' = national (AUT), sonst LV-Code, z.B. 'WBSV'
        public bool $youth = false,
    ) {}

    /**
     * Baut den Filter aus Abfrageparametern. Unbekannte Werte fallen auf den Standard zurück
     * (analog QualificationOverviewFilter) statt eine leere Liste zu erzeugen.
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $association = (string) ($query['association'] ?? '');
        if (! array_key_exists($association, Club::REGIONAL_ASSOCIATIONS)) {
            $association = '';
        }

        $course = (string) ($query['course'] ?? '');
        if (! in_array($course, self::COURSES, true)) {
            $course = '';
        }

        $gender = (string) ($query['gender'] ?? '');
        if (! in_array($gender, self::GENDERS, true)) {
            $gender = '';
        }

        return new self(
            trim((string) ($query['sport_class'] ?? '')),
            $gender,
            $course,
            $association,
            (bool) ($query['youth'] ?? false),
        );
    }

    /** Der tatsächliche record_type-Wert für die Datenbankabfrage, z. B. "AUT.WBSV.JR". */
    public function recordType(): string
    {
        $base = $this->association === '' ? 'AUT' : 'AUT.'.$this->association;

        return $this->youth ? $base.'.JR' : $base;
    }

    public function isRegional(): bool
    {
        return $this->association !== '';
    }

    /**
     * Als Abfrageparameter — damit Filterformular, LENEX- und PDF-Link denselben Stand mitnehmen.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'sport_class' => $this->sportClass,
            'gender' => $this->gender,
            'course' => $this->course,
            'association' => $this->association,
            'youth' => $this->youth ? '1' : '',
        ], static fn (string $value): bool => $value !== '');
    }
}
