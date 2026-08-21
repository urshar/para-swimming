<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Meet;
use App\Services\DocumentService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * DocumentController — Verwaltung von `documents` im Adminbereich (Spec public-frontend §6).
 *
 * Zwei Einstiege auf denselben Bausteinen: Veranstaltungsdokumente über die meet*-Methoden
 * (documentable = Meet), Regelmente & Formulare über die unpräfigierten Methoden
 * (documentable = null). edit()/update()/destroy() sind für beide Fälle gemeinsam — ein
 * bestehendes Dokument trägt seine Zuordnung bereits selbst.
 */
class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents) {}

    // ── Regelmente & Formulare (documentable = null) ──────────────────────────────────

    public function index(): View
    {
        $documents = Document::query()
            ->whereNull('documentable_type')
            ->orderBy('category')->orderBy('sort_order')
            ->get();

        return view('admin.documents.index', ['documents' => $documents, 'meet' => null]);
    }

    public function create(): View
    {
        return $this->form(null);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->persist($request, null);

        return redirect()->route('admin.documents.index')->with('success', 'Dokument hochgeladen.');
    }

    // ── Veranstaltungsdokumente ────────────────────────────────────────────────────────

    public function meetIndex(Meet $meet): View
    {
        $documents = $meet->documents()->orderBy('category')->orderBy('sort_order')->get();

        return view('admin.documents.index', ['documents' => $documents, 'meet' => $meet]);
    }

    public function meetCreate(Meet $meet): View
    {
        return $this->form($meet);
    }

    public function meetStore(Request $request, Meet $meet): RedirectResponse
    {
        $this->persist($request, $meet);

        return redirect()->route('admin.meets.documents.index', $meet)->with('success', 'Dokument hochgeladen.');
    }

    // ── Gemeinsam ──────────────────────────────────────────────────────────────────────

    public function edit(Document $document): View
    {
        return $this->form($document->documentable instanceof Meet ? $document->documentable : null, $document);
    }

    public function update(Request $request, Document $document): RedirectResponse
    {
        $data = $this->validateDocument($request, $document);
        $data['sort_order'] = $this->resolveSortOrder($request, $document->documentable, $data['category']);

        $this->documents->update($document, $data, $request->file('file'));

        return redirect()->to($this->indexUrl($document->documentable))->with('success', 'Dokument aktualisiert.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $redirectUrl = $this->indexUrl($document->documentable);

        $this->documents->delete($document);

        return redirect()->to($redirectUrl)->with('success', 'Dokument gelöscht.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────────────

    private function form(?Meet $meet, ?Document $document = null): View
    {
        return view('admin.documents.form', [
            'meet' => $meet,
            'document' => $document,
            'pairCandidates' => $this->pairCandidates($meet, $document),
        ]);
    }

    private function persist(Request $request, ?Meet $documentable): void
    {
        $data = $this->validateDocument($request);
        $data['sort_order'] = $this->resolveSortOrder($request, $documentable, $data['category']);

        $this->documents->store($data, $request->file('file'), $documentable);
    }

    private function indexUrl(mixed $documentable): string
    {
        return $documentable instanceof Meet
            ? route('admin.meets.documents.index', $documentable)
            : route('admin.documents.index');
    }

    private function validateDocument(Request $request, ?Document $document = null): array
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'required|in:INVITATION,START_LIST,RESULTS,REGULATION,FORM',
            'locale' => 'nullable|in:de,en',
            'file' => ($document !== null ? 'nullable' : 'required').'|file|max:20480|mimes:pdf,doc,docx,xls,xlsx,zip,lxf,lef,xml',
            'published_at' => 'nullable|date',
        ]);

        // Nicht angehakte Checkboxen werden gar nicht übertragen; ohne diese Zeile ließe
        // sich ein Dokument nie wieder auf "nicht öffentlich" zurücknehmen.
        $data['is_public'] = $request->boolean('is_public');

        return $data;
    }

    /**
     * Sprachvarianten teilen sich category + sort_order (§4.1) — das ist der Schlüssel, über
     * den die öffentliche Seite die passende Fassung erkennt und die andere daneben verlinkt.
     * Wählt die Person im Formular eine bestehende Fassung als Sprachvariante aus, übernimmt
     * das neue Dokument deren sort_order; sonst reiht es sich am Ende seiner Kategorie ein.
     */
    private function resolveSortOrder(Request $request, mixed $documentable, string $category): int
    {
        $documentable = $documentable instanceof Model ? $documentable : null;
        $pairWithId = $request->integer('pair_with_document_id') ?: null;

        if ($pairWithId !== null) {
            // Auf dieselbe Zuordnung eingeschränkt: Das Feld ist ein Komfort-Helfer, kein
            // Vertrauensanker — ohne diese Einschränkung ließe sich versehentlich (oder mit
            // manipuliertem Formularwert) die Reihenfolge eines fremden Meets übernehmen.
            $pair = Document::query()
                ->where('documentable_type', $documentable?->getMorphClass())
                ->where('documentable_id', $documentable?->getKey())
                ->find($pairWithId);

            if ($pair !== null) {
                return $pair->sort_order;
            }
        }

        return $this->documents->nextSortOrder($documentable, $category);
    }

    /**
     * Bestehende Dokumente derselben Zuordnung gruppiert nach Kategorie — Grundlage für das
     * "Sprachvariante zu"-Feld im Formular (siehe resources/js/document-form.js).
     *
     * @return Collection<string, Collection<int, array{id: int, label: string}>>
     */
    private function pairCandidates(?Meet $meet, ?Document $exclude): Collection
    {
        return Document::query()
            ->where('documentable_type', $meet?->getMorphClass())
            ->where('documentable_id', $meet?->getKey())
            ->when($exclude !== null, fn (Builder $query): Builder => $query->whereKeyNot($exclude->id))
            ->get()
            ->groupBy('category')
            ->map(fn (Collection $documents): Collection => $documents
                ->map(fn (Document $candidate): array => [
                    'id' => $candidate->id,
                    'label' => $candidate->title.' ('.($candidate->locale ?? 'sprachneutral').')',
                ])
                ->values());
    }
}
