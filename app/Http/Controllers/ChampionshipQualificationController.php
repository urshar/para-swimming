<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Services\QualificationEvaluationService;
use App\Support\QualificationRow;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Die beiden Ansichten der Erfüllungsübersicht (Spec §7.5).
 *
 * Bewusst zwei getrennte Ansichten, keine gemeinsame mit Statusfilter:
 *
 *   qualified()   — Wer hat sich qualifiziert, und wie weit fehlt den übrigen?
 *                   Nur reale Zeiten aus WPS-anerkannten Wettkämpfen.
 *   development() — Hat der Athlet international eine Chance?
 *                   Alles, einschließlich umgerechneter Kurzbahnzeiten, gekennzeichnet.
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
    private const int ATHLETES_PER_PAGE = 10;

    public function __construct(
        private readonly QualificationEvaluationService $service
    ) {}

    /**
     * Qualifikantenansicht — Kaderart → Athlet → Bewerbe mit Norm.
     *
     * Die eigentliche Darstellung übernimmt die Livewire-Komponente; sie braucht Filter und
     * das Aufklappen des Leistungsverlaufs.
     */
    public function qualified(Championship $championship): View
    {
        return view('championships.qualified', [
            'championship' => $championship,
            'clubId' => $this->clubFilter(),
        ]);
    }

    /**
     * Förderansicht — je Athlet über alle Bewerbe, mit umgerechneten Zeiten und Zielzeiten.
     *
     * Zeigt standardmäßig alle Athleten mit mindestens einem Ergebnis im Zeitraum, statt
     * einer leeren Seite, die erst nach einer Auswahl etwas anzeigt.
     */
    public function development(Request $request, Championship $championship): View
    {
        $clubId = $this->clubFilter();
        $suche = trim((string) $request->query('q', ''));

        $athleten = $this->service->evaluate($championship, $clubId, null);

        if ($suche !== '') {
            $athleten = $athleten->filter(
                static fn (array $eintrag): bool => str_contains(
                    mb_strtolower((string) $eintrag['athlete']->full_name),
                    mb_strtolower($suche)
                )
            );
        }

        $sortiert = $athleten
            ->map(static fn (array $eintrag): array => self::splitByStandard($eintrag))
            // Athleten, bei denen kein einziger Bewerb eine Norm hat, tragen zur Frage
            // "wie weit fehlt zur Norm" nichts bei.
            ->filter(static fn (array $eintrag): bool => $eintrag['rows']->isNotEmpty())
            ->sortBy(static fn (array $eintrag): string => (string) ($eintrag['athlete']->last_name ?? ''))
            ->values();

        return view('championships.development', [
            'championship' => $championship,
            'entries' => $this->paginate($sortiert, $request),
            'search' => $suche,
        ]);
    }

    /**
     * Trennt Bewerbe mit Norm von solchen ohne.
     *
     * Bewerbe ohne Norm werden nicht als Tabellenzeile geführt: Die Förderansicht beantwortet
     * "wie weit fehlt zur Norm", und diese Entfernung gibt es dort nicht — die Zeile
     * verlängert nur die Liste.
     *
     * Sie verschwinden aber nicht stillschweigend, sondern werden am Fuß des Athletenblocks
     * benannt. Sonst entstünde der Eindruck, der Athlet sei dort gar nicht angetreten
     * (§7.4, Risiko Q-R4).
     *
     * Bewusst hier und nicht im Service: Der Status `no_standard` bleibt Teil der Bewertung
     * und ist weiterhin geprüft; getrennt wird erst für die Darstellung.
     *
     * @param  array<string, mixed>  $eintrag
     * @return array<string, mixed>
     */
    private static function splitByStandard(array $eintrag): array
    {
        $alle = $eintrag['rows'];

        return [
            'athlete' => $eintrag['athlete'],
            'rows' => $alle
                ->filter(static fn (QualificationRow $z): bool => $z->standard !== null)
                ->values(),
            'events_without_standard' => $alle
                ->filter(static fn (QualificationRow $z): bool => $z->standard === null)
                ->map(static fn (QualificationRow $z): string => $z->eventLabel)
                ->unique()
                ->sort()
                ->values(),
        ];
    }

    /**
     * Seitenweise Ausgabe der Athleten.
     *
     * Von Hand statt über paginate() der Abfrage: Die Bewertung findet in PHP statt, weil je
     * Athlet alle Bewerbe gegeneinander aufgelöst werden müssen (bedingte MET-Auswertung,
     * §7.2). Eine Datenbank-Seiteneinteilung würde Athleten mittendrin abschneiden und diese
     * Auflösung verfälschen.
     *
     * Gezählt werden Athleten, nicht Zeilen — jeder Athlet ist eine eigene Tabelle, und ihn
     * über zwei Seiten zu zerreißen wäre unlesbar.
     *
     * @param  Collection<int, array<string, mixed>>  $athleten
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginate(Collection $athleten, Request $request): LengthAwarePaginator
    {
        $seite = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $athleten->forPage($seite, self::ATHLETES_PER_PAGE)->values(),
            $athleten->count(),
            self::ATHLETES_PER_PAGE,
            $seite,
            [
                'path' => $request->url(),
                // Ohne das fiele die Suche beim Blättern weg und man landete wieder in der
                // vollständigen Liste.
                'query' => $request->query(),
            ],
        );
    }

    /**
     * Vereinsnutzer sehen nur die Athleten ihres Vereins; Admins alle.
     */
    private function clubFilter(): ?int
    {
        $nutzer = auth()->user();

        return $nutzer?->is_admin === true ? null : $nutzer?->club_id;
    }
}
