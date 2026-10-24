<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Result;
use App\Services\ResultClubChangeService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * MeetResultClubsController
 *
 * Seite "Vereine korrigieren" eines Wettkampfs: Verein der Einzelergebnisse gesammelt je Athlet ändern — z. B. nach
 * einem LENEX-Import, in dem die österreichischen Schwimmer unter dem Nationalteam (ÖBSV) statt unter ihrem
 * Stammverein stehen und deshalb keine Landesrekorde erkannt werden. Die Folgearbeiten (Rekorde mitziehen,
 * Landesrekorde nachprüfen) übernimmt ResultClubChangeService. Staffelergebnisse bleiben unberührt (ihr Verein ist
 * der Staffelverein). Nur Admin (Route-Middleware RequireAdmin).
 */
class MeetResultClubsController extends Controller
{
    public function __construct(
        private readonly ResultClubChangeService $clubChange,
    ) {}

    public function index(Meet $meet): View
    {
        $results = $meet->results()->with(['athlete.club', 'club'])->get();

        $rows = $results->groupBy('athlete_id')
            ->map(function (Collection $athleteResults): array {
                /** @var Athlete $athlete */
                $athlete = $athleteResults->first()->athlete;

                return [
                    'athlete' => $athlete,
                    'home' => $athlete->club,
                    'clubs' => $athleteResults->groupBy('club_id')
                        ->map(fn (Collection $byClub): array => ['club' => $byClub->first()->club, 'count' => $byClub->count()])
                        ->values(),
                    'count' => $athleteResults->count(),
                    // Nur Ergebnisse unter einem Verband gelten als falsch zugeordnet; ein anderer gewöhnlicher Verein
                    // ist meist ein späterer Vereinswechsel, dann stimmt der Verein zum Zeitpunkt des Starts.
                    'underFederation' => $athleteResults->contains(fn (Result $r): bool => self::isMisassigned($r)),
                ];
            })
            ->sortBy(fn (array $row): string => mb_strtolower($row['athlete']->last_name.' '.$row['athlete']->first_name))
            ->values();

        return view('meets.result-clubs', [
            'meet' => $meet,
            'rows' => $rows,
            'clubs' => Club::orderBy('name')->get(),
            'federationCount' => $this->federationResults($meet)->count(),
            'backUrl' => MeetResultsOverviewController::backUrl($meet),
        ]);
    }

    /**
     * Alle Einzelergebnisse eines Athleten in diesem Wettkampf auf einen Verein setzen.
     *
     * @throws Throwable
     */
    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $data = $request->validate([
            'athlete_id' => ['required', Rule::exists('results', 'athlete_id')->where('meet_id', $meet->id)],
            'club_id' => 'required|exists:clubs,id',
        ]);

        $athlete = Athlete::findOrFail($data['athlete_id']);
        $clubId = (int) $data['club_id'];
        $outcome = $this->clubChange->reassign(
            $meet->results()->where('athlete_id', $athlete->id)->get(),
            fn (Result $result): int => $clubId,
        );

        return $this->done($meet, $outcome, $athlete->display_name.': ');
    }

    /**
     * Ergebnisse, die unter einem Verband (Nationalteam/Landesauswahl) stehen, auf den Heimverein des Athleten setzen.
     *
     * @throws Throwable
     */
    public function adoptHomeClubs(Meet $meet): RedirectResponse
    {
        $outcome = $this->clubChange->reassign(
            $this->federationResults($meet),
            fn (Result $result): int => $result->athlete->club_id,
        );

        return $this->done($meet, $outcome, '');
    }

    /**
     * Einzelergebnisse unter einem Verein vom Typ Verband, deren Athlet einen anderen Heimverein hat, der selbst kein
     * Verband ist.
     *
     * @return Collection<int, Result>
     */
    private function federationResults(Meet $meet): Collection
    {
        return $meet->results()
            ->with(['athlete.club', 'club'])
            ->get()
            ->filter(fn (Result $result): bool => self::isMisassigned($result))
            ->values();
    }

    private static function isMisassigned(Result $result): bool
    {
        return $result->club?->type === Club::TYPE_VERBAND
            && $result->athlete?->club !== null
            && $result->athlete->club->type !== Club::TYPE_VERBAND
            && $result->athlete->club_id !== $result->club_id;
    }

    /** @param  array{changed: int, notes: list<string>}  $outcome */
    private function done(Meet $meet, array $outcome, string $prefix): RedirectResponse
    {
        $count = $outcome['changed'];
        $message = $prefix.$count.' '.($count === 1 ? 'Ergebnis' : 'Ergebnisse').' geändert.';
        if ($outcome['notes'] !== []) {
            $message .= ' '.implode('. ', $outcome['notes']).'.';
        }

        return redirect()->route('meets.result-clubs', $meet)->with('success', $message);
    }
}
