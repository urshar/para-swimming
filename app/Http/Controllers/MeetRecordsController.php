<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\MeetRecordsService;
use App\Services\PdfExportService;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rekorde einer Veranstaltung (Einzel und Staffel, je Rekordart), nur lesend. Aufgerufen von der Wettkampfseite;
 * dieselbe Liste auch als PDF.
 */
class MeetRecordsController extends Controller
{
    public function __construct(
        private readonly MeetRecordsService $meetRecords,
        private readonly PdfExportService $pdfExportService,
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

    public function pdf(Meet $meet): Response
    {
        return $this->pdfExportService->stream('pdf.meet-records', [
            'meet' => $meet,
            'groups' => $this->meetRecords->grouped($meet),
            'statusLabels' => MeetRecordsService::STATUS_LABELS,
            'generatedAt' => now(),
        ], "rekorde-veranstaltung-$meet->id.pdf");
    }
}
