<?php

namespace App\Http\Controllers;

use App\Models\BaseTimeVersion;
use App\Services\BaseTimeExportService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BaseTimeExportController extends Controller
{
    public function __construct(
        private readonly BaseTimeExportService $exportService,
    ) {}

    public function export(BaseTimeVersion $version): BinaryFileResponse
    {
        abort_unless(auth()->user()?->is_admin, 403, 'Nur für Administratoren.');

        $path = $this->exportService->export($version);

        return response()
            ->download($path, $this->exportService->downloadFilename($version))
            ->deleteFileAfterSend();
    }
}
