<?php

namespace App\Services\Public;

use App\Models\Meet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * PublicMeetService — Veranstaltungslisten für den öffentlichen Bereich (Spec public-frontend
 * §5.1). Rein lesend, nur veröffentlichte Veranstaltungen (Meet::scopePublished()).
 *
 * "Vorbei" heißt: Enddatum in der Vergangenheit, oder — ohne Enddatum — Startdatum in der
 * Vergangenheit. Der Vergleich läuft über Datums-Strings (`toDateString()`), nicht über
 * Carbon-Objekte im Query-Callback, damit die Bedingung auf MySQL und SQLite identisch bleibt.
 */
final readonly class PublicMeetService
{
    /**
     * Die nächsten $limit veröffentlichten Veranstaltungen, chronologisch aufsteigend.
     *
     * @return Collection<int, Meet>
     */
    public function upcoming(int $limit = 10): Collection
    {
        $today = Carbon::today()->toDateString();

        return Meet::published()
            ->with('nation')
            ->where(function (Builder $query) use ($today): void {
                $query->where('end_date', '>=', $today)
                    ->orWhere(function (Builder $query) use ($today): void {
                        $query->whereNull('end_date')->where('start_date', '>=', $today);
                    });
            })
            ->oldest('start_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Die letzten $limit veröffentlichten Veranstaltungen, neueste zuerst.
     *
     * @return Collection<int, Meet>
     */
    public function recentPast(int $limit = 10): Collection
    {
        return $this->pastQuery()
            ->latest('start_date')
            ->limit($limit)
            ->get();
    }

    /**
     * Alle veröffentlichten vergangenen Veranstaltungen, gruppiert nach Jahr (neuestes Jahr
     * zuerst, innerhalb eines Jahres neuestes Datum zuerst) — Grundlage für das Archiv (§5.1).
     *
     * @return Collection<int, Collection<int, Meet>>
     */
    public function archiveGroupedByYear(): Collection
    {
        return $this->pastQuery()
            ->latest('start_date')
            ->get()
            ->groupBy(fn (Meet $meet): int => $meet->start_date->year);
    }

    private function pastQuery(): Builder
    {
        $today = Carbon::today()->toDateString();

        return Meet::published()
            ->with('nation')
            ->where(function (Builder $query) use ($today): void {
                $query->where('end_date', '<', $today)
                    ->orWhere(function (Builder $query) use ($today): void {
                        $query->whereNull('end_date')->where('start_date', '<', $today);
                    });
            });
    }
}
