<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Models\MeetSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Datum und Startzeit je Abschnitt einer Veranstaltung pflegen (Tabelle meet_sessions). Angezeigt werden alle
 * Abschnittsnummern, die in den Disziplinen vorkommen, plus bereits gespeicherte Abschnitte.
 */
class MeetSessionController extends Controller
{
    public function edit(Meet $meet): View
    {
        $sessions = $meet->sessions()->get()->keyBy('number');

        return view('meets.sessions', [
            'meet' => $meet,
            'numbers' => $this->sessionNumbers($meet, $sessions),
            'sessions' => $sessions,
        ]);
    }

    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $from = $meet->start_date->toDateString();
        $until = ($meet->end_date ?? $meet->start_date)->toDateString();

        $validated = $request->validate([
            'sessions' => ['array'],
            'sessions.*.date' => ['nullable', 'date', 'after_or_equal:'.$from, 'before_or_equal:'.$until],
            'sessions.*.daytime' => ['nullable', 'date_format:H:i'],
        ], [
            'sessions.*.date.after_or_equal' => 'Das Datum muss im Zeitraum der Veranstaltung liegen.',
            'sessions.*.date.before_or_equal' => 'Das Datum muss im Zeitraum der Veranstaltung liegen.',
            'sessions.*.daytime.date_format' => 'Die Startzeit muss im Format HH:MM angegeben werden.',
        ]);

        $allowed = $this->sessionNumbers($meet, $meet->sessions()->get()->keyBy('number'));

        foreach ($validated['sessions'] ?? [] as $number => $values) {
            $number = (int) $number;
            if (! $allowed->contains($number)) {
                continue;
            }

            $date = $values['date'] ?? null;
            $daytime = $values['daytime'] ?? null;

            // Beide Felder leer = Abschnitt ohne eigenes Datum — keine leere Zeile aufbewahren.
            if ($date === null && $daytime === null) {
                MeetSession::where('meet_id', $meet->id)->where('number', $number)->delete();

                continue;
            }

            MeetSession::updateOrCreate(
                ['meet_id' => $meet->id, 'number' => $number],
                ['date' => $date, 'daytime' => $daytime]
            );
        }

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Abschnitte gespeichert.');
    }

    /**
     * Abschnittsnummern aus den Disziplinen plus bereits gespeicherte Abschnitte, aufsteigend.
     *
     * @param  Collection<int, MeetSession>  $sessions
     * @return Collection<int, int>
     */
    private function sessionNumbers(Meet $meet, Collection $sessions): Collection
    {
        return $meet->swimEvents()->distinct()->pluck('session_number')
            ->map(fn ($n): int => (int) $n)
            ->merge($sessions->keys())
            ->unique()
            ->sort()
            ->values();
    }
}
