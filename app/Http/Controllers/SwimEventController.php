<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Meet;
use App\Models\ScoringGroup;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Rules\CodeList;
use App\Services\ScoringGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class SwimEventController extends Controller
{
    public function __construct(
        private readonly ScoringGroupService $scoring,
    ) {}

    public function create(Meet $meet): View
    {
        $strokeTypes = StrokeType::active()
            ->orderByRaw("CASE category WHEN 'standard' THEN 0 WHEN 'special' THEN 1 WHEN 'fin' THEN 2 ELSE 3 END")
            ->orderBy('name_de')
            ->get();

        // Vorbelegung Event-Nr.: nächste laufende Nummer nach der höchsten bereits
        // vergebenen — bleibt im Formular änderbar.
        $nextEventNumber = ($meet->swimEvents()->max('event_number') ?? 0) + 1;

        $levelOptions = self::levelOptions([]);

        return view('swim-events.form', compact('meet', 'strokeTypes', 'nextEventNumber', 'levelOptions'));
    }

    public function store(Request $request, Meet $meet): RedirectResponse
    {
        $data = $this->validateSwimEvent($request);
        $data['meet_id'] = $meet->id;
        $groups = $this->validateScoringGroups($request);

        $event = SwimEvent::create($data);
        $this->saveScoringGroups($event, $groups);

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Disziplin hinzugefügt.');
    }

    public function edit(SwimEvent $event): View
    {
        $strokeTypes = StrokeType::active()
            ->orderByRaw("CASE category WHEN 'standard' THEN 0 WHEN 'special' THEN 1 WHEN 'fin' THEN 2 ELSE 3 END")
            ->orderBy('name_de')
            ->get();

        return view('swim-events.form', [
            'meet' => $event->meet,
            'event' => $event,
            'strokeTypes' => $strokeTypes,
            'levelOptions' => self::levelOptions($event->scoringGroups()->pluck('levels')->all()),
            'copyTargets' => self::copyTargets($event),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function update(Request $request, SwimEvent $event): RedirectResponse
    {
        $data = $this->validateSwimEvent($request);
        $groups = $this->validateScoringGroups($request);

        // Rahmenbewerb: vorhandene Ergebnisse werden gelöscht, aber nur nach ausdrücklicher Bestätigung.
        $resultCount = $event->results()->count() + $event->relayResults()->count();
        if (! $data['is_scored'] && $event->is_scored && $resultCount > 0 && ! $request->boolean('confirm_delete_results')) {
            return back()->withInput()->withErrors([
                'confirm_delete_results' => "Dieser Bewerb hat $resultCount ".($resultCount === 1 ? 'Ergebnis' : 'Ergebnisse')
                    .'. Bestätigen, dass sie beim Kennzeichnen als nicht gewertet gelöscht werden.',
            ]);
        }

        DB::transaction(function () use ($event, $data) {
            if (! $data['is_scored']) {
                // Einzeln löschen, damit cascadeOnDelete (Zwischenzeiten, Staffelschwimmer) und Model-Events greifen.
                $event->results()->get()->each->delete();
                $event->relayResults()->get()->each->delete();
            }
            $event->update($data);
        });
        $this->saveScoringGroups($event, $groups);

        return redirect()
            ->route('meets.show', $event->meet)
            ->with('success', 'Disziplin aktualisiert.');
    }

    /**
     * Übernimmt die Wertungsgruppen dieses Bewerbs auf alle anderen Bewerbe der Veranstaltung derselben Art (Einzel
     * bzw. Staffel) und derselben Klassenkategorie (S: Freistil/Rücken/Delfin, SB: Brust, SM: Lagen). Deren bisherige
     * Gruppen werden ersetzt. Ist der Zielbewerb Damen bzw. Herren, bekommen die Gruppen dessen Geschlecht (siehe
     * groupsForTarget()).
     */
    public function copyScoringGroups(SwimEvent $event): RedirectResponse
    {
        $event->load(['scoringGroups', 'strokeType']);
        $category = $event->classCategory();

        $targets = SwimEvent::where('meet_id', $event->meet_id)
            ->whereKeyNot($event->id)
            ->with('strokeType')
            ->get()
            ->filter(fn (SwimEvent $other): bool => ($other->relay_count > 1) === ($event->relay_count > 1)
                && $other->classCategory() === $category);

        foreach ($targets as $target) {
            $target->scoringGroups()->delete();
            foreach (self::groupsForTarget($event, $target) as $row) {
                $target->scoringGroups()->create($row);
            }
            $target->syncSportClassesFromGroups();
            $this->scoring->syncPlaces($target);
        }

        return redirect()
            ->route('events.edit', $event)
            ->with('success', 'Wertungsgruppen auf '.$targets->count().' '.($targets->count() === 1 ? 'Bewerb' : 'Bewerbe').' übernommen.');
    }

    /**
     * Kopiert eine einzelne Wertungsgruppe (gespeicherter Stand) in die gewählten Bewerbe derselben Veranstaltung und
     * derselben Art (Einzel bzw. Staffel), auch über die Klassenkategorie hinweg. Sie wird hinten angefügt, vorhandene
     * Gruppen bleiben. Übersprungen werden Ziele, die schon eine Gruppe mit gleichem Namen und Geschlecht haben oder zu
     * deren Geschlecht die Gruppe nicht passt (groupRowForTarget()). IDs außerhalb der Auswahl werden ignoriert.
     *
     * @throws Throwable
     */
    public function copySingleScoringGroup(Request $request, ScoringGroup $scoringGroup): RedirectResponse
    {
        $validated = $request->validate([
            'target_event_ids' => 'nullable|array',
            'target_event_ids.*' => 'integer',
        ]);

        $source = $scoringGroup->swimEvent;
        $targets = SwimEvent::where('meet_id', $source->meet_id)
            ->whereKeyNot($source->id)
            ->whereIn('id', $validated['target_event_ids'] ?? [])
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get()
            ->filter(fn (SwimEvent $target): bool => $target->isRelay() === $source->isRelay());

        if ($targets->isEmpty()) {
            return redirect()->route('events.edit', $source)->with('error', 'Bitte mindestens einen Zielbewerb auswählen.');
        }

        $copied = 0;
        $duplicates = [];
        $otherGender = [];
        DB::transaction(function () use ($scoringGroup, $source, $targets, &$copied, &$duplicates, &$otherGender) {
            foreach ($targets as $target) {
                $row = self::groupRowForTarget($scoringGroup, $source, $target);
                if ($row === null) {
                    $otherGender[] = self::shortEventLabel($target);

                    continue;
                }

                $existing = $target->scoringGroups()->get();
                $name = mb_strtolower($row['name']);
                if ($existing->contains(fn (ScoringGroup $g): bool => mb_strtolower($g->name) === $name && $g->gender === $row['gender'])) {
                    $duplicates[] = self::shortEventLabel($target);

                    continue;
                }

                $target->scoringGroups()->create([...$row, 'sort_order' => (int) $existing->max('sort_order') + 1]);
                $target->syncSportClassesFromGroups();
                $this->scoring->syncPlaces($target);
                $copied++;
            }
        });

        $skipped = array_filter([
            $duplicates !== [] ? implode(', ', $duplicates).' (Gruppe bereits vorhanden)' : null,
            $otherGender !== [] ? implode(', ', $otherGender).' (anderes Geschlecht)' : null,
        ]);
        $skippedText = $skipped !== [] ? ' Übersprungen: '.implode('; ', $skipped).'.' : '';

        return redirect()->route('events.edit', $source)->with(
            $copied > 0 ? 'success' : 'error',
            $copied > 0
                ? 'Wertungsgruppe "'.$scoringGroup->label().'" in '.$copied.' '.($copied === 1 ? 'Bewerb' : 'Bewerbe').' kopiert.'.$skippedText
                : 'Wertungsgruppe "'.$scoringGroup->label().'" wurde nicht kopiert.'.$skippedText,
        );
    }

    public function destroy(SwimEvent $event): RedirectResponse
    {
        $meet = $event->meet;

        // Staffelmeldungen und -ergebnisse hängen per cascadeOnDelete am Bewerb — ohne diese Prüfung würden sie still
        // mitgelöscht.
        if ($event->entries()->exists() || $event->results()->exists()
            || $event->relayEntries()->exists() || $event->relayResults()->exists()) {
            return back()->with('error', 'Disziplin kann nicht gelöscht werden — es gibt bereits Meldungen oder Ergebnisse.');
        }

        $event->delete();

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Disziplin gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Wertungsgruppen aus dem Formular (vor dem Speichern des Bewerbs geprüft).
     *
     * @return list<array<string, mixed>>
     */
    private function validateScoringGroups(Request $request): array
    {
        return array_values($request->validate([
            'scoring_groups' => 'nullable|array',
            'scoring_groups.*.name' => 'required|string|max:100',
            'scoring_groups.*.gender' => 'required|in:M,F,X,A',
            'scoring_groups.*.sport_classes' => 'nullable|string|max:100',
            // Bis 999: Bei Staffeln ist das Alter die Summe der Alter (Masters z. B. 320-359).
            'scoring_groups.*.age_min' => 'nullable|integer|min:0|max:999',
            'scoring_groups.*.age_max' => 'nullable|integer|min:0|max:999',
            'scoring_groups.*.regional_associations' => ['nullable', 'string', 'max:100', new CodeList(array_keys(Club::REGIONAL_ASSOCIATIONS))],
            'scoring_groups.*.regional_mode' => 'nullable|required_with:scoring_groups.*.regional_associations|in:'
                .implode(',', array_keys(ScoringGroup::REGION_MODES)),
            'scoring_groups.*.levels' => 'nullable|string|max:100',
            'scoring_groups.*.title' => 'nullable|in:'.implode(',', array_keys(ScoringGroup::TITLES)),
            'scoring_groups.*.lenex_agegroup_id' => 'nullable|string|max:50',
        ])['scoring_groups'] ?? []);
    }

    /**
     * Ersetzt die Wertungsgruppen des Bewerbs und gleicht sport_classes ab.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function saveScoringGroups(SwimEvent $event, array $rows): void
    {
        $event->scoringGroups()->delete();
        foreach ($rows as $index => $row) {
            $classes = ScoringGroup::parseClassNumbers($row['sport_classes'] ?? '');
            $associations = ScoringGroup::parseCodes($row['regional_associations'] ?? '');
            // Eine Staffel hat kein Level; die Einschränkung bliebe sonst ohne Ergebnisse.
            $levels = $event->relay_count > 1 ? [] : ScoringGroup::parseLevels($row['levels'] ?? '');
            $event->scoringGroups()->create([
                'name' => $row['name'],
                'gender' => $row['gender'],
                'sport_classes' => $classes !== [] ? implode(',', $classes) : null,
                'age_min' => $row['age_min'] ?? null,
                'age_max' => $row['age_max'] ?? null,
                'regional_associations' => $associations !== [] ? implode(',', $associations) : null,
                'regional_mode' => $associations !== [] ? $row['regional_mode'] : null,
                'levels' => $levels !== [] ? implode(',', $levels) : null,
                'title' => $row['title'] ?? null,
                'sort_order' => $index + 1,
                'lenex_agegroup_id' => $row['lenex_agegroup_id'] ?? null,
            ]);
        }

        $event->syncSportClassesFromGroups();
        $this->scoring->syncPlaces($event);
    }

    /**
     * Level-Auswahl des Formulars: vergebene Level plus die der Gruppen und eines abgeschickten Formulars.
     *
     * @param  list<?string>  $groupLevels  levels-Spalten der Gruppen
     * @return list<string>
     */
    private static function levelOptions(array $groupLevels): array
    {
        return ScoringGroup::levelOptions([...$groupLevels, ...collect(old('scoring_groups', []))->pluck('levels')->all()]);
    }

    /**
     * Zu übernehmende Gruppen für einen Zielbewerb. Ist das Ziel Damen bzw. Herren, bekommt jede Gruppe dessen
     * Geschlecht; ist die Quelle offen/Mixed, entfallen dabei Gruppen des anderen Geschlechts (sonst entstünden
     * doppelte Gruppen). Ein offenes/Mixed-Ziel übernimmt die Gruppen unverändert.
     *
     * @return list<array<string, mixed>>
     */
    private static function groupsForTarget(SwimEvent $source, SwimEvent $target): array
    {
        $rows = [];
        foreach ($source->scoringGroups as $group) {
            $row = self::groupRowForTarget($group, $source, $target);
            if ($row !== null) {
                $row['sort_order'] = count($rows) + 1;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Felder einer Gruppe für einen Zielbewerb (ohne LENEX-ID und Reihenfolge), Geschlechtsregel siehe
     * groupsForTarget(); null = die Gruppe passt nicht zum Geschlecht des Ziels.
     *
     * @return array<string, mixed>|null
     */
    private static function groupRowForTarget(ScoringGroup $group, SwimEvent $source, SwimEvent $target): ?array
    {
        $row = $group->only([
            'name', 'gender', 'sport_classes', 'age_min', 'age_max', 'regional_associations', 'regional_mode', 'levels',
            'title',
        ]);

        if (in_array($target->gender, ['M', 'F'], true)) {
            $sourceGendered = in_array($source->gender, ['M', 'F'], true);
            if (! $sourceGendered && ! in_array($group->gender, [$target->gender, 'A'], true)) {
                return null;
            }
            $row['gender'] = $target->gender;
        }

        return $row;
    }

    /**
     * Auswahl des Dialogs "Wertungsgruppe kopieren": alle anderen Bewerbe der Veranstaltung gleicher Art.
     *
     * @return list<array{id: int, label: string, category: string, same_category: bool, without_groups: bool}>
     */
    private static function copyTargets(SwimEvent $event): array
    {
        $category = $event->classCategory();

        return SwimEvent::where('meet_id', $event->meet_id)
            ->whereKeyNot($event->id)
            ->with('strokeType')
            ->withCount('scoringGroups')
            ->orderBy('session_number')
            ->orderBy('event_number')
            ->get()
            ->filter(fn (SwimEvent $target): bool => $target->isRelay() === $event->isRelay())
            ->map(fn (SwimEvent $target): array => [
                'id' => $target->id,
                'label' => $target->selection_label,
                'category' => $target->classCategory(),
                'same_category' => $target->classCategory() === $category,
                'without_groups' => $target->scoring_groups_count === 0,
            ])
            ->values()
            ->all();
    }

    /** Kurzbezeichnung eines Bewerbs für Meldungen, z. B. "Nr. 7". */
    private static function shortEventLabel(SwimEvent $event): string
    {
        return $event->event_number ? 'Nr. '.$event->event_number : trim($event->display_name);
    }

    private function validateSwimEvent(Request $request): array
    {
        $data = $request->validate([
            'stroke_type_id' => 'required|exists:stroke_types,id',
            'event_number' => 'nullable|integer|min:1',
            'session_number' => 'required|integer|min:1',
            'gender' => 'required|in:M,F,A,X',
            'round' => 'required|in:TIM,FHT,FIN,SEM,QUA,PRE,SOP,SOS,SOQ,TIMETRIAL',
            'distance' => 'required|integer|min:1',
            'relay_count' => 'required|integer|min:1',
            'technique' => 'nullable|in:DIVE,GLIDE,KICK,PULL,START,TURN',
            'style_code' => 'nullable|string|max:20',
            'style_name' => 'nullable|string|max:255',
            'sport_classes' => 'nullable|string|max:100',
            'timing' => 'nullable|in:AUTOMATIC,SEMIAUTOMATIC,MANUAL3,MANUAL2,MANUAL1',
            'is_scored' => 'nullable|boolean',
        ]);

        // Fehlt das Feld (z. B. ältere Formulare), gilt der Bewerb als gewertet.
        $data['is_scored'] = $request->boolean('is_scored', true);

        return $data;
    }
}
