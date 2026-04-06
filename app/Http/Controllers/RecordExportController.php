<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\RecordLenexExportService;
use DOMException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use ZipArchive;

/**
 * RecordExportController
 *
 * Export-Flow für LENEX Rekord-Dateien:
 *   GET  /records/export          → showForm()   — Export-Formular
 *   POST /records/export/download → download()   — XML als .lef in .lxf ZIP verpackt
 */
class RecordExportController extends Controller
{
    public function __construct(
        private readonly RecordLenexExportService $exportService
    ) {}

    public function showForm(): View
    {
        return view('records.export', [
            'regionalTypes' => Club::REGIONAL_ASSOCIATIONS,
        ]);
    }

    /**
     * Generiert dem LENEX-XML, verpackt sie als .lef in ein .lxf ZIP und liefert den Download.
     *
     * @throws DOMException
     */
    public function download(Request $request): Response
    {
        $request->validate([
            'category' => 'required|in:national,regional,international,custom',
            'courses' => 'nullable|array',
            'courses.*' => 'in:LCM,SCM,SCY',
            'gender' => 'nullable|in:M,F,',
            'record_types' => 'nullable|array',
        ]);

        $category = $request->input('category');
        $courses = $request->input('courses', []);
        $gender = (string) $request->input('gender', ''); // '' kommt als null an

        $recordTypes = $this->resolveRecordTypes($category, $request);

        $xml = $this->exportService->build($recordTypes, $courses, $gender);
        $basename = $this->buildBasename($category, $courses, $gender);

        $lxfContent = $this->zipToLxf($xml, $basename.'.lef');

        return response($lxfContent, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$basename.'.lxf"',
        ]);
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    /**
     * Verpackt den XML-String als <basename>.lef in ein ZIP und gibt den
     * binären ZIP-Inhalt als String zurück.
     */
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
     * Leitet aus der gewählten Kategorie die konkreten record_type-Werte ab.
     *
     * national      → ['AUT', 'AUT.JR']
     * regional      → alle AUT.XXXX + AUT.XXXX.JR der gewählten Verbände
     * international → ['WR', 'ER', 'OR']
     * custom        → direkt aus dem Formular (record_types[])
     */
    private function resolveRecordTypes(string $category, Request $request): array
    {
        return match ($category) {
            'national' => ['AUT', 'AUT.JR'],
            'international' => ['WR', 'ER', 'OR'],
            'regional' => $this->resolveRegionalTypes($request->input('associations', [])),
            'custom' => array_filter((array) $request->input('record_types', [])),
            default => [],
        };
    }

    /**
     * Baut die record_type-Liste für ausgewählte Regionalverbände.
     * Ohne Auswahl → alle bekannten Verbände.
     *
     * @param  string[]  $associations  z.B. ['WBSV', 'TBSV']
     * @return string[] z.B. ['AUT.WBSV', 'AUT.WBSV.JR', 'AUT.TBSV', 'AUT.TBSV.JR']
     */
    private function resolveRegionalTypes(array $associations): array
    {
        $codes = ! empty($associations)
            ? array_intersect_key(Club::REGIONAL_ASSOCIATIONS, array_flip($associations))
            : Club::REGIONAL_ASSOCIATIONS;

        $types = [];
        foreach (array_keys($codes) as $code) {
            $types[] = 'AUT.'.$code;
            $types[] = 'AUT.'.$code.'.JR';
        }

        return $types;
    }

    /**
     * Generiert einen sprechenden Basis-Dateinamen (ohne Extension).
     *
     * Beispiele:
     *   paraswimming_national_LCM_m_records_20250101
     *   paraswimming_regional_records_20250101
     *   paraswimming_international_SCM_SCY_records_20250101
     */
    private function buildBasename(string $category, array $courses, string $gender): string
    {
        $parts = ['paraswimming', $category];

        if (! empty($courses)) {
            $parts[] = implode('-', $courses);
        }

        if ($gender !== '') {
            $parts[] = strtolower($gender);
        }

        $parts[] = 'records';
        $parts[] = now()->format('Ymd');

        return implode('_', $parts);
    }
}
