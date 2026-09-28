<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\MeetEntryListExportService;
use App\Services\MeetEntryListService;
use App\Services\PdfExportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Exception as SpreadsheetException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * MeetEntryListController
 *
 * Meldebasierte Listen einer Veranstaltung als PDF und Excel:
 *
 *   - Teilnehmerliste     (pro Verein) — Admin bekommt alle Vereine, ein Block/Blatt
 *                          je Verein; ein Vereinsnutzer nur den eigenen Verein.
 *   - Sportpasskontrolle  (über alle Vereine) — nur Admin (Route-Middleware).
 *
 * Rein lesend. Aufbereitung in MeetEntryListService, Excel in
 * MeetEntryListExportService, PDF über die pdf.entry-lists-Views.
 */
class MeetEntryListController extends Controller
{
    public function __construct(
        private readonly MeetEntryListService $lists,
        private readonly MeetEntryListExportService $export,
        private readonly PdfExportService $pdf,
    ) {}

    public function teilnehmerPdf(Request $request, Meet $meet): Response
    {
        return $this->pdf->stream(
            'pdf.entry-lists.teilnehmer',
            [
                'meet' => $meet,
                'byClub' => $this->lists->participantsByClub($meet, $this->scopeClubId($request)),
                'days' => $this->lists->days($meet),
            ],
            $this->pdfName('teilnehmerliste', $meet),
        );
    }

    /**
     * @throws SpreadsheetException
     */
    public function teilnehmerXlsx(Request $request, Meet $meet): BinaryFileResponse
    {
        $byClub = $this->lists->participantsByClub($meet, $this->scopeClubId($request));
        $path = $this->export->teilnehmerXlsx($meet, $byClub);

        return response()
            ->download($path, $this->export->downloadFilename($meet, 'teilnehmerliste'))
            ->deleteFileAfterSend();
    }

    public function nachNamenPdf(Request $request, Meet $meet): Response
    {
        return $this->pdf->stream(
            'pdf.entry-lists.nach-namen',
            [
                'meet' => $meet,
                'sections' => $this->lists->byName($meet, $this->scopeClubId($request)),
            ],
            $this->pdfName('meldeliste-nach-namen', $meet),
        );
    }

    public function nachBewerbenPdf(Request $request, Meet $meet): Response
    {
        // Einzelmeldungen wahlweise ein- oder zweispaltig (Default: zwei Spalten).
        $columns = $request->integer('columns') === 1 ? 1 : 2;
        $clubId = $this->scopeClubId($request);

        return $this->pdf->stream(
            'pdf.entry-lists.nach-bewerben',
            [
                'meet' => $meet,
                'sections' => $this->lists->byEvent($meet, $clubId),
                'courseLabel' => $this->lists->courseLabel($meet->course),
                'columns' => $columns,
                // Verein je Einzelsportler nur für den Admin (alle Vereine) und nur
                // in der einspaltigen Darstellung sinnvoll (die zweispaltige hat keinen Platz).
                'showClub' => $clubId === null,
            ],
            $this->pdfName('meldeliste-nach-bewerben', $meet),
        );
    }

    public function sportpassPdf(Meet $meet): Response
    {
        return $this->pdf->stream(
            'pdf.entry-lists.sportpass',
            [
                'meet' => $meet,
                'participants' => $this->lists->allParticipants($meet),
            ],
            $this->pdfName('sportpasskontrolle', $meet),
        );
    }

    /**
     * @throws SpreadsheetException
     */
    public function sportpassXlsx(Meet $meet): BinaryFileResponse
    {
        $path = $this->export->sportpassXlsx($meet, $this->lists->allParticipants($meet));

        return response()
            ->download($path, $this->export->downloadFilename($meet, 'sportpasskontrolle'))
            ->deleteFileAfterSend();
    }

    /**
     * Vereins-Scope für die Teilnehmerliste: Admin sieht alle Vereine (null),
     * ein Vereinsnutzer nur den eigenen. Ein Nicht-Admin ohne Verein bekommt 0
     * (matcht keinen Verein → leere Liste), damit hier nie versehentlich alle
     * Vereine ausgegeben werden.
     */
    private function scopeClubId(Request $request): ?int
    {
        $user = $request->user();

        if ($user->is_admin) {
            return null;
        }

        return $user->club_id ?? 0;
    }

    private function pdfName(string $kind, Meet $meet): string
    {
        $slug = str($meet->name)->slug()->limit(40, '')->value() ?: 'wettkampf';

        return "$kind-$slug.pdf";
    }
}
