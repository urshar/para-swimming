<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\ImportReviewItem;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Athleten zusammenführen (Dubletten, docs/open-points.md): Ein Athlet bleibt ($keep), der andere ($drop) wird
 * aufgelöst und danach endgültig gelöscht. Alles, was am aufgelösten Athleten hängt, wandert zum behaltenen.
 *
 * - Stammdaten: Feld für Feld gewählt ('keep' / 'drop', Notizen auch 'both'). Vorschlag ist der Wert des behaltenen
 *   Athleten, bei leerem Wert der des anderen. club_id und level folgen aus Vereins- bzw. Level-History.
 * - Blockierend: beide im selben Bewerb (Ergebnis oder Meldung) oder in derselben Staffel (Ergebnis, Meldung,
 *   Rekord-Staffelteam) — das muss vorher von Hand bereinigt werden.
 * - Sportklassen: Für jede Kategorie mit abweichenden Werten muss gewählt werden. Ausnahme-Codes werden vereinigt.
 * - Histories (Verein, Level, Kader, Klassifikation): alles übernehmen, exakte Duplikate entfallen. Mehrere offene
 *   Vereinseinträge werden geschlossen, bis auf den jüngsten.
 * - Cup-Wertungen und Qualifikationen: umhängen, kollidierende Zeilen des aufgelösten Athleten entfallen. Auf Wunsch
 *   ($recalculate) werden die betroffenen Cups und Richtzeitenlisten in derselben Transaktion neu berechnet
 *   (AthleteMergeRecalculationService). Umgehängte Ergebnisse und Kaderzugehörigkeiten bekommen ein neues updated_at,
 *   damit CupStalenessService die Wertungen sonst als veraltet meldet.
 * - Rekorde: nur umhängen, die Kette hängt nicht am Athleten.
 */
