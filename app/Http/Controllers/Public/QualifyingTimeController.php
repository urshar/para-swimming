<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Qualification;
use App\Models\QualifyingTime;
use App\Models\QualifyingTimeList;
use App\Models\SportClassGroup;
use App\Models\SportClassGroupMember;
use App\Support\DisabilityGroupGrouper;
use App\Support\PublicQualificationFilter;
use App\Support\SportClassSorter;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Public\QualifyingTimeController
 *
 * Öffentliche Startberechtigung (Spec public-frontend §5, Phase 7) — zeigt die ermittelten
 * Qualifikationen (Snapshot, siehe Qualification-Model) der aktuell aktiven Richtzeitenliste
 * (QualifyingTimeList::is_active). Kein Jahres-Parameter: es gibt immer nur eine "aktuelle"
 * Startberechtigung, historische Listen sind eine interne Angelegenheit.
 *
 * Eigenständige, schlankere Neuimplementierung statt Wiederverwendung von
 * QualifyingTimeListController::filteredQualifications() — bewusst ohne dessen Namenssuche (siehe
 * PublicQualificationFilter) und ohne dessen admin-/Request-gekoppelte Filterlogik. Die
 * Gruppierung (Behinderungsgruppe → Bewerb) ist dagegen identisch und kommt aus
 * DisabilityGroupGrouper, das genau dafür aus dem internen Controller extrahiert wurde.
 */
class QualifyingTimeController extends Controller
{
    public function index(Request $request): View
    {
        $list = QualifyingTimeList::where('is_active', true)->first();

        if (! $list) {
            return view('public.qualifying-times.index', [
                'list' => null,
                'filter' => PublicQualificationFilter::fromQuery([]),
                'events' => collect(),
                'genders' => collect(),
                'sportClasses' => collect(),
                'sportClassGroups' => collect(),
                'clubs' => collect(),
                'sections' => collect(),
                'referenceTimes' => collect(),
            ]);
        }

        $filter = PublicQualificationFilter::fromQuery($request->query());

        $base = Qualification::where('qualifying_time_list_id', $list->id)
            ->with(['athlete', 'club', 'qualifyingTime.strokeType']);

        // Filteroptionen aus dem ungefilterten Gesamtbestand ableiten, damit sie beim Filtern
        // nicht aus den Auswahlfeldern verschwinden (wie im internen Pendant).
        $all = (clone $base)->get();

        $events = $all->map(fn (Qualification $q) => [
            'stroke_type_id' => $q->qualifyingTime->stroke_type_id,
            'distance' => $q->qualifyingTime->distance,
            'label' => "{$q->qualifyingTime->distance}m {$q->qualifyingTime->strokeType?->name_de}",
        ])->unique(fn (array $e) => "{$e['stroke_type_id']}-{$e['distance']}")->sortBy('distance')->values();

        $genders = $all->pluck('qualifyingTime.gender')->filter()->unique()->sort()->values();
        $sportClasses = $all->pluck('sport_class')->unique()->sortBy(fn (?string $sc
        ) => SportClassSorter::key($sc))->values();
        $clubs = $all->pluck('club')->filter()->unique('id')->sortBy('name')->values();

        // Zusätzlicher, gröberer Filter neben der einzelnen Sportklasse (Rückmeldung: "wenn alle
        // Sportklassen gewählt ist, dass man sich nur die Sportklassengruppen ebenfalls ansehen
        // kann, so wie bei der Jahresbestleistung die Klasse") — dieselbe Zuordnungstabelle wie
        // DisabilityGroupGrouper, das die Sektionen weiter unten ohnehin schon danach gliedert.
        $memberMap = SportClassGroupMember::pluck('sport_class_group_id', 'sport_class');
        $presentGroupIds = $all->pluck('sport_class')->map(fn (?string $sc) => $memberMap->get($sc))->filter()->unique();
        $sportClassGroups = SportClassGroup::active()->whereIn('id', $presentGroupIds)->orderBy('sort_order')->get();

        $filtered = clone $base;

        if ($filter->strokeTypeId !== null && $filter->distance !== null) {
            $filtered->whereHas('qualifyingTime', fn ($q) => $q
                ->where('stroke_type_id', $filter->strokeTypeId)
                ->where('distance', $filter->distance));
        }
        if ($filter->gender !== '') {
            $filtered->whereHas('qualifyingTime', fn ($q) => $q->where('gender', $filter->gender));
        }
        if ($filter->sportClass !== '') {
            $filtered->where('sport_class', $filter->sportClass);
        }
        if ($filter->sportClassGroupId !== null) {
            $classesInGroup = $memberMap->filter(fn ($groupId) => (int) $groupId === $filter->sportClassGroupId)->keys();
            $filtered->whereIn('sport_class', $classesInGroup);
        }
        if ($filter->clubId !== null) {
            $filtered->where('club_id', $filter->clubId);
        }

        $qualifications = $filtered->get()->sortBy(
            fn (Qualification $q) => $q->athlete?->last_name.'|'.$q->athlete?->first_name
        );

        $sections = DisabilityGroupGrouper::byGroupThenStroke(
            $qualifications,
            fn (Qualification $q) => $q->athlete?->last_name.'|'.$q->athlete?->first_name
        );

        return view('public.qualifying-times.index', [
            'list' => $list,
            'filter' => $filter,
            'events' => $events,
            'genders' => $genders,
            'sportClasses' => $sportClasses,
            'sportClassGroups' => $sportClassGroups,
            'clubs' => $clubs,
            'sections' => $sections,
            'referenceTimes' => $this->referenceTimes($list, $filter),
        ]);
    }

    /**
     * Richtzeiten der gewählten Sportklasse, je Bewerb (und Geschlecht, falls gefiltert) —
     * Rückmeldung: "beim Filtern der Sportklassen müsste hier die Richtzeit der Sportklasse hin".
     * Leer, solange keine Sportklasse gewählt ist (die Richtzeit einer *einzelnen* Sportklasse
     * ergibt ohne diese Eingrenzung keinen eindeutigen Sinn — je Bewerb/Geschlecht gibt es sonst
     * bis zu 21 verschiedene Werte).
     *
     * @return Collection<string, Collection<int, QualifyingTime>> Schlüssel "stroke_type_id-distance"
     */
    private function referenceTimes(QualifyingTimeList $list, PublicQualificationFilter $filter): Collection
    {
        if ($filter->sportClass === '') {
            return collect();
        }

        return QualifyingTime::where('qualifying_time_list_id', $list->id)
            ->where('sport_class', $filter->sportClass)
            ->when($filter->gender !== '', fn ($q) => $q->where('gender', $filter->gender))
            ->get()
            ->groupBy(fn (QualifyingTime $qt) => "$qt->stroke_type_id-$qt->distance");
    }
}
