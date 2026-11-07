<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Entry;
use App\Models\Meet;
use App\Models\RelayEntry;
use App\Models\ScoringGroup;
use App\Models\SwimEvent;
use App\Support\AthleteAge;
use App\Support\MedalCount;
use App\Support\MedalDetailRow;
use App\Support\MedalRule;
use App\Support\MedalTableRow;
use App\Support\MeetEntrySummary;
use Illuminate\Support\Collection;

/**
 * MeetEntrySummaryService — Kennzahlen aus den Meldungen einer Veranstaltung (docs/specs/meet-entry-summary.md).
 *
 * Eine Planzahl, keine Vergabe nach Ergebnissen; berechnet bei jedem Aufruf, nichts wird gespeichert.
 *
 * - Teilnehmer: Meldungen ohne Status EXH (AK), WDR, SICK, RJC; Staffeln außerdem nicht außer Konkurrenz.
 * - Zuordnung wie bei Ergebnissen über ScoringGroup::matches(): Geschlecht, Klassennummer (entries.sport_class, leer =
 *   Klasse des Athleten passend zur Lage), Jahrgangsalter, Landesverband des meldenden Vereins, Level zum
 *   Wettkampfdatum. Mehrfachwertung zählt in jeder passenden Gruppe.
 * - Medaillen je Wertungsgruppe und Bewerb nach MedalRule, nur Gruppen mit Medaillenwertung; Staffeln je Team so
 *   viele Medaillen wie Schwimmer. Zusammengefasst nach Titel (ÖJM, ÖBM ...), ohne Titel nach Gruppenname.
 * - Jugendliche (0-18 nach der Stichtagsregel) und Sportler der B-Meisterschaft: jede Person einmal, aus Einzel- und
 *   Staffelmeldungen.
 */
