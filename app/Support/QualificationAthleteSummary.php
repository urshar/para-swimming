<?php

namespace App\Support;

use App\Models\Athlete;
use Illuminate\Support\Collection;

/**
 * Ein Athlet mit allen Bewerben, für die bei der Meisterschaft eine Norm ausgeschrieben ist
 * (Spec "WPS Qualification" §7.5, Qualifikantenansicht).
 *
 * Bewerbe ohne Norm sind nicht enthalten: Sie sagen über die Qualifikation nichts aus und
 * würden die Liste um Zeilen aufblähen, zu denen es nichts zu entscheiden gibt.
 *
 * Nicht erfüllte Bewerbe bleiben dagegen drin — der Abstand zur Norm ist die eigentliche
 * Information für die Nominierungsentscheidung.
 */
final readonly class QualificationAthleteSummary
{
    /**
     * @param  Collection<int, QualificationRow>  $rows  Bewerbe mit Norm, gemischt erfüllt/offen
     * @param  string|null  $kaderName  Kaderart zum Stichtag, null wenn keine Zugehörigkeit
     * @param  int  $kaderSortOrder  Sortierung der Kaderart; ohne Zugehörigkeit ans Ende
     */
    public function __construct(
        public Athlete $athlete,
        public Collection $rows,
        public ?string $kaderName,
        public int $kaderSortOrder,
    ) {}

    /** Sportklasse für die Kopfzeile — die S-Klasse, nicht SB oder SM. */
    public function displaySportClass(): ?string
    {
        return self::primarySportClass($this->rows);
    }

    /**
     * Die Sportklasse, unter der ein Athlet geführt wird: die S-Klasse.
     *
     * SB und SM gelten nur für Brust und Lagen und können abweichen; als Kennzeichnung des
     * Athleten taugt allein die S-Klasse. Statisch, weil die Förderansicht dieselbe Angabe
     * braucht, dort aber kein Summary-Objekt vorliegt — zwei Kopien derselben Regel würden
     * auseinanderlaufen.
     *
     * @param  Collection<int, QualificationRow>  $rows
     */
    public static function primarySportClass(Collection $rows): ?string
    {
        return $rows
            ->map(static fn (QualificationRow $zeile): string => $zeile->sportClass)
            ->first(static fn (string $klasse): bool => str_starts_with($klasse, 'S')
                && ! str_starts_with($klasse, 'SB')
                && ! str_starts_with($klasse, 'SM'))
            ?? $rows->first()?->sportClass;
    }

    public function mqsCount(): int
    {
        return $this->rows
            ->filter(static fn (QualificationRow $zeile): bool => $zeile->status->status === QualificationStatus::MQS_MET
                || $zeile->status->status === QualificationStatus::OBSV_MET)
            ->count();
    }

    public function metCount(): int
    {
        return $this->rows
            ->filter(static fn (QualificationRow $zeile): bool => $zeile->status->status === QualificationStatus::MET_ONLY)
            ->count();
    }

    /** Bewerbe mit Norm, in denen bislang weder MQS noch MET erreicht wurde. */
    public function openCount(): int
    {
        return $this->rows->count() - $this->mqsCount() - $this->metCount();
    }
}
