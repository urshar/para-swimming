<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Services\PdfExportService;
use App\Services\Public\PublicRecordService;
use App\Services\RecordLenexExportService;
use App\Support\PublicRecordFilter;
use DOMException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * RecordExportController (öffentlich) — Rekord-Download als LENEX oder PDF, Spec §5.2/Phase 5.
 *
 * Schlankere öffentliche Variante des internen RecordExportController: kein Kategorie-/
 * Verbände-Formular, der Filter kommt direkt aus PublicRecordFilter (identisch zur Bildschirm-
 * auswahl, siehe dortiger Kommentar). Nutzt RecordLenexExportService und PdfExportService
 * weiter, ohne die internen Statusfilter.
 *
 * Der LENEX-Export kennt — anders als die Bildschirmliste und der PDF-Export — keine
 * Sportklassen-Eingrenzung: RecordLenexExportService::build() filtert nur nach record_type,
 * Bahn und Geschlecht (unverändert übernommen, siehe Klassenkommentar dort). Eine LENEX-Rekorddatei
 * ist als vollständige Bestenliste eines Verbands für ein Meldeprogramm gedacht,
 * keine Ein-Klassen-Auswahl — die Einschränkung ist also unschädlich, nicht nur hingenommen.
 */
class RecordExportController extends Controller
{
    public function __construct(
        private readonly PublicRecordService $records,
        private readonly RecordLenexExportService $lenexExport,
        private readonly PdfExportService $pdfExport,
    ) {}

    /**
     * @throws DOMException
     */
    public function download(Request $request): Response
    {
        $format = (string) $request->query('format', 'pdf');
        abort_unless(in_array($format, ['lxf', 'pdf'], true), 404);

        $filter = PublicRecordFilter::fromQuery($request->query());

        return $format === 'lxf'
            ? $this->downloadLenex($filter)
            : $this->downloadPdf($filter);
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * @throws DOMException
     */
    private function downloadLenex(PublicRecordFilter $filter): Response
    {
        $courses = $filter->course === '' ? [] : [$filter->course];
        $xml = $this->lenexExport->build([$filter->recordType()], $courses, $filter->gender);

        $basename = $this->buildBasename($filter);
        $lxfContent = $this->zipToLxf($xml, $basename.'.lef');

        return response($lxfContent, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$basename.'.lxf"',
        ]);
    }

    private function downloadPdf(PublicRecordFilter $filter): Response
    {
        $basename = $this->buildBasename($filter);

        return $this->pdfExport->download('pdf.public-records', [
            'filter' => $filter,
            'groups' => $this->records->groupByStroke($this->records->forFilter($filter)),
            'generatedAt' => now(),
        ], $basename.'.pdf');
    }

    /** Verpackt den XML-String als <basename>.lef in ein ZIP und gibt den binären Inhalt zurück. */
    private function zipToLxf(string $xmlContent, string $lefFilename): string
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'lxf_');

        $zip = new ZipArchive;
        $zip->open($tmpZip, ZipArchive::OVERWRITE);
        $zip->addFromString($lefFilename, $xmlContent);
        $zip->close();

        $binary = file_get_contents($tmpZip);
        unlink($tmpZip);

        return $binary;
    }

    /**
     * Sprechender Basis-Dateiname (ohne Extension), z. B.
     * "oebsv_rekorde_national_LCM_m_20260821".
     */
    private function buildBasename(PublicRecordFilter $filter): string
    {
        $parts = ['oebsv', 'rekorde', $filter->isRegional() ? strtolower($filter->association) : 'national'];

        if ($filter->youth) {
            $parts[] = 'jugend';
        }
        if ($filter->course !== '') {
            $parts[] = $filter->course;
        }
        if ($filter->gender !== '') {
            $parts[] = strtolower($filter->gender);
        }

        $parts[] = now()->format('Ymd');

        return implode('_', $parts);
    }
}
