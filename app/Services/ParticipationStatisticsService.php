<?php

namespace App\Services;

use App\Models\AgeGroup;
use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\Result;
use App\Support\ReportConfiguration;
use App\Support\SportClassSorter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use stdClass;

/**
 * ParticipationStatisticsService
 *
 * Basis-Zählmaschine der Statistik (Spec Phase 2): Anzahl Veranstaltungen,
 * Teilnehmer, Vereine, Starts und Teilnahmen für eine ReportConfiguration.
 *
 * Alle Kennzahlen werden live aus `results` berechnet — es wird nichts
 * persistiert (Spec §23). Ändert oder löscht sich ein Ergebnis, ändern sich
 * die Kennzahlen beim nächsten Aufruf automatisch mit.
 *
 * Die Definition eines "Starts" ist bewusst an genau einer Stelle gekapselt
 * (startsQuery), damit sie zentral anpassbar und in Phase 16 gegen die
 * Referenzzahlen des ÖBSV kalibrierbar bleibt.
 *
 * statusBreakdown() liefert ergänzend, wie oft jeder Ergebnisstatus vorkam
 * (inkl. DNS/SICK/WDR), damit der Bericht diese Fälle ausweisen kann.
 */
final readonly class ParticipationStatisticsService
{
    /**
     * Statuswerte, bei denen der Athlet NICHT angetreten ist und die daher
     * nicht als Start zählen (fachliche Definition B, mit dem ÖBSV bestätigt):
     *   DNS  = did not start
     *   SICK = krank abgemeldet
     *   WDR  = zurückgezogen
     *
     * Als Start zählen dagegen reguläre Ergebnisse (status = null) sowie DSQ,
     * DNF und EXH — in diesen Fällen ist der Athlet angetreten.
     *
     * @var list<string>
     */
    private const array NON_START_STATUSES = ['DNS', 'SICK', 'WDR'];

    /**
     * Alle Nicht-null-Statuswerte des results.status-Enums, in Enum-Reihenfolge.
     * Reguläre Ergebnisse (status = null) werden getrennt unter dem Schlüssel
     * 'regular' geführt.
     *
     * @var list<string>
     */
    private const array SPECIAL_STATUSES = ['EXH', 'DSQ', 'DNS', 'DNF', 'SICK', 'WDR'];

    /**
     * Ausgabereihenfolge der Geschlechter — spiegelt die Reihenfolge des
     * bestehenden Enums athletes.gender wider. Es wird bewusst nur der Code
     * geliefert; die deutschsprachige Beschriftung erfolgt wie im übrigen
     * Projekt in der View.
     *
     * @var list<string>
     */
    private const array GENDER_ORDER = ['M', 'F', 'N'];

    /**
     * Ausgabereihenfolge der Staffel-Geschlechter. Maßgeblich ist das
     * Geschlecht des Staffel-Bewerbs (swim_events.gender), nicht das des
     * einzelnen Schwimmers: Herren (M), Damen (F), Mixed (X). Der
     * Default-Wert 'A' (all) kommt bei Staffeln nicht vor und wird daher
     * bewusst nicht geführt.
     *
     * @var list<string>
     */
    private const array RELAY_GENDER_ORDER = ['M', 'F', 'X'];

    /**
     * Gruppierungsschlüssel für den sichtbaren Sammeleintrag, unter dem
     * Datensätze ohne auflösbare Zuordnung ausgewiesen werden (statt sie
     * stillschweigend zu verwerfen). Er kann mit keiner echten ID kollidieren.
     */
    private const string UNASSIGNED_KEY = '__unassigned__';

    /** Bezeichnung des Sammeleintrags für Sportklassen ohne Gruppenzuordnung. */
    private const string LABEL_NO_DISABILITY_GROUP = 'Ohne Zuordnung';

    /** Bezeichnung des Sammeleintrags für Athleten ohne Geburtsdatum. */
    private const string LABEL_NO_AGE_GROUP = 'Ohne Geburtsdatum';

    /**
     * Die Zuordnung Sportklasse => Behinderungsgruppe stammt ausschließlich aus
     * dem bestehenden GroupResolverService (Spec Phase 7: keine hardcodierten
     * Listen, neue Sportklassen/Gruppen wirken automatisch).
     */
    public function __construct(
        private GroupResolverService $groupResolver,
    ) {}

    /**
     * Basiskennzahlen für den Übersichtsabschnitt.
     *
     * @return array{
     *     meets: int,
     *     participants: int,
     *     clubs: int,
     *     foreign_clubs: int,
     *     starts: int,
     *     participations: int
     * }
     */
    public function overview(ReportConfiguration $config): array
    {
        $base = $this->startsQuery($config);
        $clubs = $this->clubBreakdown($base);

        return [
            'meets' => (clone $base)->distinct()->count('meet_id'),
            'participants' => (clone $base)->distinct()->count('athlete_id'),
            'clubs' => $clubs['austrian'],
            'foreign_clubs' => $clubs['foreign'],
            'starts' => (clone $base)->count(),
            'participations' => $this->countParticipations($base),
        ];
    }

    /**
     * Anzahl der Veranstaltungen mit mindestens einem gewerteten (Einzel-)Start
     * im Auswertungsumfang — dieselbe Definition wie overview()['meets'], aber
     * ohne die übrigen Kennzahlen zu berechnen (für die Mehrjahres-Zeitreihe).
     */
    public function meetsWithStarts(ReportConfiguration $config): int
    {
        return $this->startsQuery($config)->distinct()->count('meet_id');
    }

    /**
     * Ergebnisse pro Status im Auswertungsumfang (Einzelbewerbe, gleicher
     * Umfang wie overview()). Enthält ausdrücklich auch die "nicht
     * angetreten"-Status DNS, SICK und WDR sowie reguläre Ergebnisse
     * (status = null) unter dem Schlüssel 'regular'.
     *
     * Alle bekannten Schlüssel erscheinen immer (0, falls nicht vorhanden), in
     * stabiler Reihenfolge (regular, dann Enum-Reihenfolge) — praktisch für
     * Berichtstabellen. Die Summe aller Werte entspricht der Gesamtzahl der
     * Einzelergebnisse im Umfang; die Summe von regular + EXH + DSQ + DNF
     * entspricht der Startzahl aus overview().
     *
     * @return array<string, int>
     */
    public function statusBreakdown(ReportConfiguration $config): array
    {
        $breakdown = ['regular' => 0];
        foreach (self::SPECIAL_STATUSES as $status) {
            $breakdown[$status] = 0;
        }

        $rows = $this->scopedQuery($config)
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->get();

        foreach ($rows as $row) {
            $key = $row->status ?? 'regular';
            $breakdown[$key] = (int) $row->aggregate;
        }

        return $breakdown;
    }

    /**
     * Status-Aufschlüsselung je Veranstaltung (Einzelbewerbe): pro Meet die
     * Anzahl regulärer Ergebnisse sowie je Sonderstatus (EXH/DSQ/DNS/DNF/SICK/
     * WDR). Alle Schlüssel erscheinen immer (0, falls nicht vorhanden), in
     * derselben Reihenfolge wie statusBreakdown().
     *
     * Es werden nur Veranstaltungen mit mindestens einem Einzelergebnis
     * geliefert, sortiert chronologisch (start_date, dann Name).
     *
     * @return Collection<int, array{meet_id: int, meet: string, start_date: ?string, statuses: array<string, int>, total: int}>
     */
    public function statusByMeet(ReportConfiguration $config): Collection
    {
        $rows = $this->scopedQuery($config)
            ->toBase()
            ->selectRaw('results.meet_id as meet_id, results.status as status, COUNT(*) as aggregate')
            ->groupBy('results.meet_id', 'results.status')
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        $template = ['regular' => 0];
        foreach (self::SPECIAL_STATUSES as $status) {
            $template[$status] = 0;
        }

        $byMeet = [];
        foreach ($rows as $row) {
            $meetId = (int) $row->meet_id;
            $byMeet[$meetId] ??= $template;
            $byMeet[$meetId][$row->status ?? 'regular'] = (int) $row->aggregate;
        }

        return Meet::query()
            ->whereIn('id', array_keys($byMeet))
            ->oldest('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date'])
            ->map(fn (Meet $meet): array => [
                'meet_id' => $meet->id,
                'meet' => $meet->name,
                'start_date' => $meet->start_date?->toDateString(),
                'statuses' => $byMeet[$meet->id],
                'total' => array_sum($byMeet[$meet->id]),
            ])
            ->values();
    }

    /**
     * Veranstaltungsstatistik (Spec Phase 3): pro Veranstaltung im Umfang die
     * Anzahl Teilnehmer (unterschiedliche Athleten) und Starts.
     *
     * Ein Athlet zählt je Veranstaltung nur einmal als Teilnehmer. Es werden
     * nur Veranstaltungen mit mindestens einem relevanten Start geliefert
     * (leere Meets erscheinen nicht), sortiert chronologisch nach start_date,
     * dann nach Name.
     *
     * @return Collection<int, array{meet_id: int, meet: string, start_date: ?string, participants: int, starts: int}>
     */
    public function byMeet(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->selectRaw('meet_id, COUNT(*) as starts, COUNT(DISTINCT athlete_id) as participants')
            ->groupBy('meet_id')
            ->get()
            ->keyBy('meet_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Meet::query()
            ->whereIn('id', $aggregates->keys())
            ->oldest('start_date')
            ->orderBy('name')
            ->get(['id', 'name', 'start_date'])
            ->map(fn (Meet $meet): array => [
                'meet_id' => $meet->id,
                'meet' => $meet->name,
                'start_date' => $meet->start_date?->toDateString(),
                'participants' => (int) $aggregates[$meet->id]->participants,
                'starts' => (int) $aggregates[$meet->id]->starts,
            ])
            ->values();
    }

    /**
     * Vereinsstatistik (Spec Phase 4): pro Verein die Anzahl Teilnehmer
     * (unterschiedliche Athleten) und Starts, absteigend gereiht.
     *
     * Verein = Verein zum Zeitpunkt des Ergebnisses (results.club_id). Ein
     * Athlet zählt je Verein nur einmal als Teilnehmer. Gereiht wird nach
     * Starts (desc), dann Teilnehmer (desc), dann Name (asc); 'rank' ist die
     * 1-basierte Position. Es werden alle beteiligten Vereine (inkl.
     * ausländischer, erkennbar am Nationscode) geliefert.
     *
     * @return Collection<int, array{rank: int, club_id: int, club: string, nation: ?string, participants: int, starts: int}>
     */
    public function byClub(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->selectRaw('club_id, COUNT(*) as starts, COUNT(DISTINCT athlete_id) as participants')
            ->groupBy('club_id')
            ->get()
            ->keyBy('club_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Club::query()
            ->with('nation:id,code')
            ->whereIn('id', $aggregates->keys())
            ->get(['id', 'name', 'nation_id'])
            ->map(fn (Club $club): array => [
                'club_id' => $club->id,
                'club' => $club->name,
                'nation' => $club->nation?->code,
                'participants' => (int) $aggregates[$club->id]->participants,
                'starts' => (int) $aggregates[$club->id]->starts,
            ])
            ->sort(fn (array $a, array $b): int => [$b['starts'], $b['participants']] <=> [$a['starts'], $a['participants']]
                ?: strcmp($a['club'], $b['club']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * Sportlerstatistik (Spec Phase 5): pro Sportler die Anzahl der
     * Veranstaltungs-Teilnahmen (unterschiedliche Meets) und Starts, gereiht
     * nach den meisten Teilnahmen.
     *
     * Ein Sportler zählt je Veranstaltung nur einmal als Teilnahme. Gereiht
     * wird nach Teilnahmen (desc), dann Starts (desc), dann Name (asc);
     * 'rank' ist die 1-basierte Position. Der Nationscode stammt aus der
     * Nation des Sportlers (nicht des Vereins), da in Österreich lebende
     * EU-Bürger für österreichische Vereine starten können.
     *
     * @return Collection<int, array{rank: int, athlete_id: int, athlete: string, nation: ?string, participations: int, starts: int}>
     */
    public function byAthlete(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->selectRaw('athlete_id, COUNT(*) as starts, COUNT(DISTINCT meet_id) as participations')
            ->groupBy('athlete_id')
            ->get()
            ->keyBy('athlete_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Athlete::query()
            ->with('nation:id,code')
            ->whereIn('id', $aggregates->keys())
            ->get()
            ->map(fn (Athlete $athlete): array => [
                'athlete_id' => $athlete->id,
                'athlete' => $athlete->display_name,
                'nation' => $athlete->nation?->code,
                'participations' => (int) $aggregates[$athlete->id]->participations,
                'starts' => (int) $aggregates[$athlete->id]->starts,
            ])
            ->sort(fn (array $a, array $b): int => [$b['participations'], $b['starts']] <=> [$a['participations'], $a['starts']]
                ?: strcmp($a['athlete'], $b['athlete']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * Anzahl Sportler mit mindestens X Veranstaltungs-Teilnahmen (Spec Phase 5).
     * X stammt aus der Konfiguration (min_participations, Standard 2).
     *
     * Der Schwellenwert wird bewusst in PHP geprüft: Ein gebundener Parameter
     * in HAVING wird von PDO als String übergeben, wodurch SQLite den Vergleich
     * Integer/Text falsch auswertet. Die Aggregatmenge (eine Zeile je Sportler)
     * ist klein genug, um sie zu laden und zu filtern.
     */
    public function countAthletesWithMinParticipations(ReportConfiguration $config): int
    {
        return $this->startsQuery($config)
            ->selectRaw('athlete_id, COUNT(DISTINCT meet_id) as participations')
            ->groupBy('athlete_id')
            ->get()
            ->filter(fn ($row): bool => (int) $row->participations >= $config->minParticipations)
            ->count();
    }

    /**
     * Nationenstatistik (Spec Phase 6): pro Nation die Anzahl Teilnehmer
     * (unterschiedliche Athleten) und Starts, absteigend gereiht.
     *
     * Zuordnung über die Nation des Sportlers (athletes.nation_id), nicht die
     * Vereinsnation — konsistent mit der Sportlerstatistik. Es werden alle
     * beteiligten Nationen inkl. AUT geliefert. Gereiht nach Starts (desc),
     * dann Teilnehmer (desc), dann Nationscode (asc); 'rank' ist die
     * 1-basierte Position.
     *
     * @return Collection<int, array{rank: int, nation_id: int, nation: string, nation_name: string, participants: int, starts: int}>
     */
    public function byNation(ReportConfiguration $config): Collection
    {
        $aggregates = $this->startsQuery($config)
            ->join('athletes', 'athletes.id', '=', 'results.athlete_id')
            ->selectRaw('athletes.nation_id as nation_id, COUNT(*) as starts, COUNT(DISTINCT results.athlete_id) as participants')
            ->groupBy('athletes.nation_id')
            ->get()
            ->keyBy('nation_id');

        if ($aggregates->isEmpty()) {
            return collect();
        }

        return Nation::query()
            ->whereIn('id', $aggregates->keys())
            ->get(['id', 'code', 'name_de'])
            ->map(fn (Nation $nation): array => [
                'nation_id' => $nation->id,
                'nation' => $nation->code,
                'nation_name' => $nation->name_de,
                'participants' => (int) $aggregates[$nation->id]->participants,
                'starts' => (int) $aggregates[$nation->id]->starts,
            ])
            ->sort(fn (array $a, array $b): int => [$b['starts'], $b['participants']] <=> [$a['starts'], $a['participants']]
                ?: strcmp($a['nation'], $b['nation']))
            ->values()
            ->map(fn (array $row, int $index): array => ['rank' => $index + 1] + $row);
    }

    /**
     * Sportklassenstatistik (Spec Phase 7): pro Sportklasse die Anzahl
     * Teilnehmer (unterschiedliche Athleten) und Starts, samt zugehöriger
     * Behinderungsgruppe.
     *
     * Die Sportklasse stammt aus dem Ergebnis (results.sport_class), die
     * Gruppenzuordnung ausschließlich aus dem bestehenden GroupResolverService.
     * Dadurch werden neu angelegte Sportklassen und Gruppen automatisch
     * berücksichtigt — es gibt keine Liste im Statistikcode.
     *
     * Ergebnisse ohne Sportklasse bleiben unberücksichtigt (sie lassen sich
     * keiner Klasse zuordnen). Sortiert numerisch korrekt über den bestehenden
     * SportClassSorter (S2 vor S10).
     *
     * @return Collection<int, array{sport_class: string, group_code: ?string, group_name: ?string, participants: int, starts: int}>
     */
    public function bySportClass(ReportConfiguration $config): Collection
    {
        $pairs = $this->sportClassAthletePairs($config);

        if ($pairs->isEmpty()) {
            return collect();
        }

        $sportClassMap = $this->groupResolver->loadSportClassMap();

        return $pairs
            ->groupBy('sport_class')
            ->map(function (Collection $rows, string $sportClass) use ($sportClassMap): array {
                $group = $this->groupResolver->resolveBaseSportClassGroup($sportClass, $sportClassMap);

                return [
                    'sport_class' => $sportClass,
                    'group_code' => $group?->code,
                    'group_name' => $group?->name_de,
                    'participants' => $rows->pluck('athlete_id')->unique()->count(),
                    'starts' => (int) $rows->sum('starts'),
                ];
            })
            ->sortBy(fn (array $row): string => SportClassSorter::key($row['sport_class']))
            ->values();
    }

    /**
     * Behinderungsgruppenstatistik (Spec Phase 7): pro Gruppe die Anzahl
     * Teilnehmer und Starts.
     *
     * Ein Athlet zählt je Gruppe nur einmal, auch wenn er in mehreren
     * Sportklassen derselben Gruppe startet (z.B. S9 und SB8 → beide PI).
     *
     * Sportklassen ohne hinterlegte Gruppenzuordnung werden NICHT verworfen,
     * sondern als sichtbarer Sammeleintrag am Ende ausgewiesen (group_id =
     * null). So bleiben solche Fälle kontrollierbar; welche Sportklassen
     * betroffen sind, zeigt bySportClass() (dort group_code = null).
     *
     * Sortiert nach der im Stammdatensatz gepflegten sort_order der Gruppe,
     * der Sammeleintrag steht immer am Schluss.
     *
     * @return Collection<int, array{group_id: ?int, group_code: ?string, group_name: string, participants: int, starts: int}>
     */
    public function byDisabilityGroup(ReportConfiguration $config): Collection
    {
        $pairs = $this->sportClassAthletePairs($config);

        if ($pairs->isEmpty()) {
            return collect();
        }

        $sportClassMap = $this->groupResolver->loadSportClassMap();

        return $pairs
            ->map(fn (array $row): array => $row + [
                'group' => $this->groupResolver->resolveBaseSportClassGroup($row['sport_class'], $sportClassMap),
            ])
            ->groupBy(fn (array $row): string => $row['group'] === null
                ? self::UNASSIGNED_KEY
                : (string) $row['group']->id)
            ->sortBy(fn (Collection $rows): int => (int) ($rows->first()['group']->sort_order ?? PHP_INT_MAX))
            ->map(function (Collection $rows): array {
                $group = $rows->first()['group'];

                return [
                    'group_id' => $group?->id,
                    'group_code' => $group?->code,
                    'group_name' => $group->name_de ?? self::LABEL_NO_DISABILITY_GROUP,
                    'participants' => $rows->pluck('athlete_id')->unique()->count(),
                    'starts' => (int) $rows->sum('starts'),
                ];
            })
            ->values();
    }

    /**
     * Altersgruppenstatistik (Spec Phase 8): pro Altersgruppe die Anzahl
     * Teilnehmer und Starts.
     *
     * Die Zuordnung erfolgt ausschließlich über die bestehende
     * GroupResolverService::resolveAgeGroup() — inklusive der 31.12.-Stichtags-
     * regel (maßgeblich ist das Alter am Jahresende des Wettkampfjahres) und
     * der in age_groups gepflegten Grenzen (JUGEND ≤ 18, OFFEN ≥ 19). Es gibt
     * hier bewusst KEINE eigene Altersberechnung.
     *
     * Weil der Stichtag am Jahresende liegt, wird je (Athlet, Veranstaltung)
     * aufgelöst: erstreckt sich ein Bericht über mehrere Jahre, kann derselbe
     * Athlet im ersten Jahr der Jugend und im zweiten der offenen Klasse
     * zugeordnet sein. Innerhalb einer Gruppe zählt er dennoch nur einmal.
     *
     * Athleten ohne Geburtsdatum liefern keine Altersgruppe und werden als
     * sichtbarer Sammeleintrag am Ende ausgewiesen (age_group_id = null),
     * damit solche Datenlücken auffallen und nachgepflegt werden können.
     *
     * @return Collection<int, array{age_group_id: ?int, age_group_code: ?string, age_group_name: string, participants: int, starts: int}>
     */
    public function byAgeGroup(ReportConfiguration $config): Collection
    {
        return $this->ageGenderRows($config)
            ->groupBy(fn (array $row): string => $row['age_group'] === null
                ? self::UNASSIGNED_KEY
                : (string) $row['age_group']->id)
            ->sortBy(fn (Collection $rows): int => (int) ($rows->first()['age_group']->sort_order ?? PHP_INT_MAX))
            ->map(function (Collection $rows): array {
                $ageGroup = $rows->first()['age_group'];

                return [
                    'age_group_id' => $ageGroup?->id,
                    'age_group_code' => $ageGroup?->code,
                    'age_group_name' => $ageGroup->name_de ?? self::LABEL_NO_AGE_GROUP,
                    'participants' => $rows->pluck('athlete_id')->unique()->count(),
                    'starts' => (int) $rows->sum('starts'),
                ];
            })
            ->values();
    }

    /**
     * Kreuzauswertung Altersgruppe × Geschlecht (Spec Phase 8): je Kombination
     * die Anzahl Teilnehmer und Starts.
     *
     * Verwendet dieselbe Zuordnung wie byAgeGroup() und byGender(); auch hier
     * erscheinen Athleten ohne Geburtsdatum im Sammeleintrag. Sortiert nach
     * Altersgruppe (sort_order, Sammeleintrag zuletzt), innerhalb der
     * Altersgruppe nach der Enum-Reihenfolge des Geschlechts.
     *
     * @return Collection<int, array{age_group_id: ?int, age_group_code: ?string, age_group_name: string, gender: string, participants: int, starts: int}>
     */
    public function byAgeGroupAndGender(ReportConfiguration $config): Collection
    {
        $order = array_flip(self::GENDER_ORDER);

        return $this->ageGenderRows($config)
            ->groupBy(fn (array $row): string => ($row['age_group']?->id ?? self::UNASSIGNED_KEY).'|'.$row['gender'])
            ->sortBy(fn (Collection $rows): string => sprintf(
                '%011d-%03d',
                (int) ($rows->first()['age_group']->sort_order ?? PHP_INT_MAX),
                $order[$rows->first()['gender']] ?? count($order),
            ))
            ->map(function (Collection $rows): array {
                $ageGroup = $rows->first()['age_group'];

                return [
                    'age_group_id' => $ageGroup?->id,
                    'age_group_code' => $ageGroup?->code,
                    'age_group_name' => $ageGroup->name_de ?? self::LABEL_NO_AGE_GROUP,
                    'gender' => $rows->first()['gender'],
                    'participants' => $rows->pluck('athlete_id')->unique()->count(),
                    'starts' => (int) $rows->sum('starts'),
                ];
            })
            ->values();
    }

    /**
     * Geschlechterstatistik (Spec Phase 8): pro Geschlecht die Anzahl
     * Teilnehmer und Starts.
     *
     * Maßgeblich ist das bestehende Feld athletes.gender (Enum M/F/N). Es wird
     * nur der Code geliefert; die Beschriftung übernimmt wie im übrigen Projekt
     * die View. Sortiert in der Reihenfolge des Enums.
     *
     * @return Collection<int, array{gender: string, participants: int, starts: int}>
     */
    public function byGender(ReportConfiguration $config): Collection
    {
        $pairs = $this->athleteMeetPairs($config);

        if ($pairs->isEmpty()) {
            return collect();
        }

        $genders = Athlete::query()
            ->whereIn('id', $pairs->pluck('athlete_id')->unique())
            ->pluck('gender', 'id');

        $order = array_flip(self::GENDER_ORDER);

        return $pairs
            ->map(fn (array $row): ?array => isset($genders[$row['athlete_id']])
                ? $row + ['gender' => (string) $genders[$row['athlete_id']]]
                : null)
            ->filter()
            ->groupBy('gender')
            ->map(fn (Collection $rows, string $gender): array => [
                'gender' => $gender,
                'participants' => $rows->pluck('athlete_id')->unique()->count(),
                'starts' => (int) $rows->sum('starts'),
            ])
            ->sortBy(fn (array $row): int => $order[$row['gender']] ?? count($order))
            ->values();
    }

    /**
     * Staffelstarts je Event-Geschlecht (Herren = M, Damen = F, Mixed = X) im
     * Auswertungsumfang, gezählt "pro Athlet": jede angetretene
     * Staffel-Ergebniszeile (ein eingesetzter Schwimmer) zählt einzeln.
     *
     * Maßgeblich ist das Geschlecht des Staffel-Bewerbs (swim_events.gender),
     * nicht das des einzelnen Schwimmers — eine Mixed-Staffel bleibt Mixed (X),
     * unabhängig von der Zusammensetzung. Ausschließlich Staffeln
     * (relay_count > 1); Einzelbewerbe bleiben außen vor.
     *
     * Als "angetreten" gilt dieselbe Definition wie bei den Einzelstarts
     * (onlyStarted): reguläre Ergebnisse plus alle Status außer
     * NON_START_STATUSES. Alle drei Schlüssel erscheinen immer (0, falls nicht
     * vorhanden), in der Reihenfolge Herren, Damen, Mixed.
     *
     * @return array<string, int>
     */
    public function relayStartsByEventGender(ReportConfiguration $config): array
    {
        $counts = array_fill_keys(self::RELAY_GENDER_ORDER, 0);

        $rows = $this->onlyStarted($this->relayScopedQuery($config))
            ->join('swim_events', 'swim_events.id', '=', 'results.swim_event_id')
            ->toBase()
            ->selectRaw('swim_events.gender as gender, COUNT(*) as starts')
            ->groupBy('swim_events.gender')
            ->get();

        foreach ($rows as $row) {
            $gender = (string) $row->gender;

            if (array_key_exists($gender, $counts)) {
                $counts[$gender] = (int) $row->starts;
            }
        }

        return $counts;
    }

    /**
     * Gemeinsamer Auswertungsumfang für alle Einzel-Kennzahlen:
     *   - Einzelbewerbe (keine Staffeln — relay_count = 1),
     *   - eingeschränkt auf die ausgewählten Veranstaltungen; ohne Auswahl auf
     *     alle Meets, deren start_date im Zeitraum liegt.
     *
     * Bewusst OHNE Status-Filter, damit darauf sowohl die Startzählung
     * (startsQuery) als auch die vollständige Status-Aufschlüsselung
     * (statusBreakdown) aufsetzen können.
     *
     * Staffeln werden hier ausgeklammert; sie laufen über relayScopedQuery().
     */
    private function scopedQuery(ReportConfiguration $config): Builder
    {
        return $this->periodScopedQuery($config)
            ->whereHas('swimEvent', fn (Builder $q) => $q->where('relay_count', '<=', 1));
    }

    /**
     * Auswertungsumfang für Staffeln (relay_count > 1) — das Gegenstück zu
     * scopedQuery(). Staffelergebnisse liegen pro eingesetztem Schwimmer als
     * eigene Ergebniszeile vor (results.athlete_id ist Pflichtfeld); die
     * Zählweise "pro Athlet" ergibt sich damit direkt aus COUNT(*).
     *
     * Zeitraum- und Meet-Scope sind identisch zur Einzelauswertung
     * (periodScopedQuery); ebenfalls bewusst OHNE Status-Filter.
     */
    private function relayScopedQuery(ReportConfiguration $config): Builder
    {
        return $this->periodScopedQuery($config)
            ->whereHas('swimEvent', fn (Builder $q) => $q->where('relay_count', '>', 1));
    }

    /**
     * Reiner Zeitraum-/Meet-Scope ohne Staffel-Unterscheidung — gemeinsame
     * Grundlage von scopedQuery() (Einzel) und relayScopedQuery() (Staffel),
     * damit die Definition des Auswertungsfensters an genau einer Stelle liegt.
     */
    private function periodScopedQuery(ReportConfiguration $config): Builder
    {
        $query = Result::query();

        if ($config->isMeetFiltered()) {
            $query->whereIn('results.meet_id', $config->meetIds);
        } else {
            // whereDate() statt Bereichsvergleich: Eloquent schreibt
            // date-Felder mit Uhrzeit ("2024-12-31 00:00:00"), was auf SQLite
            // (Testsuite) ein reiner String-Vergleich ist — der letzte Tag des
            // Zeitraums fiele sonst heraus. whereDate() normalisiert beide
            // Seiten und verhält sich auf MySQL und SQLite gleich.
            $query->whereHas('meet', fn (Builder $q) => $q
                ->whereDate('meets.start_date', '>=', $config->dateFrom->toDateString())
                ->whereDate('meets.start_date', '<=', $config->dateTo->toDateString()));
        }

        return $query;
    }

    /**
     * Grundgesamtheit aller (Einzel-)Starts: der Auswertungsumfang,
     * eingeschränkt auf angetretene Ergebnisse (ohne NON_START_STATUSES).
     *
     * Wichtig: `status = null` (reguläres Ergebnis) muss ausdrücklich
     * eingeschlossen werden, weil `NOT IN (...)` in SQL bei NULL nicht greift.
     */
    private function startsQuery(ReportConfiguration $config): Builder
    {
        return $this->onlyStarted($this->scopedQuery($config));
    }

    /**
     * Schränkt einen Ergebnis-Query auf angetretene Ergebnisse ein (reguläre
     * Ergebnisse mit status = null sowie alle Status außer NON_START_STATUSES).
     * Ausgelagert, damit Einzel- (startsQuery) und Staffelauswertung
     * (relayStartsByEventGender) dieselbe Start-Definition teilen.
     */
    private function onlyStarted(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('results.status')
                ->orWhereNotIn('results.status', self::NON_START_STATUSES);
        });
    }

    /**
     * Anzahl Teilnahmen = Anzahl unterschiedlicher (Athlet, Veranstaltung)-
     * Kombinationen. Ein Athlet zählt pro Veranstaltung genau einmal.
     *
     * Bewusst DB-portabel gelöst (SQLite-Test-DB kennt kein
     * COUNT(DISTINCT a, b)): distinct Paare laden und in PHP zählen.
     */
    /**
     * Aggregiert die Starts je (Sportklasse, Athlet)-Paar. Diese Zwischenstufe
     * erlaubt beide Auswertungen aus einer Abfrage: die Sportklassenstatistik
     * (Gruppierung nach Klasse) und die Behinderungsgruppenstatistik, bei der
     * ein Athlet über mehrere Sportklassen hinweg nur einmal zählen darf.
     *
     * Sportklassen werden normalisiert (Großschreibung, ohne Randleerzeichen),
     * damit uneinheitlich importierte Schreibweisen zusammenfallen.
     *
     * Die Abfrage läuft bewusst über den Base-Query-Builder (toBase()): Sie
     * liefert Aggregatzeilen und keine echten Ergebnisse — ein hydriertes
     * Result-Model wäre hier irreführend (es besäße nur die drei
     * Aggregatfelder) und kostet unnötig Zeit. Vorhandene Scopes und
     * Bedingungen aus startsQuery() bleiben dabei erhalten.
     *
     * @return Collection<int, array{sport_class: string, athlete_id: int, starts: int}>
     */
    private function sportClassAthletePairs(ReportConfiguration $config): Collection
    {
        return $this->startsQuery($config)
            ->whereNotNull('results.sport_class')
            ->toBase()
            ->selectRaw('results.sport_class as sport_class, results.athlete_id as athlete_id, COUNT(*) as starts')
            ->groupBy('results.sport_class', 'results.athlete_id')
            ->get()
            ->map(fn (stdClass $row): array => [
                'sport_class' => strtoupper(trim((string) $row->sport_class)),
                'athlete_id' => (int) $row->athlete_id,
                'starts' => (int) $row->starts,
            ]);
    }

    /**
     * Aggregiert die Starts je (Athlet, Veranstaltung)-Paar. Grundlage für die
     * Alters- und Geschlechterauswertung: Die Veranstaltung wird mitgeführt,
     * weil die Altersgruppe vom Wettkampfjahr abhängt (31.12.-Stichtag).
     *
     * Wie sportClassAthletePairs() bewusst über den Base-Query-Builder, da es
     * sich um Aggregatzeilen und nicht um echte Ergebnisse handelt.
     *
     * @return Collection<int, array{athlete_id: int, meet_id: int, starts: int}>
     */
    private function athleteMeetPairs(ReportConfiguration $config): Collection
    {
        return $this->startsQuery($config)
            ->toBase()
            ->selectRaw('results.athlete_id as athlete_id, results.meet_id as meet_id, COUNT(*) as starts')
            ->groupBy('results.athlete_id', 'results.meet_id')
            ->get()
            ->map(fn (stdClass $row): array => [
                'athlete_id' => (int) $row->athlete_id,
                'meet_id' => (int) $row->meet_id,
                'starts' => (int) $row->starts,
            ]);
    }

    /**
     * Löst je (Athlet, Veranstaltung)-Paar Altersgruppe und Geschlecht auf und
     * bildet die gemeinsame Grundlage für byAgeGroup() und
     * byAgeGroupAndGender().
     *
     * Die Altersgruppen werden einmalig vorgeladen und an resolveAgeGroup()
     * übergeben (N+1-Vermeidung, analog zum vorhandenen sportClassMap-Muster);
     * zusätzlich wird je (Athlet, Wettkampfjahr) zwischengespeichert, da die
     * Zuordnung nur davon abhängt. Athleten ohne Geburtsdatum behalten
     * age_group = null und werden vom Aufrufer als Sammeleintrag ausgewiesen.
     *
     * @return Collection<int, array{athlete_id: int, starts: int, age_group: ?AgeGroup, gender: string}>
     */
    private function ageGenderRows(ReportConfiguration $config): Collection
    {
        $pairs = $this->athleteMeetPairs($config);

        if ($pairs->isEmpty()) {
            return collect();
        }

        $athletes = Athlete::query()
            ->whereIn('id', $pairs->pluck('athlete_id')->unique())
            ->get()
            ->keyBy('id');

        $meets = Meet::query()
            ->whereIn('id', $pairs->pluck('meet_id')->unique())
            ->get(['id', 'start_date'])
            ->keyBy('id');

        $ageGroups = $this->groupResolver->loadAgeGroups();
        $resolved = [];

        return $pairs
            ->map(function (array $row) use ($athletes, $meets, $ageGroups, &$resolved): ?array {
                $athlete = $athletes[$row['athlete_id']] ?? null;
                $meet = $meets[$row['meet_id']] ?? null;

                if ($athlete === null || $meet === null || $meet->start_date === null) {
                    return null;
                }

                $cacheKey = $row['athlete_id'].'|'.$meet->start_date->year;

                if (! array_key_exists($cacheKey, $resolved)) {
                    $resolved[$cacheKey] = $this->groupResolver->resolveAgeGroup(
                        $athlete,
                        $meet->start_date,
                        ageGroups: $ageGroups,
                    );
                }

                return [
                    'athlete_id' => $row['athlete_id'],
                    'starts' => $row['starts'],
                    'age_group' => $resolved[$cacheKey],
                    'gender' => (string) $athlete->gender,
                ];
            })
            ->filter()
            ->values();
    }

    private function countParticipations(Builder $base): int
    {
        return (clone $base)
            ->distinct()
            ->get(['athlete_id', 'meet_id'])
            ->count();
    }

    /**
     * Zerlegt die beteiligten Vereine (Verein zum Zeitpunkt des Ergebnisses,
     * results.club_id) in österreichische und ausländische. Nationalität über
     * die Vereinsnation (Code 'AUT'), konsistent mit
     * GroupResolverService::isForeignClub().
     *
     * @return array{austrian: int, foreign: int}
     */
    private function clubBreakdown(Builder $base): array
    {
        $clubIds = (clone $base)->distinct()->pluck('club_id');

        if ($clubIds->isEmpty()) {
            return ['austrian' => 0, 'foreign' => 0];
        }

        $clubs = Club::query()
            ->with('nation:id,code')
            ->whereIn('id', $clubIds)
            ->get(['id', 'nation_id']);

        $austrian = $clubs
            ->filter(fn (Club $club): bool => $club->nation?->code === 'AUT')
            ->count();

        return [
            'austrian' => $austrian,
            'foreign' => $clubs->count() - $austrian,
        ];
    }
}
