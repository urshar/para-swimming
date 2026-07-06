<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Meet;
use App\Services\LenexExportService;
use DOMException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use ZipArchive;

class LenexExportController extends Controller
{
    public function __construct(
        private readonly LenexExportService $exportService
    ) {}

    public function showForm(): View
    {
        $meets = Meet::with('nation')->orderByDesc('start_date')->get();

        return view('lenex.export', [
            'meets' => $meets,
            'regionalTypes' => Club::REGIONAL_ASSOCIATIONS,
        ]);
    }

    public function download(Request $request): Response|RedirectResponse
    {
        $request->validate([
            'meet_id' => 'required|exists:meets,id',
            'export_type' => 'required|in:structure,entries,results',
        ]);

        $meet = Meet::findOrFail($request->input('meet_id'));
        $exportType = $request->input('export_type');

        try {
            $xml = $this->exportService->build($meet, $exportType);
        } catch (DOMException $e) {
            return back()->withErrors(['export' => 'LENEX Export fehlgeschlagen: '.$e->getMessage()]);
        }

        $innerFilename = $this->buildInnerFilename($meet, $exportType);
        $tmpFile = tempnam(sys_get_temp_dir(), 'lxf_');

        $zip = new ZipArchive;
        $zip->open($tmpFile, ZipArchive::OVERWRITE);
        $zip->addFromString($innerFilename, $xml);
        $zip->close();

        $zipContent = file_get_contents($tmpFile);
        unlink($tmpFile);

        return response($zipContent, 200, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$this->buildFilename($meet, $exportType).'"',
        ]);
    }

    private function buildFilename(Meet $meet, string $type): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $meet->name)
            .'_'.$meet->start_date->format('Y-m-d').'_'.$type.'.lxf';
    }

    private function buildInnerFilename(Meet $meet, string $type): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $meet->name)
            .'_'.$meet->start_date->format('Y-m-d').'_'.$type.'.lef';
    }
}
