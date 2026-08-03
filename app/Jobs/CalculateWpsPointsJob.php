<?php

namespace App\Jobs;

use App\Models\Meet;
use App\Models\WpsPointVersion;
use App\Services\WpsPointCalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Berechnet die WPS-Punkte eines Wettkampfs im Hintergrund.
 *
 * Wird nur für große Wettkämpfe verwendet (siehe config/wps.php); kleinere rechnet der
 * Controller synchron, damit der Benutzer die Zusammenfassung sofort sieht.
 *
 * Es werden bewusst nur IDs serialisiert statt der Modelle selbst: der Job kann Minuten
 * nach dem Auslösen laufen, und bis dahin können sich Meet oder Version geändert haben.
 */
class CalculateWpsPointsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $meetId,
        private readonly ?int $versionId = null,
        private readonly bool $onlyMissing = false,
    ) {}

    public function handle(WpsPointCalculationService $service): void
    {
        $meet = Meet::find($this->meetId);

        if ($meet === null) {
            return;
        }

        $version = $this->versionId !== null
            ? WpsPointVersion::find($this->versionId)
            : null;

        $service->recalculateForMeet($meet, $version, $this->onlyMissing);
    }
}
