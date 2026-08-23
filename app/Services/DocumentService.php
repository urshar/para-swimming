<?php

namespace App\Services;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * DocumentService — Ablage und Pflege der Dateien hinter `documents` (Spec public-frontend
 * §4.1, §6). Wird sowohl für Veranstaltungsdokumente als auch für Regelmente/Formulare ohne
 * Veranstaltungsbezug verwendet (documentable = null).
 */
final readonly class DocumentService
{
    /**
     * mime_type und size_bytes kommen aus der hochgeladenen Datei selbst, nicht aus
     * Nutzereingaben — sonst ließen sie sich beliebig fälschen, und der öffentliche Linktext
     * ("Ausschreibung, PDF, 240 kB", siehe Document::formatLabel()/sizeLabel()) würde falsche
     * Angaben zeigen.
     */
    public function store(array $data, UploadedFile $file, ?Model $documentable): Document
    {
        return Document::create([
            ...$data,
            'documentable_type' => $documentable?->getMorphClass(),
            'documentable_id' => $documentable?->getKey(),
            'path' => $file->store('documents', 'local'),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
        ]);
    }

    /**
     * Wird eine neue Datei mitgeschickt, ersetzt sie die bestehende — die alte Datei wird von
     * der Disk gelöscht, damit keine verwaisten Dateien liegen bleiben.
     */
    public function update(Document $document, array $data, ?UploadedFile $file): Document
    {
        if ($file !== null) {
            Storage::disk('local')->delete($document->path);

            $data['path'] = $file->store('documents', 'local');
            $data['mime_type'] = $file->getMimeType();
            $data['size_bytes'] = $file->getSize();
        }

        $document->update($data);

        return $document;
    }

    public function delete(Document $document): void
    {
        Storage::disk('local')->delete($document->path);
        $document->delete();
    }

    /**
     * Nächste freie Reihenfolge-Nummer innerhalb einer Kategorie — Grundlage, wenn beim
     * Anlegen keine Sprachvariante ausgewählt wird (siehe DocumentController::resolveSortOrder()).
     */
    public function nextSortOrder(?Model $documentable, string $category): int
    {
        $max = Document::query()
            ->where('documentable_type', $documentable?->getMorphClass())
            ->where('documentable_id', $documentable?->getKey())
            ->where('category', $category)
            ->max('sort_order');

        return ((int) ($max ?? 0)) + 1;
    }
}
