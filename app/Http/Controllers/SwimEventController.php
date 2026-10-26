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
     * Gruppen werden ersetzt.
     */
    public function copyScoringGroups(SwimEvent $event): RedirectResponse
    {
        $event->load(['scoringGroups', 'strokeType']);
        $category = self::classCategory($event);

        $targets = SwimEvent::where('meet_id', $event->meet_id)
            ->whereKeyNot($event->id)
            ->with('strokeType')
            ->get()
            ->filter(fn (SwimEvent $other): bool => ($other->relay_count > 1) === ($event->relay_count > 1)
                && self::classCategory($other) === $category);

        foreach ($targets as $target) {
            $target->scoringGroups()->delete();
            foreach ($event->scoringGroups as $group) {
                $target->scoringGroups()->create($group->only([
                    'name', 'gender', 'sport_classes', 'age_min', 'age_max', 'regional_associations', 'regional_mode', 'levels',
                    'title', 'sort_order',
                ]));
            }
            $target->syncSportClassesFromGroups();
            $this->scoring->syncPlaces($target);
        }

        return redirect()
            ->route('events.edit', $event)
            ->with('success', 'Wertungsgruppen auf '.$targets->count().' '.($targets->count() === 1 ? 'Bewerb' : 'Bewerbe').' übernommen.');
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
            'scoring_groups.*.age_min' => 'nullable|integer|min:0|max:99',
            'scoring_groups.*.age_max' => 'nullable|integer|min:0|max:99',
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

    /** Klassenkategorie der Lage: SB (Brust), SM (Lagen), sonst S. */
    private static function classCategory(SwimEvent $event): string
    {
        return match ($event->strokeType?->lenex_code) {
            'BREAST' => 'SB',
            'MEDLEY', 'IMRELAY' => 'SM',
            default => 'S',
        };
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