final readonly class MeetEntrySummaryService
{
    /** Meldestatus, die nicht als Teilnahme zählen. */
    public const array NON_COUNTING_STATUSES = ['EXH', 'WDR', 'SICK', 'RJC'];

    /** Höchstalter der Jugend (Alter zum 31. Dezember des Wettkampfjahres). */
    public const int YOUTH_MAX_AGE = 18;

    /** Reihenfolge der Titel in der Aufstellung; Gruppen ohne Titel folgen alphabetisch. */
    private const array TITLE_ORDER = [
        ScoringGroup::TITLE_STATE => 0,
        ScoringGroup::TITLE_NATIONAL => 1,
        ScoringGroup::TITLE_JUNIOR => 2,
        ScoringGroup::TITLE_B => 3,
    ];

    public function summarize(Meet $meet): MeetEntrySummary
    {
        $year = (int) $meet->start_date->format('Y');
        $date = $meet->start_date->toDateString();
        $reductionRule = $meet->medal_reduction_rule;

        $events = SwimEvent::where('meet_id', $meet->id)
            ->with(['scoringGroups', 'strokeType'])
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->orderBy('id')
            ->get();

        $entries = Entry::where('meet_id', $meet->id)
            ->with(['athlete.sportClasses', 'club'])
            ->get()
            ->filter(fn (Entry $e): bool => $e->athlete !== null && ! in_array($e->status, self::NON_COUNTING_STATUSES, true));

        $relayEntries = RelayEntry::where('meet_id', $meet->id)
            ->with(['members.athlete', 'club'])
            ->get()
            ->filter(fn (RelayEntry $e): bool => ! $e->is_exhibition && ! in_array($e->status, self::NON_COUNTING_STATUSES, true));

        $levelGroups = $events->flatMap->scoringGroups->contains(fn (ScoringGroup $g): bool => $g->levelCodes() !== []);
        $levels = $levelGroups
            ? ScoringGroupService::levelsAt($entries->pluck('athlete_id')->unique()->values()->all(), $date)
            : [];

        $entriesByEvent = $entries->groupBy('swim_event_id');
        $relaysByEvent = $relayEntries->groupBy('swim_event_id');

        /** @var array<int, Athlete> $people jede gemeldete Person einmal */
        $people = [];
        /** @var array<int, true> $bAthletes */
        $bAthletes = [];
        $details = [];
        $eventsWithoutGroups = [];
        $unassigned = 0;
        $unevaluableRelays = 0;

        foreach ($events as $event) {
            $groups = $event->scoringGroups;
            $isRelay = $event->relay_count > 1;
            $eventEntries = $isRelay ? ($relaysByEvent[$event->id] ?? collect()) : ($entriesByEvent[$event->id] ?? collect());
            if ($eventEntries->isEmpty()) {
                continue;
            }

            foreach ($eventEntries as $entry) {
                foreach (self::athletesOf($entry) as $athlete) {
                    $people[$athlete->id] = $athlete;
                }
            }

            if ($groups->isEmpty()) {
                $eventsWithoutGroups[] = $event->selection_label;
                if ($isRelay) {
                    $unevaluableRelays += $eventEntries->count();
                }

                continue;
            }

            // Staffeln ohne eingetragene Schwimmer lassen sich nicht zuordnen.
            if ($isRelay) {
                $withoutMembers = $eventEntries->filter(fn (RelayEntry $e): bool => $e->members->isEmpty());
                $unevaluableRelays += $withoutMembers->count();
                $eventEntries = $eventEntries->diffKeys($withoutMembers);
            }

            $matched = [];
            foreach ($groups as $group) {
                $members = $eventEntries->filter(function (Entry|RelayEntry $entry) use ($group, $event, $year, $levels): bool {
                    [$gender, $class, $age, $region, $level] = self::traits($entry, $event, $year, $levels);

                    return $group->matches($gender, $class, $age, $region, $level);
                });
                foreach ($members->keys() as $key) {
                    $matched[$key] = true;
                }

                if ($group->title === ScoringGroup::TITLE_B) {
                    foreach ($members as $entry) {
                        foreach (self::athletesOf($entry) as $athlete) {
                            $bAthletes[$athlete->id] = true;
                        }
                    }
                }

                if ($group->awards_medals && $members->isNotEmpty()) {
                    $medals = MedalRule::forParticipants($members->count(), $reductionRule);
                    $details[] = new MedalDetailRow(
                        $event->id,
                        $event->selection_label,
                        self::scoringLabel($group),
                        $group->label(),
                        $isRelay,
                        $members->count(),
                        $isRelay ? $medals->times($event->relay_count) : $medals,
                    );
                }
            }
            $unassigned += $eventEntries->count() - count($matched);
        }

        $ages = collect($people)->map(fn (Athlete $a): ?int => AthleteAge::atEndOf($a, $year));
        $hasBGroups = $events->flatMap->scoringGroups->contains(fn (ScoringGroup $g): bool => $g->title === ScoringGroup::TITLE_B);

        return new MeetEntrySummary(
            reductionRule: $reductionRule,
            athletes: count($people),
            youthAthletes: $ages->filter(fn (?int $age): bool => $age !== null && $age >= 0 && $age <= self::YOUTH_MAX_AGE)->count(),
            athletesWithoutBirthDate: $ages->filter(fn (?int $age): bool => $age === null)->count(),
            bChampionshipAthletes: $hasBGroups ? count($bAthletes) : null,
            medalRows: self::medalRows($details),
            details: $details,
            eventsWithoutGroups: $eventsWithoutGroups,
            unassignedEntries: $unassigned,
            groupsWithoutMedals: $events->flatMap->scoringGroups
                ->reject(fn (ScoringGroup $g): bool => $g->awards_medals)
                ->map(fn (ScoringGroup $g): string => $g->name)
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all(),
            unevaluableRelays: $unevaluableRelays,
        );
    }

    /**
     * Zuordnungsmerkmale einer Meldung wie in ScoringGroupService: [Geschlecht, Klassennummer, Jahrgangsalter,
     * Landesverband, Level]. Staffeln über das Geschlecht des Bewerbs und die Staffelklasse, ohne Alter und Level.
     *
     * @param  array<int, string>  $levels  athlete_id => Level zum Wettkampfdatum
     * @return array{0: ?string, 1: ?int, 2: ?int, 3: ?string, 4: ?string}
     */
    private static function traits(Entry|RelayEntry $entry, SwimEvent $event, int $year, array $levels): array
    {
        $region = $entry->club?->regional_association;

        if ($entry instanceof RelayEntry) {
            return [$event->gender, self::classNumber($entry->relay_class), null, $region, null];
        }

        $athlete = $entry->athlete;
        $class = $entry->sport_class ?: $athlete->getSportClass($event->classCategory())?->sport_class;

        return [
            $athlete->gender,
            self::classNumber($class),
            AthleteAge::atEndOf($athlete, $year),
            $region,
            $levels[$athlete->id] ?? $athlete->level,
        ];
    }

    /**
     * Gemeldete Personen einer Meldung: der Athlet bzw. die eingetragenen Staffelschwimmer.
     *
     * @return list<Athlete>
     */
    private static function athletesOf(Entry|RelayEntry $entry): array
    {
        if ($entry instanceof Entry) {
            return [$entry->athlete];
        }

        return $entry->members->pluck('athlete')->filter()->values()->all();
    }

    private static function classNumber(?string $class): ?int
    {
        $digits = preg_replace('/\D+/', '', (string) $class);

        return $digits === '' ? null : (int) $digits;
    }

    /** Wertung einer Gruppe: Kurzform des Titels, ohne Titel der Gruppenname. */
    private static function scoringLabel(ScoringGroup $group): string
    {
        return ScoringGroup::TITLE_SHORT[$group->title] ?? $group->name;
    }

    /**
     * Fasst die Details je Wertung zusammen, Einzel vor Staffel, Titel in fester Reihenfolge, sonst alphabetisch.
     *
     * @param  list<MedalDetailRow>  $details
     * @return list<MedalTableRow>
     */
    private static function medalRows(array $details): array
    {
        $titleOrder = [];
        foreach (self::TITLE_ORDER as $title => $order) {
            $titleOrder[ScoringGroup::TITLE_SHORT[$title]] = $order;
        }

        $grouped = collect($details)->groupBy(fn (MedalDetailRow $d): string => sprintf(
            '%d|%02d|%s',
            $d->isRelay ? 1 : 0,
            $titleOrder[$d->scoringLabel] ?? 99,
            mb_strtolower($d->scoringLabel),
        ))->sortKeys();

        return $grouped->map(fn (Collection $rows): MedalTableRow => new MedalTableRow(
            $rows->first()->scoringLabel,
            $rows->first()->isRelay,
            $rows->reduce(fn (MedalCount $sum, MedalDetailRow $d): MedalCount => $sum->plus($d->medals), MedalCount::none()),
        ))->values()->all();
    }
}
