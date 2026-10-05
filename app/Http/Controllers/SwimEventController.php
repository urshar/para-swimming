<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Models\ScoringGroup;
use App\Models\StrokeType;
use App\Models\SwimEvent;
use App\Services\ScoringGroupService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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

        return view('swim-events.form', compact('meet', 'strokeTypes', 'nextEventNumber'));
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
        ]);
    }

    public function update(Request $request, SwimEvent $event): RedirectResponse
    {
        $data = $this->validateSwimEvent($request);
        $groups = $this->validateScoringGroups($request);

        $event->update($data);
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
                $target->scoringGroups()->create($group->only(['name', 'gender', 'sport_classes', 'age_min', 'age_max', 'title', 'sort_order']));
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

        if ($event->entries()->exists() || $event->results()->exists()) {
            return back()->withErrors([
                'event' => 'Disziplin kann nicht gelöscht werden — es gibt bereits Meldungen oder Ergebnisse.',
            ]);
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
            $event->scoringGroups()->create([
                'name' => $row['name'],
                'gender' => $row['gender'],
                'sport_classes' => $classes !== [] ? implode(',', $classes) : null,
                'age_min' => $row['age_min'] ?? null,
                'age_max' => $row['age_max'] ?? null,
                'title' => $row['title'] ?? null,
                'sort_order' => $index + 1,
                'lenex_agegroup_id' => $row['lenex_agegroup_id'] ?? null,
            ]);
        }

        $event->syncSportClassesFromGroups();
        $this->scoring->syncPlaces($event);
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
        return $request->validate([
            'stroke_type_id' => 'required|exists:stroke_types,id',
            'event_number' => 'nullable|integer|min:1',
            'session_number' => 'required|integer|min:1',
            'gender' => 'required|in:M,F,A,X',
            'round' => 'required|in:TIM,FHT,FIN,SEM,QUA,PRE,SOP,SOS,SOQ,TIMETRIAL',
            'distance' => 'required|integer|min:1',
            'relay_count' => 'required|integer|min:1',
            'technique' => 'nullable|in:DIVE,GLIDE,KICK,PULL,START,TURN',
            'style_code' => 'nullable|string|max:6',
            'style_name' => 'nullable|string|max:255',
            'sport_classes' => 'nullable|string|max:100',
            'timing' => 'nullable|in:AUTOMATIC,SEMIAUTOMATIC,MANUAL3,MANUAL2,MANUAL1',
        ]);
    }
}
