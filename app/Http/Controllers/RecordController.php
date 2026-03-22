<?php

namespace App\Http\Controllers;

use App\Models\Meet;
use App\Models\Nation;
use App\Models\RecordSplit;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class RecordController extends Controller
{
    public function __construct(
        private readonly RecordCheckerService $checker
    ) {}

    public function index(Request $request): View
    {
        $recordType = $request->query('type', 'WR');

        $query = SwimRecord::with(['strokeType', 'athlete.nation', 'nation'])
            ->where('record_type', $recordType)
            ->where('is_current', true)
            ->orderBy('sport_class')
            ->orderBy('gender')
            ->orderBy('distance');

        if ($sportClass = $request->query('sport_class')) {
            $query->where('sport_class', $sportClass);
        }

        if ($gender = $request->query('gender')) {
            $query->where('gender', $gender);
        }

        if ($course = $request->query('course')) {
            $query->where('course', $course);
        }

        $records = $query->paginate(30)->withQueryString();

        return view('records.index', compact('records', 'recordType'));
    }

    public function show(SwimRecord $record): View
    {
        $record->load(['strokeType', 'athlete.nation', 'nation', 'result', 'splits']);

        // Vollständige Historie laden
        $history = $record->getHistoryChain();

        return view('records.show', compact('record', 'history'));
    }

    public function destroy(SwimRecord $record): RedirectResponse
    {
        // Nur historische Einträge können gelöscht werden
        if ($record->is_current) {
            return back()->withErrors([
                'record' => 'Aktuelle Rekorde können nicht gelöscht werden. Bitte zuerst einen neuen Rekord eintragen.',
            ]);
        }

        $record->delete();

        return back()->with('success', 'Historischer Rekord gelöscht.');
    }

    // ── Rekord-Check eines gesamten Meets ────────────────────────────────────

    public function checkMeet(Meet $meet): RedirectResponse
    {
        try {
            $checked = $this->checker->checkMeetResults($meet);
        } catch (Throwable $e) {
            return back()->withErrors([
                'check' => 'Rekord-Check fehlgeschlagen: '.$e->getMessage(),
            ]);
        }

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', $checked.' Ergebnis(se) auf Rekorde geprüft.');
    }

    // ── LENEX Import ──────────────────────────────────────────────────────────

    public function importForm(): View
    {
        return view('records.import');
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'lenex_file' => 'required|file|mimes:lxf,xml|max:20480',
        ]);

        // TODO: RecordImportService implementieren
        // $request->file('lenex_file') wird dann an den Service übergeben
        return redirect()
            ->route('records.index')
            ->with('success', 'Rekorde erfolgreich importiert.');
    }

    // ── LENEX Export ──────────────────────────────────────────────────────────

    public function export(Request $request): RedirectResponse
    {
        $recordType = $request->query('type', 'WR');

        // Export-Logik kommt im LenexExportController / RecordExportService
        return redirect()
            ->route('records.index', ['type' => $recordType])
            ->with('success', 'Export wird vorbereitet.');
    }

    // ── Manuell einen Rekord anlegen ─────────────────────────────────────────

    public function createManual(): View
    {
        $strokeTypes = StrokeType::active()->standard()->orderBy('name_de')->get();
        $nations = Nation::active()->orderBy('name_de')->get();

        return view('records.form', compact('strokeTypes', 'nations'));
    }

    /**
     * @throws Throwable
     */
    public function storeManual(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'record_type' => 'required|string|max:20',
            'stroke_type_id' => 'required|exists:stroke_types,id',
            'sport_class' => 'required|string|max:15',
            'gender' => 'required|in:M,F,X',
            'course' => 'required|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'distance' => 'required|integer|min:1',
            'relay_count' => 'required|integer|min:1',
            'swim_time' => 'required|integer|min:0',
            'record_status' => 'required|in:APPROVED,PENDING,INVALID,APPROVED.HISTORY,PENDING.HISTORY,TARGETTIME',
            'athlete_id' => 'nullable|exists:athletes,id',
            'nation_id' => 'nullable|exists:nations,id',
            'set_date' => 'nullable|date',
            'meet_name' => 'nullable|string|max:255',
            'meet_city' => 'nullable|string|max:100',
            'meet_course' => 'nullable|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'comment' => 'nullable|string|max:255',

            'splits' => 'nullable|array',
            'splits.*.distance' => 'required_with:splits.*|integer|min:1',
            'splits.*.split_time' => 'required_with:splits.*|integer|min:0',
        ]);

        DB::transaction(function () use ($data) {
            // Bisherigen aktuellen Rekord suchen
            $current = SwimRecord::where('record_type', $data['record_type'])
                ->where('stroke_type_id', $data['stroke_type_id'])
                ->where('sport_class', $data['sport_class'])
                ->where('gender', $data['gender'])
                ->where('course', $data['course'])
                ->where('distance', $data['distance'])
                ->where('relay_count', $data['relay_count'])
                ->where('is_current', true)
                ->first();

            $splits = $data['splits'] ?? [];
            unset($data['splits']);

            $newRecord = SwimRecord::create(array_merge($data, [
                'is_current' => true,
                'supersedes_id' => $current?->id,
            ]));

            // Bisherigen Rekord auf historisch setzen
            $current?->markAsSupersededBy($newRecord);

            // Splits speichern
            foreach ($splits as $split) {
                RecordSplit::create([
                    'swim_record_id' => $newRecord->id,
                    'distance' => $split['distance'],
                    'split_time' => $split['split_time'],
                ]);
            }
        });

        return redirect()
            ->route('records.index')
            ->with('success', 'Rekord erfolgreich eingetragen.');
    }
}
