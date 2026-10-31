<?php

namespace App\Http\Controllers;

use App\Enums\Module;
use App\Models\Club;
use App\Models\Meet;
use App\Services\EntryFeeCalculator;
use App\Services\PdfExportService;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Meldegeld-Abrechnung einer Veranstaltung (Berechnung in EntryFeeCalculator), online und als PDF.
 *
 * Zugriff wie bei den Melde-Listen: Mit Meldungen lesen "alle" sieht man alle Vereine (Übersicht + Detail je
 * Verein), mit "eigener Verein" nur die eigene Abrechnung. Ohne Verein gibt es dann 403.
 */
class EntryFeeController extends Controller
{
    public function __construct(
        private readonly EntryFeeCalculator $calculator,
        private readonly PdfExportService $pdf,
    ) {}

    public function index(Request $request, Meet $meet): View
    {
        $user = $request->user();

        if (! $user->canReadAll(Module::Entries)) {
            abort_unless($user->club_id, 403);

            return $this->clubView($meet, Club::findOrFail($user->club_id));
        }

        $statements = $this->calculator->statements($meet, null);

        return view('meets.fee-statements.index', [
            'meet' => $meet,
            'statements' => $statements,
            'totalCents' => EntryFeeCalculator::total($statements),
        ]);
    }

    public function club(Request $request, Meet $meet, Club $club): View
    {
        $user = $request->user();
        abort_unless($user->canReadAll(Module::Entries) || $user->club_id === $club->id, 403);

        return $this->clubView($meet, $club);
    }

    public function pdf(Request $request, Meet $meet): Response
    {
        $user = $request->user();
        $all = $user->canReadAll(Module::Entries);
        abort_unless($all || $user->club_id, 403);

        $statements = $this->calculator->statements($meet, $all ? null : $user->club_id);

        return $this->pdf->stream(
            'pdf.entry-lists.meldegeld',
            [
                'meet' => $meet,
                'statements' => $statements,
                'totalCents' => EntryFeeCalculator::total($statements),
                'feeSchedule' => $this->calculator->schedule($meet),
                // Gesamtübersicht nur mit Meldungen lesen "alle" (alle Vereine).
                'showOverview' => $all,
            ],
            'meldegeld-'.(str($meet->name)->slug()->limit(40, '')->value() ?: 'wettkampf').'.pdf',
        );
    }

    private function clubView(Meet $meet, Club $club): View
    {
        return view('meets.fee-statements.club', [
            'meet' => $meet,
            'club' => $club,
            'statement' => $this->calculator->statements($meet, $club->id)->first(),
        ]);
    }
}
