<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Championship;
use App\Services\QualificationEvaluationService;
use App\Support\QualificationRow;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Die beiden Ansichten der Erfüllungsübersicht (Spec §7).
 *
 * Bewusst zwei getrennte Ansichten, keine gemeinsame mit Statusfilter:
 *
 *   qualified()   — Wer hat sich qualifiziert? Ausschließlich Nachweise.
 *   development() — Hat der Athlet international eine Chance? Alles, gekennzeichnet.
 *
 * Ein Statusfilter über einer gemeinsamen Liste wäre die Möglichkeit, sich umgerechnete
 * Zeiten doch wieder in die Nachweisliste zu holen — genau das Missverständnis, das Q-R1
 * verhindern soll.
 *
 * Beide Ansichten sind lesend und stehen allen Angemeldeten offen; Vereinsnutzer sehen nur
 * die Athleten ihres Vereins.
 */
class ChampionshipQualificationController extends Controller
{
    public function __construct(
        private readonly QualificationEvaluationService $service
    ) {}

    /**
     * Qualifikantenliste — gruppiert nach Bewerb, Geschlecht und Sportklasse.
     */
    public function qualified(Championship $championship): View
    {
        $clubId = $this->clubFilter();

        $zeilen = $this->service->qualified($championship, $clubId);

        return view('championships.qualified', [
            'championship' => $championship,
            'groups' => $this->groupByEvent($zeilen),
            // Ohne diesen Hinweis ist eine leere Liste nicht von einer korrekt leeren zu
            // unterscheiden (Q-R9).
            'excluded' => $this->service->excludedForMissingApproval($championship, $clubId),
        ]);
    }

    /**
     * Förderansicht — je Athlet über alle Bewerbe.
     *
     * Zeigt standardmäßig alle Athleten mit mindestens einem Ergebnis im Zeitraum, statt
     * einer leeren Seite, die erst nach einer Auswahl etwas anzeigt.
     */
    public function development(Request $request, Championship $championship): View
    {
        $clubId = $this->clubFilter();

        $athleteId = $request->integer('athlete') ?: null;
        $suche = trim((string) $request->query('q', ''));

        $athleten = $this->service->evaluate($championship, $clubId, $athleteId);

        if ($suche !== '') {
            $athleten = $athleten->filter(
                static fn (array $eintrag): bool => str_contains(
                    mb_strtolower($eintrag['athlete']->full_name ?? ''),
                    mb_strtolower($suche)
                )
            )->values();
        }

        return view('championships.development', [
            'championship' => $championship,
            'entries' => $athleten->sortBy(
                static fn (array $eintrag): string => (string) ($eintrag['athlete']->last_name ?? '')
            )->values(),
            'search' => $suche,
            'selectedAthlete' => $athleteId === null ? null : Athlete::query()->find($athleteId),
        ]);
    }

    /**
     * Vereinsnutzer sehen nur die Athleten ihres Vereins; Admins alle.
     */
    private function clubFilter(): ?int
    {
        $nutzer = auth()->user();

        return $nutzer?->is_admin === true ? null : $nutzer?->club_id;
    }

    /**
     * Gruppiert die Nachweise nach Bewerb, Geschlecht und Sportklasse.
     *
     * Innerhalb einer Gruppe nach Zeit aufsteigend — die schnellste zuerst. Das ist noch
     * keine Auswahl-Rangliste (§8, Phase 5), sondern nur eine sinnvolle Lesereihenfolge.
     *
     * @param  Collection<int, QualificationRow>  $zeilen
     * @return Collection<string, Collection<int, QualificationRow>>
     */
    private function groupByEvent(Collection $zeilen): Collection
    {
        /** @var Collection<string, Collection<int, QualificationRow>> $gruppen */
        $gruppen = $zeilen
            ->groupBy(static fn (QualificationRow $zeile): string => sprintf(
                '%s · %s · %s',
                $zeile->eventLabel,
                $zeile->athlete?->gender === 'M' ? 'männlich' : 'weiblich',
                $zeile->sportClass,
            ))
            ->map(static fn (Collection $gruppe): Collection => $gruppe
                ->sortBy(static fn (QualificationRow $zeile): int => $zeile->status->swimTime ?? PHP_INT_MAX)
                ->values())
            ->sortKeys();

        return $gruppen;
    }
}
