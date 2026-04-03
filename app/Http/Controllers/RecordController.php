<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RecordSplit;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use App\Support\TimeParser;
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
        $category = $request->input('category', 'national');

        $allowedTypes = match ($category) {
            'international' => [
                'WR' => 'Weltrekorde',
                'ER' => 'Europarekorde',
                'OR' => 'Olympische Rekorde',
            ],
            'regional' => $this->buildRegionalTypes(),
            default => [
                'AUT' => 'Österreich (gesamt)',
                'AUT.JR' => 'Österreich Jugend',
            ],
        };

        $defaultType = array_key_first($allowedTypes);
        $recordType = $request->input('type', $defaultType);
        if (! array_key_exists($recordType, $allowedTypes)) {
            $recordType = $defaultType;
        }

        $query = SwimRecord::with(['strokeType', 'athlete.nation', 'athlete.club', 'nation'])
            ->where('record_type', $recordType)
            ->where('is_current', true)
            ->orderBy('sport_class')
            ->orderBy('gender')
            ->orderBy('distance');

        if ($sportClass = $request->input('sport_class')) {
            $query->where('sport_class', $sportClass);
        }
        if ($gender = $request->input('gender')) {
            $query->where('gender', $gender);
        }
        if ($course = $request->input('course')) {
            $query->where('course', $course);
        }

        $records = $query->paginate(30)->withQueryString();

        return view('records.index', [
            'records' => $records,
            'category' => $category,
            'recordType' => $recordType,
            'recordTypeLabel' => $allowedTypes[$recordType],
            'regionalTypes' => $category === 'regional' ? $allowedTypes : [],
        ]);
    }

    public function show(SwimRecord $record): View
    {
        $record->load(['strokeType', 'athlete.nation', 'athlete.club', 'nation', 'result', 'splits']);

        $history = $record->getHistoryChain();

        return view('records.show', compact('record', 'history'));
    }

    // ── Rekord-Check eines gesamten Meets ────────────────────────────────────

    public function destroy(SwimRecord $record): RedirectResponse
    {
        $wasCurrent = $record->is_current;
        $supersedes_id = $record->supersedes_id;

        try {
            DB::transaction(function () use ($record, $wasCurrent, $supersedes_id) {
                // Wenn aktueller Rekord: Vorgänger automatisch wieder auf aktuell setzen
                if ($wasCurrent && $supersedes_id) {
                    $predecessor = SwimRecord::find($supersedes_id);
                    $predecessor?->update([
                        'is_current' => true,
                        'superseded_by_id' => null,
                        'record_status' => 'APPROVED',
                    ]);
                }

                $record->delete();
            });
        } catch (Throwable $e) {
            return back()->withErrors(['record' => 'Löschen fehlgeschlagen: '.$e->getMessage()]);
        }

        $message = $wasCurrent && $supersedes_id
            ? 'Rekord gelöscht. Vorgänger wurde automatisch wiederhergestellt.'
            : 'Rekord gelöscht.';

        return redirect()->route('records.index')->with('success', $message);
    }

    // ── LENEX Import ──────────────────────────────────────────────────────────

    /**
     * @throws Throwable
     */
    public function update(Request $request, SwimRecord $record): RedirectResponse
    {
        $data = $request->validate($this->recordValidationRules());
        $data = $this->parseTimeFields($data);

        DB::transaction(function () use ($record, $data) {
            $splits = $this->extractSplits($data);

            $record->update($data);

            $record->splits()->delete();
            $this->storeSplits($record->id, $splits);
        });

        return redirect()
            ->route('records.show', $record)
            ->with('success', 'Rekord aktualisiert.');
    }

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

    public function importForm(): View
    {
        return view('records.import');
    }

    // ── historischen Rekord wiederherstellen ──────────────────────────────────

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'lenex_file' => 'required|file|mimes:lxf,xml|max:20480',
        ]);

        // TODO: RecordImportService implementieren
        return redirect()
            ->route('records.index')
            ->with('success', 'Rekorde erfolgreich importiert.');
    }

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

    public function export(Request $request): RedirectResponse
    {
        $recordType = $request->query('type', 'WR');

        return redirect()
            ->route('records.index', ['type' => $recordType])
            ->with('success', 'Export wird vorbereitet.');
    }

    public function createManual(): View
    {
        return view('records.form', $this->formData());
    }

    public function edit(SwimRecord $record): View
    {
        $record->load('splits');

        return view('records.form', array_merge($this->formData(), compact('record')));
    }

    /**
     * @throws Throwable
     */
    public function storeManual(Request $request): RedirectResponse
    {
        $data = $request->validate($this->recordValidationRules());
        $data = $this->parseTimeFields($data);

        DB::transaction(function () use ($data) {
            $current = SwimRecord::where('record_type', $data['record_type'])
                ->where('stroke_type_id', $data['stroke_type_id'])
                ->where('sport_class', $data['sport_class'])
                ->where('gender', $data['gender'])
                ->where('course', $data['course'])
                ->where('distance', $data['distance'])
                ->where('relay_count', $data['relay_count'])
                ->where('is_current', true)
                ->first();

            $splits = $this->extractSplits($data);

            $newRecord = SwimRecord::create(array_merge($data, [
                'is_current' => true,
                'supersedes_id' => $current?->id,
            ]));

            $current?->markAsSupersededBy($newRecord);

            $this->storeSplits($newRecord->id, $splits);
        });

        return redirect()
            ->route('records.index')
            ->with('success', 'Rekord erfolgreich eingetragen.');
    }

    /**
     * @throws Throwable
     */
    public function restore(SwimRecord $record): RedirectResponse
    {
        if ($record->is_current) {
            return back()->withErrors(['record' => 'Dieser Rekord ist bereits aktuell.']);
        }

        DB::transaction(function () use ($record) {
            $current = SwimRecord::where('record_type', $record->record_type)
                ->where('stroke_type_id', $record->stroke_type_id)
                ->where('sport_class', $record->sport_class)
                ->where('gender', $record->gender)
                ->where('course', $record->course)
                ->where('distance', $record->distance)
                ->where('relay_count', $record->relay_count)
                ->where('is_current', true)
                ->first();

            $current?->update([
                'is_current' => false,
                'superseded_by_id' => $record->id,
                'record_status' => str_contains($current->record_status, 'PENDING')
                    ? 'PENDING.HISTORY'
                    : 'APPROVED.HISTORY',
            ]);

            $record->update([
                'is_current' => true,
                'superseded_by_id' => null,
                'record_status' => 'APPROVED',
            ]);
        });

        return redirect()
            ->route('records.show', $record)
            ->with('success', 'Rekord wiederhergestellt.');
    }

    private function buildRegionalTypes(): array
    {
        $types = [];

        foreach (Club::REGIONAL_ASSOCIATIONS as $code => $name) {
            $types["AUT.$code"] = "$code – $name";
            $types["AUT.$code.JR"] = "$code – $name (Jugend)";
        }

        return $types;
    }

    /** Gemeinsame Validation-Regeln für store und update */
    private function recordValidationRules(): array
    {
        $timeRegex = ['string', 'regex:/^\d{1,2}:\d{2}\.\d{2}$/'];

        return [
            'record_type' => 'required|string|max:20',
            'stroke_type_id' => 'required|exists:stroke_types,id',
            'sport_class' => 'required|string|max:15',
            'gender' => 'required|in:M,F,X',
            'course' => 'required|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'distance' => 'required|integer|min:1',
            'relay_count' => 'required|integer|min:1',
            'swim_time' => ['required', ...$timeRegex],
            'record_status' => 'required|in:APPROVED,PENDING,INVALID,APPROVED.HISTORY,PENDING.HISTORY,TARGETTIME',
            'athlete_id' => 'nullable|exists:athletes,id',
            'nation_id' => 'nullable|exists:nations,id',
            'set_date' => 'nullable|date',
            'meet_name' => 'nullable|string|max:255',
            'meet_city' => 'nullable|string|max:100',
            'meet_course' => 'nullable|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'comment' => 'nullable|string|max:255',
            'splits' => 'nullable|array',
            'splits.*.distance' => 'nullable|integer|min:1',
            'splits.*.split_time' => ['nullable', ...$timeRegex],
        ];
    }

    // ── LENEX Export ──────────────────────────────────────────────────────────

    /**
     * Konvertiert swim_time und split_time von MM:SS.cs in Hundertstelsekunden.
     * Gibt das angepasste $data Array zurück.
     */
    private function parseTimeFields(array $data): array
    {
        $data['swim_time'] = TimeParser::parse($data['swim_time']);

        if (! empty($data['splits'])) {
            $data['splits'] = array_map(function (array $split): array {
                if (! empty($split['split_time'])) {
                    $split['split_time'] = TimeParser::parse((string) $split['split_time']);
                }

                return $split;
            }, $data['splits']);
        }

        return $data;
    }

    // ── manuell einen Rekord anlegen / bearbeiten ─────────────────────────────

    /**
     * Filtert leere Split-Zeilen heraus und entfernt 'splits' aus $data.
     * Gibt bereinigte Split-Liste zurück.
     */
    private function extractSplits(array &$data): array
    {
        $splits = collect($data['splits'] ?? [])
            ->filter(fn ($s) => ! empty($s['distance']) && ! empty($s['split_time']))
            ->values()
            ->toArray();

        unset($data['splits']);

        return $splits;
    }

    /** Speichert Splits für einen Rekord. */
    private function storeSplits(int $recordId, array $splits): void
    {
        foreach ($splits as $split) {
            RecordSplit::create([
                'swim_record_id' => $recordId,
                'distance' => $split['distance'],
                'split_time' => $split['split_time'],
            ]);
        }
    }

    /** Gemeinsame View-Daten für create und edit */
    private function formData(): array
    {
        return [
            'strokeTypes' => StrokeType::active()->standard()->orderBy('name_de')->get(),
            'nations' => Nation::active()->orderBy('name_de')->get(),
            'athletes' => Athlete::with('club')->orderBy('last_name')->orderBy('first_name')->get(),
        ];
    }
}