final readonly class AthleteMergeService
{
    public const string KEEP = 'keep';

    public const string DROP = 'drop';

    public const string BOTH = 'both';

    /** Tabellen mit athlete_id → Bezeichnung für Vorschau und Protokoll. */
    public const array TABLES = [
        'results' => 'Ergebnisse',
        'relay_result_members' => 'Staffelergebnisse',
        'entries' => 'Meldungen',
        'relay_entry_members' => 'Staffelmeldungen',
        'swim_records' => 'Rekorde',
        'relay_team_members' => 'Rekord-Staffelteams',
        'athlete_sport_classes' => 'Sportklassen',
        'athlete_exceptions' => 'Ausnahme-Codes',
        'athlete_classifications' => 'Klassifikationen',
        'athlete_club_history' => 'Vereins-History',
        'athlete_level_history' => 'Level-History',
        'athlete_kader_memberships' => 'Kaderzugehörigkeiten',
        'athlete_performance_notes' => 'Leistungsnotizen',
        'import_review_items' => 'Prüflisten-Einträge',
        'cup_top_group_classifications' => 'Cup-Topgruppe',
        'cup_daily_results' => 'Cup-Tageswertungen',
        'cup_overall_results' => 'Cup-Gesamtwertungen',
        'qualifications' => 'Qualifikationen',
    ];

    /** Stammdaten, die Feld für Feld gewählt werden (ohne club_id und level, die aus der History folgen). */
    public const array FIELDS = [
        'first_name', 'last_name', 'name_prefix', 'birth_date', 'gender', 'nation_id', 'license', 'license_ipc', 'swrid',
        'status', 'disability_group', 'disability_subgroup', 'is_active', 'notes', 'email', 'phone', 'address_street',
        'address_city', 'address_zip', 'address_country', 'last_medical_check_at', 'next_medical_check_at',
    ];

    /** Spalten, in denen sich zwei Sportklassen derselben Kategorie unterscheiden können. */
    private const array SPORT_CLASS_COLUMNS = ['sport_class', 'class_number', 'classification_scope',
        'classification_status', 'frd_year'];

    public function __construct(
        private AthleteMergeRecalculationService $recalculationService,
    ) {}

    /**
     * Gegenüberstellung beider Athleten für die Vorschau.
     *
     * @return array{
     *     fields: array<string, array{keep: mixed, drop: mixed, suggestion: string}>,
     *     counts: array<string, array{keep: int, drop: int}>,
     *     blocking: list<string>,
     *     sport_classes: array<string, array{keep: string, drop: string}>,
     *     hints: list<string>,
     *     cup_ids: list<int>,
     *     qualifying_time_list_ids: list<int>,
     * }
     */
    public function analyze(Athlete $keep, Athlete $drop): array
    {
        $this->assertDistinct($keep, $drop);
        $ids = [$keep->id, $drop->id];

        return [
            'fields' => $this->fieldDifferences($keep, $drop),
            'counts' => collect(array_keys(self::TABLES))->mapWithKeys(fn (string $table) => [$table => [
                'keep' => $this->count($table, $keep->id),
                'drop' => $this->count($table, $drop->id),
            ]])->all(),
            'blocking' => $this->blockingCollisions($keep, $drop),
            'sport_classes' => $this->sportClassConflicts($keep, $drop),
            'hints' => [...$this->overlappingClubPeriods($keep, $drop), ...$this->duplicateRecords($keep, $drop)],
            'cup_ids' => DB::table('cup_top_group_classifications')->whereIn('athlete_id', $ids)->pluck('cup_id')
                ->merge(DB::table('cup_daily_results')->whereIn('athlete_id', $ids)->pluck('cup_id'))
                ->merge(DB::table('cup_overall_results')->whereIn('athlete_id', $ids)->pluck('cup_id'))
                ->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
            'qualifying_time_list_ids' => DB::table('qualifications')->whereIn('athlete_id', $ids)
                ->pluck('qualifying_time_list_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all(),
        ];
    }

    /**
     * Führt $drop in $keep zusammen (eine Transaktion) und löscht $drop endgültig.
     *
     * @param  array<string, string>  $fieldChoices  Feld → 'keep' / 'drop' (notes auch 'both'); fehlt ein abweichendes
     *                                               Feld, gilt der Vorschlag aus analyze()
     * @param  array<string, string>  $sportClassChoices  Kategorie → 'keep' / 'drop', Pflicht für jede abweichende
     *                                                    Kategorie
     * @param  bool  $recalculate  betroffene Cups und Richtzeitenlisten neu berechnen
     * @return array{
     *     moved: array<string, int>,
     *     recalculation: ?array{
     *         cups: list<array{id: int, name: string, stages: list<string>}>,
     *         qualifying_time_lists: list<array{id: int, year: int, qualified: int}>,
     *         errors: list<string>,
     *     },
     * } übernommene Datensätze je Tabelle (Stand vor dem Zusammenführen) und Bericht der Neuberechnung
     *
     * @throws Throwable
     */
    public function merge(Athlete $keep, Athlete $drop, array $fieldChoices, array $sportClassChoices,
        bool $recalculate): array
    {
        $analysis = $this->analyze($keep, $drop);

        if ($analysis['blocking'] !== []) {
            throw new RuntimeException('Zusammenführen nicht möglich: '.implode(' ', $analysis['blocking']));
        }

        $attributes = $this->chosenAttributes($analysis['fields'], $fieldChoices);

        foreach (array_keys($analysis['sport_classes']) as $category) {
            if (! in_array($sportClassChoices[$category] ?? null, [self::KEEP, self::DROP], true)) {
                throw new InvalidArgumentException("Sportklasse $category: Es wurde keine Auswahl getroffen.");
            }
        }

        $moved = array_filter(array_map(fn (array $counts) => $counts['drop'], $analysis['counts']));

        $recalculation = DB::transaction(function () use ($keep, $drop, $attributes, $sportClassChoices, $recalculate,
            $analysis) {
            $now = now();
            $movedKaderIds = DB::table('athlete_kader_memberships')->where('athlete_id', $drop->id)->pluck('id');

            $this->mergeSportClasses($keep, $drop, $sportClassChoices);
            $this->moveUnlessPresent('athlete_exceptions', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['exception_code_id', 'category']));
            $this->moveUnlessPresent('athlete_classifications', $keep, $drop,
                fn (object $row) => $this->classificationKey($row));
            $this->moveUnlessPresent('athlete_club_history', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['club_id', 'joined_at', 'left_at']));
            $this->moveUnlessPresent('athlete_level_history', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['level', 'changed_at']));
            $this->moveUnlessPresent('athlete_kader_memberships', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['kader_type_id', 'valid_from', 'valid_until']));
            $this->moveUnlessPresent('cup_top_group_classifications', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['cup_id']));
            $this->moveUnlessPresent('cup_daily_results', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['cup_id', 'meet_id']));
            $this->moveUnlessPresent('cup_overall_results', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['cup_id', 'gender', 'sport_class_group_id']));
            $this->moveUnlessPresent('qualifications', $keep, $drop,
                fn (object $row) => $this->columnsKey($row, ['qualifying_time_list_id', 'qualifying_time_id']));

            DB::table('athlete_kader_memberships')->whereIn('id', $movedKaderIds)->where('athlete_id', $keep->id)
                ->update(['updated_at' => $now]);

            DB::table('results')->where('athlete_id', $drop->id)->update(['athlete_id' => $keep->id, 'updated_at' => $now]);
            foreach (['relay_result_members', 'entries', 'relay_entry_members', 'swim_records', 'relay_team_members',
                'athlete_performance_notes', 'import_review_items'] as $table) {
                DB::table($table)->where('athlete_id', $drop->id)->update(['athlete_id' => $keep->id]);
            }
            $this->rewriteReviewDetails($keep, $drop);

            $clubId = $this->normalizeClubHistory($keep);
            $level = DB::table('athlete_level_history')->where('athlete_id', $keep->id)
                ->orderByDesc('changed_at')->orderByDesc('id')->value('level');

            $keep->fill([
                ...$attributes,
                'club_id' => $clubId ?? $keep->club_id ?? $drop->club_id,
                'level' => $level ?? $keep->level ?? $drop->level,
            ])->save();

            $remaining = array_filter(array_keys(self::TABLES), fn (string $table) => $this->count($table, $drop->id) > 0);
            if ($remaining !== []) {
                throw new RuntimeException('Nach dem Zusammenführen verweisen noch Daten auf den aufgelösten Athleten: '
                    .implode(', ', $remaining));
            }

            $drop->forceDelete();

            return $recalculate
                ? $this->recalculationService->recalculate($analysis['cup_ids'], $analysis['qualifying_time_list_ids'])
                : null;
        });

        Log::info('Athleten zusammengeführt', [
            'user_id' => Auth::id(),
            'keep' => ['id' => $keep->id, 'name' => $keep->display_name],
            'drop' => ['id' => $drop->id, 'name' => $drop->display_name],
            'moved' => $moved,
            'recalculation' => $recalculation,
        ]);

        return ['moved' => $moved, 'recalculation' => $recalculation];
    }

    private function assertDistinct(Athlete $keep, Athlete $drop): void
    {
        if ($keep->id === $drop->id) {
            throw new InvalidArgumentException('Ein Athlet kann nicht mit sich selbst zusammengeführt werden.');
        }
    }

    private function count(string $table, int $athleteId): int
    {
        return DB::table($table)->where('athlete_id', $athleteId)->count();
    }

    // ── Stammdaten ────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{keep: mixed, drop: mixed, suggestion: string}>
     */
    private function fieldDifferences(Athlete $keep, Athlete $drop): array
    {
        $fields = [];
        foreach (self::FIELDS as $field) {
            $keepValue = $this->fieldValue($keep, $field);
            $dropValue = $this->fieldValue($drop, $field);
            if ($keepValue === $dropValue) {
                continue;
            }

            $fields[$field] = [
                'keep' => $keepValue,
                'drop' => $dropValue,
                'suggestion' => match (true) {
                    $keepValue === null => self::DROP,
                    $field === 'notes' && $dropValue !== null => self::BOTH,
                    default => self::KEEP,
                },
            ];
        }

        return $fields;
    }

    private function fieldValue(Athlete $athlete, string $field): mixed
    {
        $value = $athlete->getAttribute($field);

        if ($value instanceof CarbonInterface) {
            return $value->toDateString();
        }

        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * @param  array<string, array{keep: mixed, drop: mixed, suggestion: string}>  $fields
     * @param  array<string, string>  $choices
     * @return array<string, mixed>
     */
    private function chosenAttributes(array $fields, array $choices): array
    {
        $attributes = [];
        foreach ($fields as $field => $values) {
            $choice = $choices[$field] ?? $values['suggestion'];
            $allowed = $field === 'notes' ? [self::KEEP, self::DROP, self::BOTH] : [self::KEEP, self::DROP];
            if (! in_array($choice, $allowed, true)) {
                throw new InvalidArgumentException("Feld $field: ungültige Auswahl \"$choice\".");
            }

            $attributes[$field] = match ($choice) {
                self::KEEP => $values['keep'],
                self::DROP => $values['drop'],
                default => implode("\n\n", array_filter([$values['keep'], $values['drop']])),
            };
        }

        return $attributes;
    }

    // ── Kollisionen und Hinweise ──────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function blockingCollisions(Athlete $keep, Athlete $drop): array
    {
        $messages = [];

        foreach (['results' => 'ein Ergebnis', 'entries' => 'eine Meldung'] as $table => $label) {
            $collisions = DB::table("$table as k")
                ->join("$table as d", fn ($join) => $join->on('k.meet_id', '=', 'd.meet_id')
                    ->on('k.swim_event_id', '=', 'd.swim_event_id'))
                ->join('meets', 'meets.id', '=', 'k.meet_id')
                ->join('swim_events', 'swim_events.id', '=', 'k.swim_event_id')
                ->where('k.athlete_id', $keep->id)
                ->where('d.athlete_id', $drop->id)
                ->get(['meets.name', 'swim_events.event_number']);

            foreach ($collisions as $collision) {
                $messages[] = "Beide haben $label in \"$collision->name\", Bewerb $collision->event_number.";
            }
        }

        $relays = [
            'relay_result_members' => ['relay_result_id', 'Staffelergebnis'],
            'relay_entry_members' => ['relay_entry_id', 'Staffelmeldung'],
            'relay_team_members' => ['swim_record_id', 'Rekord-Staffelteam'],
        ];
        foreach ($relays as $table => [$column, $label]) {
            $shared = DB::table($table)->where('athlete_id', $keep->id)->pluck($column)
                ->intersect(DB::table($table)->where('athlete_id', $drop->id)->pluck($column));

            foreach ($shared->unique() as $id) {
                $messages[] = "Beide sind Mitglied derselben Staffel ($label #$id).";
            }
        }

        return $messages;
    }

    /**
     * Kategorien, in denen beide Athleten eine abweichende Sportklasse haben.
     *
     * @return array<string, array{keep: string, drop: string}>
     */
    private function sportClassConflicts(Athlete $keep, Athlete $drop): array
    {
        $keepClasses = DB::table('athlete_sport_classes')->where('athlete_id', $keep->id)->get()->keyBy('category');

        $conflicts = [];
        foreach (DB::table('athlete_sport_classes')->where('athlete_id', $drop->id)->orderBy('category')->get() as $class) {
            $existing = $keepClasses->get($class->category);
            if ($existing === null
                || $this->columnsKey($existing, self::SPORT_CLASS_COLUMNS) === $this->columnsKey($class, self::SPORT_CLASS_COLUMNS)) {
                continue;
            }

            $conflicts[$class->category] = [
                'keep' => $this->sportClassLabel($existing),
                'drop' => $this->sportClassLabel($class),
            ];
        }

        return $conflicts;
    }

    private function sportClassLabel(object $class): string
    {
        $details = array_filter([
            $class->classification_scope,
            $class->classification_status,
            $class->frd_year !== null ? 'FRD '.$class->frd_year : null,
        ]);

        return $details === [] ? $class->sport_class : $class->sport_class.' ('.implode(', ', $details).')';
    }

    /**
     * Vereinszeiträume beider Athleten, die sich überschneiden (bei verschiedenen Vereinen).
     *
     * @return list<string>
     */
    private function overlappingClubPeriods(Athlete $keep, Athlete $drop): array
    {
        $entries = fn (Athlete $athlete) => DB::table('athlete_club_history')
            ->join('clubs', 'clubs.id', '=', 'athlete_club_history.club_id')
            ->where('athlete_id', $athlete->id)
            ->get(['athlete_club_history.club_id', 'joined_at', 'left_at', 'clubs.name']);

        $messages = [];
        $keepEntries = $entries($keep);
        foreach ($entries($drop) as $dropEntry) {
            foreach ($keepEntries as $keepEntry) {
                if ($keepEntry->club_id === $dropEntry->club_id
                    || $keepEntry->joined_at > ($dropEntry->left_at ?? '9999-12-31')
                    || $dropEntry->joined_at > ($keepEntry->left_at ?? '9999-12-31')) {
                    continue;
                }

                $messages[] = 'Vereinszeiträume überschneiden sich: '.$this->periodLabel($keepEntry).' und '
                    .$this->periodLabel($dropEntry).'.';
            }
        }

        return $messages;
    }

    private function periodLabel(object $entry): string
    {
        return $entry->name.' ('.substr($entry->joined_at, 0, 10).' – '
            .($entry->left_at !== null ? substr($entry->left_at, 0, 10) : 'heute').')';
    }

    /**
     * Rekorde, die nach dem Zusammenführen doppelt wären (gleiche Kategorie, gleiche Zeit).
     *
     * @return list<string>
     */
    private function duplicateRecords(Athlete $keep, Athlete $drop): array
    {
        $columns = ['record_type', 'stroke_type_id', 'sport_class', 'gender', 'course', 'distance', 'relay_count',
            'swim_time'];
        $keepRecords = DB::table('swim_records')->where('athlete_id', $keep->id)->get()
            ->keyBy(fn (object $record) => $this->columnsKey($record, $columns));

        $messages = [];
        foreach (DB::table('swim_records')->where('athlete_id', $drop->id)->get() as $record) {
            $twin = $keepRecords->get($this->columnsKey($record, $columns));
            if ($twin !== null) {
                $messages[] = "Doppelter Rekord nach dem Zusammenführen: #$twin->id und #$record->id "
                    ."($record->record_type, $record->distance m, $record->sport_class, $record->course).";
            }
        }

        return $messages;
    }

    // ── Zusammenführen ────────────────────────────────────────────────────────

    /**
     * @param  array<string, string>  $choices
     */
    private function mergeSportClasses(Athlete $keep, Athlete $drop, array $choices): void
    {
        $keepClasses = DB::table('athlete_sport_classes')->where('athlete_id', $keep->id)->get()->keyBy('category');

        foreach (DB::table('athlete_sport_classes')->where('athlete_id', $drop->id)->get() as $class) {
            $existing = $keepClasses->get($class->category);

            // Gleiche Klasse oder Auswahl "behalten": Die Klasse des aufgelösten Athleten entfällt.
            if ($existing !== null && ($choices[$class->category] ?? self::KEEP) === self::KEEP) {
                DB::table('athlete_sport_classes')->where('id', $class->id)->delete();

                continue;
            }

            if ($existing !== null) {
                DB::table('athlete_sport_classes')->where('id', $existing->id)->delete();
            }
            DB::table('athlete_sport_classes')->where('id', $class->id)->update(['athlete_id' => $keep->id]);
        }
    }

    /**
     * Hängt die Zeilen des aufgelösten Athleten um; hat der behaltene schon eine mit demselben Schlüssel, entfällt sie.
     *
     * @param  Closure(object): string  $key
     */
    private function moveUnlessPresent(string $table, Athlete $keep, Athlete $drop, Closure $key): void
    {
        $present = DB::table($table)->where('athlete_id', $keep->id)->get()->map($key)->flip();

        foreach (DB::table($table)->where('athlete_id', $drop->id)->get() as $row) {
            if ($present->has($key($row))) {
                DB::table($table)->where('id', $row->id)->delete();
            } else {
                DB::table($table)->where('id', $row->id)->update(['athlete_id' => $keep->id]);
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function columnsKey(object $row, array $columns): string
    {
        return json_encode(array_map(fn (string $column) => $row->$column, $columns));
    }

    /** Alle Inhaltsspalten einer Klassifikation samt ihrer Ausnahme-Codes. */
    private function classificationKey(object $row): string
    {
        $exceptions = DB::table('athlete_classification_exceptions')
            ->where('athlete_classification_id', $row->id)
            ->orderBy('exception_code_id')
            ->orderBy('category')
            ->get(['exception_code_id', 'category'])
            ->map(fn (object $exception) => $exception->exception_code_id.'/'.$exception->category)
            ->all();

        $columns = array_diff_key((array) $row, array_flip(['id', 'athlete_id', 'created_at', 'updated_at']));

        return json_encode([$columns, $exceptions]);
    }

    /** Offene Prüflisten-Einträge der Rekordprüfung führen Athleten-IDs auch im JSON (details.list). */
    private function rewriteReviewDetails(Athlete $keep, Athlete $drop): void
    {
        foreach (ImportReviewItem::whereNotNull('details')->get() as $item) {
            $details = $item->details;
            if (! isset($details['list'])) {
                continue;
            }

            $changed = false;
            if ((int) ($details['list']['athlete_id'] ?? 0) === $drop->id) {
                $details['list']['athlete_id'] = $keep->id;
                $changed = true;
            }
            foreach ($details['list']['relay_members'] ?? [] as $index => $member) {
                if ((int) ($member['db_id'] ?? 0) === $drop->id) {
                    $details['list']['relay_members'][$index]['db_id'] = $keep->id;
                    $changed = true;
                }
            }

            if ($changed) {
                $item->update(['details' => $details]);
            }
        }
    }

    /**
     * Genau ein offener Vereinseintrag bleibt aktiv (der jüngste); ältere offene werden zum Tag vor dessen Beginn
     * geschlossen. Liefert den Verein des aktiven Eintrags.
     */
    private function normalizeClubHistory(Athlete $keep): ?int
    {
        $open = DB::table('athlete_club_history')
            ->where('athlete_id', $keep->id)
            ->whereNull('left_at')
            ->orderByDesc('joined_at')
            ->orderByDesc('id')
            ->get();

        $current = $open->shift();
        if ($current === null) {
            return null;
        }

        foreach ($open as $older) {
            $leftAt = Carbon::parse($current->joined_at)->subDay()->max(Carbon::parse($older->joined_at));
            DB::table('athlete_club_history')->where('id', $older->id)->update(['left_at' => $leftAt->toDateString()]);
        }

        DB::table('athlete_club_history')->where('athlete_id', $keep->id)->update(['is_active' => false]);
        DB::table('athlete_club_history')->where('id', $current->id)->update(['is_active' => true]);

        return (int) $current->club_id;
    }
}
