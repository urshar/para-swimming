<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Meet;
use App\Services\Public\PublicMeetService;
use App\Services\Public\PublicRecordService;
use App\Services\Public\PublicResultService;
use Illuminate\View\View;

/**
 * Public\HomeController — Startseite (Spec public-frontend §6, Phase 9).
 *
 * Drei Kacheln: nächste Veranstaltung, neue Rekorde, aktuelle Ergebnisse. Zeigt für "aktuelle
 * Ergebnisse" bewusst nur einen Teaser-Link auf die zuletzt vergangene Veranstaltung MIT
 * Ergebnissen (nicht zwangsläufig die chronologisch letzte — eine veröffentlichte Veranstaltung
 * kann noch ohne erfasste Ergebnisse dastehen), keine einzelnen Ergebniszeilen direkt auf der
 * Startseite: Welche Zeilen dort "hervorzuheben" wären, ist willkürlich und nicht spezifiziert.
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly PublicMeetService $meets,
        private readonly PublicRecordService $records,
        private readonly PublicResultService $results,
    ) {}

    public function index(): View
    {
        return view('public.home', [
            'nextMeet' => $this->meets->upcoming(1)->first(),
            'recentRecords' => $this->records->recent(),
            'latestMeetWithResults' => $this->latestMeetWithResults(),
        ]);
    }

    /**
     * Unter den letzten paar vergangenen Veranstaltungen die erste mit tatsächlich erfassten
     * Ergebnissen — kleine, feste Fensterbreite statt einer eigenen Datenbankabfrage: bei einem
     * Verband dieser Größe reichen die letzten 5 vergangenen Veranstaltungen praktisch immer aus,
     * eine eigene "letzte Veranstaltung mit Ergebnissen"-Query wäre unnötiger Aufwand für einen
     * Einzelwert auf der Startseite.
     */
    private function latestMeetWithResults(): ?Meet
    {
        return $this->meets->recentPast(5)
            ->first(fn (Meet $meet): bool => $this->results->hasResults($meet));
    }
}
