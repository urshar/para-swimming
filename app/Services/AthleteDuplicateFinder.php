<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AthleteDuplicateDismissal;

/**
 * Dublettenvorschläge für "Athleten zusammenführen": Paare von Athleten, die vermutlich dieselbe Person sind.
 *
 * - stark: gleiche Lizenz, SDMS ID oder swimrankings-ID (nur Werte mit Ziffer, Platzhalter wie "W-" zählen nicht);
 *   gleicher Name und gleiches Geburtsdatum; vertauschter Vor- und Nachname
 * - mittel: gleicher Name, aber anderes oder fehlendes Geburtsdatum
 * - schwach: gleiches Geburtsdatum und Geschlecht und ein gemeinsamer Namensteil (wie die Vorschläge auf der
 *   LENEX-Klärungsseite) — trifft Tippfehler und Namenswechsel, aber auch Geschwister
 *
 * Vorbelegte Richtung: Es bleibt der Athlet mit mehr Daten (Ergebnisse, Rekorde, Meldungen), bei Gleichstand der mit
 * der kleineren ID (meist der Datensatz aus dem Team Manager). Als "kein Duplikat" markierte Paare
 * (AthleteDuplicateDismissal) fallen heraus, außer sie werden ausdrücklich mit angefordert.
 */
final readonly class AthleteDuplicateFinder
{
    public const string STRONG = 'strong';

    public const string MEDIUM = 'medium';

    public const string WEAK = 'weak';

    private const array STRENGTH_ORDER = [self::STRONG => 0, self::MEDIUM => 1, self::WEAK => 2];

    /** Kennungen, die eine Person eindeutig machen → Bezeichnung im Vorschlag. */
    private const array IDENTIFIERS = [
        'license' => 'Gleiche Lizenz',
        'license_ipc' => 'Gleiche SDMS ID',
        'swrid' => 'Gleiche swimrankings-ID',
    ];

    public function __construct(
        private ImportSuggestionService $suggestionService,
    ) {}

    /**
     * @return list<array{keep: Athlete, drop: Athlete, reasons: list<string>, strength: string,
     *     dismissal: ?AthleteDuplicateDismissal}>
     */
    public function find(bool $withDismissed): array
    {
        $athletes = Athlete::with('club')
            ->withCount(['results', 'swimRecords', 'entries'])
            ->orderBy('id')
            ->get()
            ->values();
        $profiles = $athletes->map(fn (Athlete $athlete) => $this->profile($athlete))->all();
        $dismissals = AthleteDuplicateDismissal::all()
            ->keyBy(fn (AthleteDuplicateDismissal $d) => $d->athlete_id.'-'.$d->other_athlete_id);

        $pairs = [];
        $count = count($profiles);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                [$reasons, $strength] = $this->compare($profiles[$i], $profiles[$j]);
                if ($reasons === []) {
                    continue;
                }

                // Nach id sortiert geladen: $athletes[$i] hat die kleinere ID.
                $dismissal = $dismissals->get($athletes[$i]->id.'-'.$athletes[$j]->id);
                if ($dismissal !== null && ! $withDismissed) {
                    continue;
                }

                [$keep, $drop] = $this->score($athletes[$j]) > $this->score($athletes[$i])
                    ? [$athletes[$j], $athletes[$i]]
                    : [$athletes[$i], $athletes[$j]];

                $pairs[] = ['keep' => $keep, 'drop' => $drop, 'reasons' => $reasons, 'strength' => $strength,
                    'dismissal' => $dismissal];
            }
        }

        usort($pairs, fn (array $a, array $b) => $this->sortKey($a) <=> $this->sortKey($b));

        return $pairs;
    }

    /**
     * @return array{name: string, swapped: string, parts: list<string>, birth_date: ?string, gender: string,
     *     identifiers: array<string, string>}
     */
    private function profile(Athlete $athlete): array
    {
        $last = $this->suggestionService->normalizeName((string) $athlete->last_name);
        $first = $this->suggestionService->normalizeName((string) $athlete->first_name);

        $identifiers = [];
        foreach (array_keys(self::IDENTIFIERS) as $column) {
            $value = trim((string) $athlete->$column);
            if (preg_match('/\d/', $value) === 1) {
                $identifiers[$column] = mb_strtolower($value);
            }
        }

        return [
            'name' => "$last|$first",
            'swapped' => "$first|$last",
            'parts' => array_values(array_filter(preg_split('/[\s-]+/u', "$last $first"))),
            'birth_date' => $athlete->birth_date?->toDateString(),
            'gender' => (string) $athlete->gender,
            'identifiers' => $identifiers,
        ];
    }

    /**
     * @return array{0: list<string>, 1: string} Gründe und stärkste Stufe
     */
    private function compare(array $a, array $b): array
    {
        $reasons = [];
        $strength = null;

        foreach (self::IDENTIFIERS as $column => $label) {
            if (isset($a['identifiers'][$column]) && $a['identifiers'][$column] === ($b['identifiers'][$column] ?? null)) {
                $reasons[] = $label;
                $strength = self::STRONG;
            }
        }

        $sameBirthDate = $a['birth_date'] !== null && $a['birth_date'] === $b['birth_date'];

        if ($a['name'] === $b['name']) {
            $reasons[] = $sameBirthDate ? 'Gleicher Name und Geburtsdatum' : 'Gleicher Name, anderes Geburtsdatum';
            $strength ??= $sameBirthDate ? self::STRONG : self::MEDIUM;
        } elseif ($a['name'] === $b['swapped']) {
            $reasons[] = 'Vertauschter Vor- und Nachname';
            $strength = self::STRONG;
        } elseif ($sameBirthDate && $a['gender'] === $b['gender']
            && array_intersect($a['parts'], $b['parts']) !== []) {
            $reasons[] = 'Gleiches Geburtsdatum, gemeinsamer Namensteil';
            $strength ??= self::WEAK;
        }

        return [$reasons, $strength ?? self::WEAK];
    }

    /** Je mehr Daten am Athleten hängen, desto eher bleibt er. */
    private function score(Athlete $athlete): int
    {
        return $athlete->results_count + $athlete->swim_records_count + $athlete->entries_count;
    }

    private function sortKey(array $pair): string
    {
        return sprintf('%d|%s|%s|%010d', self::STRENGTH_ORDER[$pair['strength']],
            mb_strtolower((string) $pair['keep']->last_name), mb_strtolower((string) $pair['keep']->first_name),
            $pair['keep']->id);
    }
}
