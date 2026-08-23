<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\AgeGroup;
use App\Models\Cup;
use App\Models\Meet;
use App\Models\SportClassGroup;
use App\Services\OverallRankingService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Public\CupRankingController
 *
 * Öffentliche ÖBSV Cup-Wertung (Spec public-frontend §5.4/Phase 7) — read-only Ansicht des
 * bereits berechneten Gesamtwertungs-Snapshots (OverallRankingService, dieselbe Logik wie im
 * internen CupOverallRankingController) — inklusive Runden-Aufschlüsselung
 * (OverallRankingService::attachRoundBreakdown(), Rückmeldung: "die Punkte der einzelnen
 * Runden gehören dazu"). Kein Neu-berechnen-Button (admin-only, bleibt intern), kein
 * PDF-Export (keine eigene Route dafür in der Spec-Routentabelle). Athletennamen sind
 * unverlinkter Text (§2.3 Regel 2 — anders als die interne Ansicht, die auf athletes.show
 * verlinkt).
 *
 * Jahresauswahl direkt auf der Seite (Dropdown, Planungsentscheidung Phase 7) statt einer
 * eigenen Indexseite — die Spec-Routentabelle listet nur /de/cup/{jahr}. Ohne (oder mit
 * unbekanntem) Jahr wird das aktuellste Cup-Jahr mit vorhandener Gesamtwertung gezeigt.
 */
class CupRankingController extends Controller
{
    public function __construct(
        private readonly OverallRankingService $overallRankingService,
    ) {}

    /**
     * $jahr kommt aus dem hübschen Pfadsegment (JS-Jahresauswahl, siehe View); ?year= ist der
     * Fallback des <noscript>-Formulars, das ohne JavaScript nur einen Query-Parameter an die
     * aktuelle URL anhängen kann, kein neues Pfadsegment.
     *
     * Bewusst $request->route('jahr') statt eines eigenen Methodenparameters: Die Route liegt
     * unter der {locale}-Präfixgruppe, die selbst kein Methodenargument ist — Laravels implizite
     * Bindung von Nicht-Klassen-Routenparametern läuft dann positionsbasiert statt namensbasiert
     * (RouteDependencyResolverTrait::resolveMethodDependencies), und ein zusätzlicher
     * ?string $jahr-Parameter hätte fälschlich den locale-Wert bekommen (Fund: /de/cup/2025 lieferte
     * $jahr = 'de'). Betrifft jede Route dieser Art — siehe CLAUDE.md-Fallstricke.
     */
    public function index(Request $request): View
    {
        $jahr = $request->route('jahr');
        $years = Cup::orderByDesc('year')->pluck('year');

        $requestedYear = $jahr !== null ? (int) $jahr : ($request->integer('year') ?: null);
        $year = $requestedYear !== null && $years->contains($requestedYear) ? $requestedYear : $years->first();

        $cup = $year !== null ? Cup::where('year', $year)->first() : null;
        $meets = $cup ? $this->overallRankingService->cupMeets($cup) : collect();

        return view('public.cup-ranking.index', [
            'years' => $years,
            'year' => $year,
            'cup' => $cup,
            'meets' => $meets,
            'brackets' => $cup ? $this->resolveBrackets($cup, $meets) : collect(),
        ]);
    }

    /**
     * Liefert die echten Wertungskategorien plus zwei Sammel-Varianten, die keine eigene
     * Bucket-Konfiguration brauchen (Rückmeldung: "beim Klasse Filter fehlt mir noch alle
     * Klassengruppen zusammen und beim Geschlecht Filter Damen und Herren zusammen — ich meinte
     * das alle gemeinsam über die Punkte gewertet werden"):
     *   - `group: null` steht für "Alle Klassen" (OverallRankingService::rankedAcrossGroups()).
     *   - `gender: null` steht für "Damen & Herren" — bei bereits gemeinsam gewerteten Gruppen
     *     (Cup::isGenderCombined) ist das die ohnehin schon existierende echte Kategorie; bei
     *     getrennt gewerteten Gruppen wird sie hier zusätzlich aus den bereits berechneten
     *     Damen-/Herren-Zeilen zusammengelegt (rankedBracket() mit $gender = null filtert
     *     ohnehin nicht nach Geschlecht — dieselbe Abfrage, die auch echte gemeinsame Wertungen
     *     bedient).
     * Leere Kombinationen werden nicht angeboten.
     *
     * @param  EloquentCollection<int, Meet>  $meets  siehe OverallRankingService::cupMeets(), einmal pro Cup ermittelt
     * @return Collection<int, array{gender: ?string, group: ?SportClassGroup, ageGroup: ?AgeGroup, results: Collection}>
     */
    private function resolveBrackets(Cup $cup, EloquentCollection $meets): Collection
    {
        $realBrackets = $this->overallRankingService->rankedBrackets($cup, $meets);

        return $realBrackets
            ->concat($this->mergedGenderBrackets($cup, $meets, $realBrackets)->all())
            ->concat($this->mergedGroupBrackets($cup, $meets, $realBrackets)->all())
            ->values();
    }

    /**
     * "Damen & Herren" je Sportklassengruppe (übersprungen, wenn die Gruppe schon eine echte
     * gemeinsame Wertung hat — die ist dann bereits identisch).
     *
     * @param  Collection<int, array{gender: ?string, group: SportClassGroup, ageGroup: ?AgeGroup, results: Collection}>  $realBrackets
     * @return Collection<int, array{gender: null, group: SportClassGroup, ageGroup: ?AgeGroup, results: Collection}>
     */
    private function mergedGenderBrackets(Cup $cup, EloquentCollection $meets, Collection $realBrackets): Collection
    {
        return $realBrackets
            ->unique(fn (array $b) => $b['group']->id.'|'.($b['ageGroup']?->id ?? 'x'))
            ->reject(fn (array $b) => $b['gender'] === null) // schon gemeinsam gewertet
            ->map(function (array $b) use ($cup, $meets) {
                $results = $this->overallRankingService->rankedBracket(
                    $cup->id, null, $b['group']->id, $b['ageGroup']?->id
                );

                return [
                    'gender' => null,
                    'group' => $b['group'],
                    'ageGroup' => $b['ageGroup'],
                    'results' => $this->overallRankingService->attachRoundBreakdown($results, $cup, $meets),
                ];
            })
            ->values();
    }

    /**
     * "Alle Klassen" je Altersgruppe, einmal je vorhandenem Geschlecht (inkl. "Damen & Herren"
     * als klassen- UND geschlechtsübergreifende Gesamtwertung).
     *
     * @param  Collection<int, array{gender: ?string, group: SportClassGroup, ageGroup: ?AgeGroup, results: Collection}>  $realBrackets
     * @return Collection<int, array{gender: ?string, group: null, ageGroup: ?AgeGroup, results: Collection}>
     */
    private function mergedGroupBrackets(Cup $cup, EloquentCollection $meets, Collection $realBrackets): Collection
    {
        $ageGroups = $realBrackets->pluck('ageGroup')->unique(fn (?AgeGroup $ag) => $ag?->id ?? 'x')->values();
        // null (= "Damen & Herren", die klassen- und geschlechtsübergreifende Gesamtwertung) steht
        // immer zusätzlich zur Auswahl — unabhängig davon, ob irgendeine einzelne Gruppe eine echte
        // gemeinsame Wertung hat.
        $genders = $realBrackets->pluck('gender')->unique()->merge([null])->unique()->values();

        $merged = collect();

        foreach ($ageGroups as $ageGroup) {
            foreach ($genders as $gender) {
                $results = $this->overallRankingService->rankedAcrossGroups($cup->id, $gender, $ageGroup?->id);

                if ($results->isEmpty()) {
                    continue;
                }

                $merged->push([
                    'gender' => $gender,
                    'group' => null,
                    'ageGroup' => $ageGroup,
                    'results' => $this->overallRankingService->attachRoundBreakdown($results, $cup, $meets),
                ]);
            }
        }

        return $merged;
    }
}
