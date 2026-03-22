<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Services\LenexExportService;
use DOMException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class LenexExportController extends Controller
{
    public function __construct(
        private readonly LenexExportService $exportService
    ) {}

    public function showForm(): View
    {
        $meets = Meet::with('nation')
            ->orderByDesc('start_date')
            ->get();

        return view('lenex.export', compact('meets'));
    }

    /**
     * Export-Typen:
     *   structure → Nur Meet, Sessions, Events
     *   entries   → Structure + Clubs, Athletes, Entries
     *   results   → Structure + Clubs, Athletes, Results (+ Entries falls vorhanden)
     */
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
            return back()->withErrors([
                'export' => 'LENEX Export fehlgeschlagen: '.$e->getMessage(),
            ]);
        }

        $filename = $this->buildFilename($meet, $exportType);

        return response($xml, 200, [
            'Content-Type' => 'application/xml',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    private function buildFilename(Meet $meet, string $type): string
    {
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $meet->name);
        $date = $meet->start_date->format('Y-m-d');

        return $name.'_'.$date.'_'.$type.'.lxf';
    }
}
