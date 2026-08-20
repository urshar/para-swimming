<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Meet;
use App\Services\Public\PublicMeetService;
use App\Support\MeetDocumentGroup;
use Illuminate\View\View;

class MeetController extends Controller
{
    public function __construct(
        private readonly PublicMeetService $meets,
    ) {}

    public function index(): View
    {
        return view('public.meets.index', [
            'upcoming' => $this->meets->upcoming(),
            'recentPast' => $this->meets->recentPast(),
        ]);
    }

    /** Alle vergangenen Veranstaltungen gruppiert nach Jahr (§5.1). */
    public function archive(): View
    {
        return view('public.meets.archive', [
            'meetsByYear' => $this->meets->archiveGroupedByYear(),
        ]);
    }

    /**
     * $locale wird nicht ausgewertet (SetLocale hat die Anwendungssprache bereits gesetzt),
     * muss aber deklariert sein: Ohne einen eigenen Parameter dafür reicht Laravel alle
     * Routenparameter positionsweise an die Methode durch, und $meet bekäme den Sprachstring
     * statt des gebundenen Modells.
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function show(string $locale, Meet $meet): View
    {
        abort_unless($meet->is_published, 404);

        $meet->load('nation');

        return view('public.meets.show', [
            'meet' => $meet,
            'documents' => MeetDocumentGroup::forMeet($meet, app()->getLocale()),
        ]);
    }
}
