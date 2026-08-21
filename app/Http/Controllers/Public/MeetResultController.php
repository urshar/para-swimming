<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Meet;
use App\Services\Public\PublicResultService;
use Illuminate\View\View;

class MeetResultController extends Controller
{
    public function __construct(
        private readonly PublicResultService $results,
    ) {}

    /**
     * $locale wird nicht ausgewertet (SetLocale hat die Anwendungssprache bereits gesetzt),
     * muss aber deklariert sein: Ohne einen eigenen Parameter dafür reicht Laravel alle
     * Routenparameter positionsweise an die Methode durch, und $meet bekäme den Sprachstring
     * statt des gebundenen Modells (siehe Public\MeetController).
     *
     * @noinspection PhpUnusedParameterInspection
     */
    public function show(string $locale, Meet $meet): View
    {
        abort_unless($meet->is_published, 404);

        return view('public.meets.results', [
            'meet' => $meet,
            'groups' => $this->results->forMeet($meet),
        ]);
    }
}
