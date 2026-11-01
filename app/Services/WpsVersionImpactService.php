<?php

namespace App\Services;

use App\Models\Meet;
use App\Models\PointSystem;
use App\Models\Result;
use App\Models\WpsPointVersion;
use Illuminate\Support\Collection;

/**
 * Findet Wettkämpfe, deren gespeicherte WPS-Punkte nicht mehr zur heute automatisch gewählten Version passen.
 *
 * Anlass: Ändert sich "Gültig ab"/"Gültig bis" einer Version, wählt WpsPointVersionResolver für manche Wettkämpfe
 * eine andere Version als beim letzten Berechnen. Die Punkte werden dabei bewusst nicht von selbst neu berechnet —
 * die Liste dient als Hinweis, wo eine Neuberechnung fällig ist.
 *
 * Wettkämpfe mit ausdrücklich zugewiesener Version (meet_point_system.wps_point_version_id) bleiben außen vor:
 * Für sie ändert ein geänderter Gültigkeitszeitraum nichts.
 */
final readonly class WpsVersionImpactService
{
    public function __construct(
        private WpsPointVersionResolver $versionResolver
    ) {}

    /**
     * Wettkämpfe, die mit der Version gerechnet wurden oder heute mit ihr gerechnet würden, und bei denen beides
     * auseinanderfällt.
     *
     * @return Collection<int, array{meet: Meet, stored: Collection<int, WpsPointVersion>, resolved: ?WpsPointVersion}>
     */
    public function affectedMeets(WpsPointVersion $version): Collection
    {
        $meets = Meet::query()
            ->whereHas('pointSystems', static fn ($query) => $query
                ->where('code', PointSystem::CODE_WPS)
                ->whereNull('meet_point_system.wps_point_version_id'))
            ->whereIn('id', Result::query()->whereNotNull('wps_point_version_id')->select('meet_id'))
            ->oldest('start_date')
            ->get();

        $versions = WpsPointVersion::all()->keyBy('id');

        return $meets
            ->map(function (Meet $meet) use ($versions): array {
                $storedIds = Result::query()
                    ->where('meet_id', $meet->id)
                    ->whereNotNull('wps_point_version_id')
                    ->distinct()
                    ->pluck('wps_point_version_id');

                return [
                    'meet' => $meet,
                    'stored' => $storedIds->map(static fn (int $id): ?WpsPointVersion => $versions->get($id))
                        ->filter()
                        ->values(),
                    'resolved' => $this->versionResolver->resolveForMeet($meet),
                ];
            })
            ->filter(static function (array $row) use ($version): bool {
                $storedIds = $row['stored']->pluck('id');
                $resolvedId = $row['resolved']?->id;

                $concernsVersion = $storedIds->contains($version->id) || $resolvedId === $version->id;
                $mismatch = $storedIds->contains(static fn (int $id): bool => $id !== $resolvedId);

                return $concernsVersion && $mismatch;
            })
            ->values();
    }
}
