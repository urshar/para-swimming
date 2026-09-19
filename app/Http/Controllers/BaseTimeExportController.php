<?php

namespace App\Http\Controllers;

use App\Models\BaseTimeCategory;
use App\Models\BaseTimeVersion;
use App\Services\BaseTimeExportService;
use App\Services\BaseTimeTextExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BaseTimeExportController extends Controller
{
    public function __construct(
        private readonly BaseTimeExportService $exportService,
        private readonly BaseTimeTextExportService $textExportService,
    ) {}

    /** World-Aquatics-Excel-Export (.xlsx), gesamte Version — aus der Kategorien-Übersicht. */
    public function export(BaseTimeVersion $version): BinaryFileResponse
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');

        $path = $this->exportService->export($version);

        return response()
            ->download($path, $this->exportService->downloadFilename($version))
            ->deleteFileAfterSend();
    }

    /** MeetManager/Hy-Tek-"Points"-Text-Export (.txt), gesamte Version — aus der Kategorien-Übersicht. */
    public function exportText(BaseTimeVersion $version): BinaryFileResponse
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');

        $path = $this->textExportService->export($version);

        return response()
            ->download($path, $this->textExportService->downloadFilename($version))
            ->deleteFileAfterSend();
    }

    /** Excel-Export nur der angezeigten Kategorie — aus der Kategorie-Detailansicht. */
    public function categoryExport(BaseTimeVersion $version, BaseTimeCategory $category): BinaryFileResponse
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');

        $path = $this->exportService->export($version, $category);

        return response()
            ->download($path, $this->exportService->downloadFilename($version, $category))
            ->deleteFileAfterSend();
    }

    /** Text-Export nur der angezeigten Kategorie — aus der Kategorie-Detailansicht. */
    public function categoryExportText(BaseTimeVersion $version, BaseTimeCategory $category): BinaryFileResponse
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');

        $path = $this->textExportService->export($version, $category);

        return response()
            ->download($path, $this->textExportService->downloadFilename($version, $category))
            ->deleteFileAfterSend();
    }
}
