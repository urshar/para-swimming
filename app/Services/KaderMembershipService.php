<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AthleteKaderMembership;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Nationalkader-Zugehörigkeiten eines Athleten als lückenlose Kette pflegen: Ein neuer Eintrag schließt die noch
 * laufenden Vorgänger am Vortag seines Beginns ab, das Löschen des neuesten Eintrags öffnet den so abgeschlossenen
 * Vorgänger wieder. valid_until gilt einschließlich (siehe AthleteKaderMembership::scopeActiveOn()), deshalb endet
 * der Vorgänger am Vortag — sonst wäre der Athlet an einem Tag in zwei Kadern.
 *
 * Die Vergleiche laufen bewusst in PHP über die (wenigen) Einträge des Athleten statt per SQL: SQLite speichert
 * Datums-Casts mit Uhrzeit, MySQL nicht — ein direkter Gleichheitsvergleich in SQL wäre nicht portabel.
 */
final readonly class KaderMembershipService
{
    /**
     * Legt eine Kaderzugehörigkeit an. Ohne "Gültig ab" gilt das Eintragsdatum (heute).
     *
     * @param  array{kader_type_id: int|string, valid_from: ?string, valid_until: ?string, notes: ?string}  $data
     * @return array{0: AthleteKaderMembership, 1: Collection<int, AthleteKaderMembership>} neuer Eintrag und die
     *                                                                                      dabei abgeschlossenen Vorgänger
     *
     * @throws Throwable
     */
    public function create(Athlete $athlete, array $data): array
    {
        $validFrom = Carbon::parse($data['valid_from'] ?? Carbon::today())->startOfDay();

        return DB::transaction(function () use ($athlete, $data, $validFrom) {
            $closed = $athlete->kaderMemberships()->get()
                ->filter(fn (AthleteKaderMembership $m) => $m->valid_until === null
                    && ($m->valid_from === null || $m->valid_from->lt($validFrom)))
                ->values();

            foreach ($closed as $membership) {
                $membership->update(['valid_until' => $validFrom->copy()->subDay()]);
            }

            $membership = $athlete->kaderMemberships()->create([
                'kader_type_id' => $data['kader_type_id'],
                'valid_from' => $validFrom,
                'valid_until' => $data['valid_until'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            return [$membership, $closed];
        });
    }

    /**
     * Löscht eine Kaderzugehörigkeit. War sie die neueste, wird der Vorgänger, der am Vortag ihres Beginns endet
     * (also beim Anlegen automatisch abgeschlossen wurde), wieder auf "laufend" gesetzt. Ein Vorgänger, der
     * absichtlich früher beendet wurde, bleibt unverändert.
     *
     * @return Collection<int, AthleteKaderMembership> die wieder geöffneten Vorgänger
     *
     * @throws Throwable
     */
    public function delete(AthleteKaderMembership $membership): Collection
    {
        return DB::transaction(function () use ($membership) {
            $others = AthleteKaderMembership::where('athlete_id', $membership->athlete_id)
                ->whereKeyNot($membership->id)
                ->get();

            $membership->delete();

            $start = $membership->valid_from;
            if ($start === null) {
                return collect();
            }

            $hasLater = $others->contains(fn (AthleteKaderMembership $m) => $m->valid_from?->gt($start) ?? false);
            if ($hasLater) {
                return collect();
            }

            $dayBefore = $start->copy()->subDay();
            $reopened = $others
                ->filter(fn (AthleteKaderMembership $m) => $m->valid_until?->isSameDay($dayBefore) ?? false)
                ->values();

            foreach ($reopened as $predecessor) {
                $predecessor->update(['valid_until' => null]);
            }

            return $reopened;
        });
    }
}
