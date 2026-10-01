<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Models\MeetFee;
use App\Models\SwimEvent;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Meldegelder einer Veranstaltung pflegen: Gebühren je Typ für die ganze Veranstaltung und je Abschnitt
 * (Tabelle meet_fees) sowie die Gebühr je Meldung in jedem Bewerb (swim_events.fee_cents). Eingabe in Euro,
 * Speicherung in Cent (App\Support\Money).
 */
class MeetFeeController extends Controller
{
    public function edit(Meet $meet): View
    {
        $events = $meet->swimEvents()->with('strokeType')
            ->orderBy('session_number')->orderBy('event_number')->get();

        // Gespeicherte Gebühren als [session_key][typeKey] => Formularwert, session_key "meet" oder Abschnittsnummer.
        $values = [];
        foreach ($meet->fees()->get() as $fee) {
            $sessionKey = $fee->session_number === null ? 'meet' : (string) $fee->session_number;
            $values[$sessionKey][self::typeKey($fee->type)] = Money::toInput($fee->amount_cents);
        }

        return view('meets.fees', [
            'meet' => $meet,
            'events' => $events,
            'sessionNumbers' => $this->sessionNumbers($events),
            'values' => $values,
            'typeKeys' => collect(MeetFee::TYPES)->mapWithKeys(fn (string $label, string $type): array => [
                self::typeKey($type) => ['type' => $type, 'label' => $label],
            ]),
        ]);
    }

    public function update(Request $request, Meet $meet): RedirectResponse
    {
        $money = ['nullable', 'regex:'.Money::INPUT_PATTERN];
        $request->validate([
            'fees.meet.*' => $money,
            'fees.session.*.*' => $money,
            'events.*' => $money,
            'bulk_individual' => $money,
            'bulk_relay' => $money,
        ], [
            'regex' => 'Bitte einen Betrag in Euro angeben, z. B. 10 oder 10,50.',
        ]);

        $events = $meet->swimEvents()->get();

        $this->saveFees($meet, null, (array) $request->input('fees.meet', []));
        foreach ($this->sessionNumbers($events) as $number) {
            $this->saveFees($meet, $number, (array) $request->input("fees.session.$number", []));
        }

        // Schnellaktion: ein ausgefüllter Sammelbetrag überschreibt die Einzelwerte aller Einzel- bzw. Staffelbewerbe.
        $bulkIndividual = Money::toCents($request->input('bulk_individual'));
        $bulkRelay = Money::toCents($request->input('bulk_relay'));

        foreach ($events as $event) {
            $bulk = $event->relay_count > 1 ? $bulkRelay : $bulkIndividual;
            $event->update([
                'fee_cents' => $bulk ?? Money::toCents($request->input("events.$event->id")),
            ]);
        }

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', 'Meldegelder gespeichert.');
    }

    /**
     * Gebühren einer Ebene speichern — leerer Betrag entfernt die Gebühr dieses Typs.
     *
     * @param  array<string, string|null>  $input  typeKey => Euro-Betrag
     */
    private function saveFees(Meet $meet, ?int $sessionNumber, array $input): void
    {
        foreach (array_keys(MeetFee::TYPES) as $type) {
            $cents = Money::toCents($input[self::typeKey($type)] ?? null);
            $where = ['meet_id' => $meet->id, 'session_number' => $sessionNumber, 'type' => $type];

            if ($cents === null) {
                MeetFee::where($where)->delete();
            } else {
                MeetFee::updateOrCreate($where, ['amount_cents' => $cents, 'currency' => 'EUR']);
            }
        }
    }

    /**
     * Formularschlüssel eines LENEX-Typs ohne Punkt ("LATEENTRY.INDIVIDUAL" → "LATEENTRY_INDIVIDUAL") — Punkte in
     * Feldnamen würde Laravels Validierung als Verschachtelung lesen.
     */
    private static function typeKey(string $type): string
    {
        return str_replace('.', '_', $type);
    }

    /**
     * @param  Collection<int, SwimEvent>  $events
     * @return Collection<int, int>
     */
    private function sessionNumbers(Collection $events): Collection
    {
        return $events->pluck('session_number')->map(fn ($n): int => (int) $n)->unique()->sort()->values();
    }
}
