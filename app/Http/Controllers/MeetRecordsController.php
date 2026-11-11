<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\MeetRecordsService;
use Illuminate\View\View;

/**
 * Rekorde einer Veranstaltung (Einzel und Staffel, je Rekordart), nur lesend. Aufgerufen von der Wettkampfseite.
 */
class MeetRecordsController extends Controller
{
    public function __construct(
        private readonly MeetRecordsService $meetRecords,
    ) {}

    public function index(Meet $meet): View
    {
        return view('meets.records', [
            'meet' => $meet,
            'groups' => $this->meetRecords->grouped($meet),
            'statusLabels' => MeetRecordsService::STATUS_LABELS,
            'statusColors' => MeetRecordsService::STATUS_COLORS,
        ]);
    }
}
