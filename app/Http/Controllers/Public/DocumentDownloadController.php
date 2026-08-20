<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Meet;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * DocumentDownloadController — liefert Dokumente aus dem `local`-Disk aus, nie als direkt
 * erreichbare Datei unter `public/` (Spec public-frontend §6). Generisch statt an
 * Veranstaltungen gebunden, weil Phase 8 (Regelmente) denselben Controller für Dokumente ganz
 * ohne Meet-Bezug braucht.
 */
class DocumentDownloadController extends Controller
{
    /**
     * $locale wird nicht ausgewertet (SetLocale hat die Anwendungssprache bereits gesetzt),
     * muss aber deklariert sein: Ohne einen eigenen Parameter dafür reicht Laravel alle
     * Routenparameter positionsweise an die Methode durch, und $document bekäme den
     * Sprachstring statt des gebundenen Modells.
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function show(string $locale, Document $document): Response
    {
        abort_unless($this->isDownloadable($document), 404);

        return Storage::disk('local')->download($document->path, $this->downloadName($document));
    }

    /**
     * Ein Dokument ist herunterladbar, wenn es selbst öffentlich und veröffentlicht ist —
     * und, sofern es einer Veranstaltung gehört, diese zusätzlich veröffentlicht ist. Ohne
     * die zweite Prüfung wäre ein Dokument über seine eigene URL erreichbar, auch wenn die
     * Veranstaltung selbst noch nicht öffentlich ist.
     */
    private function isDownloadable(Document $document): bool
    {
        $publiclyVisible = Document::query()
            ->whereKey($document->id)
            ->public()
            ->published()
            ->exists();

        if (! $publiclyVisible) {
            return false;
        }

        if ($document->documentable instanceof Meet) {
            return $document->documentable->is_published === true;
        }

        return true;
    }

    private function downloadName(Document $document): string
    {
        $extension = pathinfo($document->path, PATHINFO_EXTENSION);

        return $extension === '' ? $document->title : $document->title.'.'.$extension;
    }
}
