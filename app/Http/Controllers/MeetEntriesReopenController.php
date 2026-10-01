<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use Carbon\CarbonInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Validation\ValidationException;

/**
 * Meldeschluss kontrolliert wiedereröffnen (Admin-only, docs/specs/club-entries.md "Meldeschluss und Nachmeldungen").
 *
 * Nach Ablauf des Meldeschlusses kann der Admin die Veranstaltung für die Vereine bis zu einem Zeitpunkt wieder
 * öffnen — ein Fenster für alle Vereine. Gespeichert wird nur das letzte Öffnen (wer, wann, bis wann).
 */
class MeetEntriesReopenController extends Controller
{
    /** Schnellauswahl in Stunden ab jetzt. */
    public const array QUICK_HOURS = [24, 48];

    public function store(Request $request, Meet $meet): RedirectResponse
    {
        if (! $meet->isDeadlinePassed()) {
            return back()->with('error', 'Der Meldeschluss ist noch nicht abgelaufen.');
        }

        $until = $request->filled('hours')
            ? $this->untilFromHours($request)
            : $this->untilFromDateTime($request);

        $meet->forceFill([
            'entries_reopened_until' => $until,
            'entries_reopened_by' => $request->user()->id,
            'entries_reopened_at' => now(),
        ])->save();

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Meldungen für Vereine wieder geöffnet bis '.$until->format('d.m.Y H:i').' Uhr.');
    }

    /** Fenster vorzeitig schließen. Das Protokoll (wer/wann) bleibt stehen. */
    public function destroy(Meet $meet): RedirectResponse
    {
        if ($meet->isReopened()) {
            $meet->forceFill(['entries_reopened_until' => now()])->save();
        }

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Meldungen für Vereine wieder geschlossen.');
    }

    private function untilFromHours(Request $request): CarbonInterface
    {
        $validated = $request->validate([
            'hours' => ['integer', 'in:'.implode(',', self::QUICK_HOURS)],
        ]);

        return now()->addHours((int) $validated['hours'])->startOfMinute();
    }

    private function untilFromDateTime(Request $request): CarbonInterface
    {
        $validated = $request->validate([
            'until_date' => ['required', 'date'],
            'until_time' => ['required', 'date_format:H:i'],
        ], [
            'until_date.required' => 'Bitte ein Datum angeben.',
            'until_time.required' => 'Bitte eine Uhrzeit angeben.',
            'until_time.date_format' => 'Die Uhrzeit muss im Format HH:MM angegeben werden.',
        ]);

        $until = Date::parse($validated['until_date'].' '.$validated['until_time']);

        if ($until->lte(now())) {
            throw ValidationException::withMessages(['until_date' => 'Der Zeitpunkt muss in der Zukunft liegen.']);
        }

        return $until;
    }
}
