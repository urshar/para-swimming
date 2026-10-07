<?php

namespace App\Http\Controllers;

use App\Models\ImportReviewItem;
use App\Services\RecordImportReviewService;
use App\Support\ListUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Prüfliste nach dem LENEX-Rekordimport: Vereinskonflikte, Zuordnungen mit abweichendem Geburtsdatum und Rekorde von
 * Athleten, deren Nationalität nicht AUT ist (docs/specs/records.md "Prüfliste nach dem Import").
 */
class RecordImportReviewController extends Controller
{
    private const array STATUSES = [
        ImportReviewItem::STATUS_OPEN => 'Offen',
        ImportReviewItem::STATUS_APPLIED => 'Erledigt',
        ImportReviewItem::STATUS_IGNORED => 'Ignoriert',
        'all' => 'Alle',
    ];

    private const array TYPES = [
        'all' => 'Alle Arten',
        ImportReviewItem::TYPE_CLUB_CONFLICT => 'Vereinskonflikte',
        ImportReviewItem::TYPE_YEAR_MATCH => 'Geburtsdatum abweichend',
        ImportReviewItem::TYPE_NATIONALITY => 'Nationalität nicht AUT',
    ];

    public function __construct(
        private readonly RecordImportReviewService $review,
    ) {}

    public function index(Request $request): View
    {
        $status = array_key_exists((string) $request->query('status'), self::STATUSES)
            ? (string) $request->query('status')
            : ImportReviewItem::STATUS_OPEN;
        $type = array_key_exists((string) $request->query('type'), self::TYPES)
            ? (string) $request->query('type')
            : 'all';

        $items = ImportReviewItem::query()
            ->with(['athlete', 'currentClub', 'lenexClub', 'resolvedBy', 'swimRecord:id'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('records.import-review', [
            'items' => $items,
            'status' => $status,
            'type' => $type,
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'openCount' => ImportReviewItem::open()->count(),
            'backUrl' => ListUrl::to('records'),
        ]);
    }

    /**
     * @throws Throwable
     */
    public function apply(Request $request, ImportReviewItem $item): RedirectResponse
    {
        if (! $item->isOpen()) {
            return back()->with('error', 'Dieser Eintrag ist bereits erledigt.');
        }

        if (! $this->review->apply($item, $request->user()->id)) {
            return back()->with('error', 'Der Verein laut Rekord existiert nicht mehr — bitte ignorieren.');
        }

        return back()->with('success', match ($item->type) {
            ImportReviewItem::TYPE_CLUB_CONFLICT => 'Stammverein von '.$item->athlete->display_name.' aktualisiert.',
            ImportReviewItem::TYPE_NATIONALITY => 'Rekord von '.$item->athlete->display_name
                .' entfernt; die Rekord-Historie wurde neu verknüpft.',
            default => 'Zuordnung von '.$item->athlete->display_name.' als geprüft markiert.',
        });
    }

    public function ignore(Request $request, ImportReviewItem $item): RedirectResponse
    {
        if (! $item->isOpen()) {
            return back()->with('error', 'Dieser Eintrag ist bereits erledigt.');
        }

        $this->review->ignore($item, $request->user()->id);

        return back()->with('success', 'Eintrag ignoriert.');
    }

    public function scan(): RedirectResponse
    {
        $created = $this->review->scanExisting();

        return redirect()
            ->route('records.import-review.index')
            ->with('success', match ($created) {
                0 => 'Bestandsprüfung: keine neuen Einträge gefunden.',
                1 => 'Bestandsprüfung: 1 neuer Fall aufgenommen.',
                default => "Bestandsprüfung: $created neue Fälle aufgenommen.",
            });
    }
}
