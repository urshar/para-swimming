<?php

namespace App\Http\Controllers;

use App\Models\ImportReviewItem;
use App\Models\SwimRecord;
use App\Services\RecordChainService;
use App\Services\RecordImportReviewService;
use App\Support\ListUrl;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

/**
 * Prüfliste Rekorde, Reiter "Import-Befunde": Prüfliste nach dem LENEX-Rekordimport mit Vereinskonflikten,
 * Zuordnungen mit abweichendem Geburtsdatum und Rekorden von Athleten, deren Nationalität nicht AUT ist,
 * Regionalrekorden mit falschem Verband, Abweichungen zur importierten Rekordliste und Staffelrekorden ohne Verein
 * (docs/specs/records.md "Prüfliste nach dem Import"). Reiter "Ausstehende Rekorde": alle Rekorde mit Status
 * PENDING zum Bestätigen oder Verwerfen.
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
        ImportReviewItem::TYPE_REGIONAL => 'Regionalrekord: falscher Verband',
        ImportReviewItem::TYPE_LIST_MISMATCH => 'Abweichung zur Rekordliste',
        ImportReviewItem::TYPE_RELAY_NO_CLUB => 'Staffelrekord ohne Verein',
        ImportReviewItem::TYPE_UNKNOWN_NATION => 'Nationalität unbekannt',
    ];

    private const array PENDING_REASONS = [
        'all' => 'Alle Gründe',
        SwimRecord::PENDING_REASON_EXHIBITION => 'Außer Konkurrenz',
        SwimRecord::PENDING_REASON_SPLIT => 'Zwischenzeit',
        SwimRecord::PENDING_REASON_CLUB => 'Vereinszugehörigkeit',
        SwimRecord::PENDING_REASON_OTHER => 'Sonstige',
    ];

    private const array PENDING_KINDS = [
        'all' => 'Einzel und Staffel',
        'single' => 'Einzel',
        'relay' => 'Staffel',
    ];

    public function __construct(
        private readonly RecordImportReviewService $review,
        private readonly RecordChainService $chain,
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
            ->with(['athlete', 'currentClub', 'lenexClub', 'resolvedBy', 'swimRecord'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // Offene Staffeln ohne Verein: passendes Staffelergebnis als Vorschlag (item_id => RelayResult).
        $candidates = collect($items->items())
            ->filter(fn (ImportReviewItem $item) => $item->isOpen() && $item->swimRecord !== null
                && $item->type === ImportReviewItem::TYPE_RELAY_NO_CLUB)
            ->mapWithKeys(fn (ImportReviewItem $item) => [$item->id => $this->review->candidateRelayResult($item->swimRecord)])
            ->filter();

        return view('records.import-review', [
            'items' => $items,
            'candidates' => $candidates,
            'status' => $status,
            'type' => $type,
            'statuses' => self::STATUSES,
            'types' => self::TYPES,
            'openCount' => ImportReviewItem::open()->count(),
            'pendingCount' => SwimRecord::pending()->count(),
            'backUrl' => ListUrl::to('records'),
        ]);
    }

    /**
     * Reiter "Ausstehende Rekorde": alle Rekordtypen, Klassen und Bahnen auf einen Blick, jüngste zuerst. Bestätigen
     * und Verwerfen laufen über records.status.update (ordnet einen bestätigten Rekord in die Kette ein). Zu jedem
     * Rekord steht der an seinem Datum geltende Rekord — der Maßstab, an dem ihn "Bestätigen" misst.
     */
    public function pending(Request $request): View
    {
        $reason = array_key_exists((string) $request->query('reason'), self::PENDING_REASONS)
            ? (string) $request->query('reason')
            : 'all';
        $kind = array_key_exists((string) $request->query('kind'), self::PENDING_KINDS)
            ? (string) $request->query('kind')
            : 'all';

        $records = SwimRecord::pending()
            ->with(['strokeType', 'athlete.nation', 'athlete.club', 'club', 'relayTeam.athlete', 'result', 'relayResult.club'])
            ->pendingReason($reason)
            ->when($kind === 'single', fn ($q) => $q->where('relay_count', 1))
            ->when($kind === 'relay', fn ($q) => $q->where('relay_count', '>', 1))
            ->orderByDesc('set_date')
            ->orderBy('record_type')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $previous = $records->getCollection()->mapWithKeys(fn (SwimRecord $record): array => [
            $record->id => $this->chain->validOn($record, $record->set_date),
        ]);
        (new EloquentCollection($previous->filter()->values()))->load(['athlete.nation', 'club', 'relayResult.club']);

        return view('records.review-pending', [
            'records' => $records,
            'previous' => $previous,
            'reason' => $reason,
            'kind' => $kind,
            'reasons' => self::PENDING_REASONS,
            'kinds' => self::PENDING_KINDS,
            'openCount' => ImportReviewItem::open()->count(),
            'pendingCount' => SwimRecord::pending()->count(),
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

        $label = $item->details['label'] ?? 'Rekord';
        // Einzelrekord: "von <Athlet>"; Staffel (Eintrag ohne bzw. mit Mitglied als Athlet): die Rekordbezeichnung.
        $holder = $item->athlete !== null && empty($item->details['members'])
            ? 'von '.$item->athlete->display_name
            : '"'.$label.'"';

        return back()->with('success', match ($item->type) {
            ImportReviewItem::TYPE_CLUB_CONFLICT => 'Stammverein von '.$item->athlete->display_name.' aktualisiert.',
            ImportReviewItem::TYPE_NATIONALITY => 'Rekord '.$holder.' entfernt; die Rekord-Historie wurde neu verknüpft.',
            ImportReviewItem::TYPE_REGIONAL => 'Regionalrekord '.$holder
                .' entfernt. Für den richtigen Regionalrekord die Rekordprüfung des Wettkampfs erneut starten.',
            ImportReviewItem::TYPE_LIST_MISMATCH => $label.': Rekordliste übernommen, die Rekord-Historie wurde neu verknüpft.',
            ImportReviewItem::TYPE_UNKNOWN_NATION => $label.': als Rekord übernommen, die Rekord-Historie wurde neu verknüpft.',
            ImportReviewItem::TYPE_RELAY_NO_CLUB => isset($item->details['linked_relay_result_id'])
                ? $label.': mit dem Staffelergebnis verknüpft (Verein und Mitglieder übernommen).'
                : $label.': als geprüft markiert.',
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

    /**
     * Löscht die erledigten Einträge. Ignorierte bleiben als Merker, damit Prüflauf und Import sie nicht neu anlegen.
     */
    public function purge(): RedirectResponse
    {
        // Der Query-Builder liefert die Anzahl gelöschter Zeilen; laut Docblock nur "mixed" — deshalb der Cast.
        $deleted = (int) ImportReviewItem::where('status', ImportReviewItem::STATUS_APPLIED)->delete();

        return redirect()
            ->route('records.import-review.index')
            ->with('success', match ($deleted) {
                0 => 'Keine erledigten Einträge vorhanden.',
                1 => '1 erledigter Eintrag gelöscht.',
                default => "$deleted erledigte Einträge gelöscht.",
            });
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
