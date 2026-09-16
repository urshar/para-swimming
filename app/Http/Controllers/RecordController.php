<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\BaseTimeSportClass;
use App\Models\Club;
use App\Models\Meet;
use App\Models\Nation;
use App\Models\RecordSplit;
use App\Models\RelayTeamMember;
use App\Models\StrokeType;
use App\Models\SwimRecord;
use App\Services\RecordCheckerService;
use App\Support\TimeParser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class RecordController extends Controller
{
    /**
     * Manuell setzbare Status-Werte für einen aktuellen Rekord (is_current = true). Die
     * ".HISTORY"-Varianten aus dem record_status-Enum sind bewusst ausgenommen — die werden
     * ausschließlich programmatisch beim Überbieten/Wiederherstellen gesetzt
     * (SwimRecord::markAsSupersededBy(), RecordController::restore()), nie manuell.
     */
    private const array STATUS_OPTIONS = [
        'APPROVED' => 'Bestätigt',
        'PENDING' => 'Ausstehend',
        'INVALID' => 'Ungültig',
        'TARGETTIME' => 'Zielzeit',
    ];

    public function __construct(
        private readonly RecordCheckerService $checker
    ) {}

    public function index(Request $request): View
    {
        $category = $request->input('category', 'national');

        // Basis-Typ je Kategorie: International bleibt WR/ER/OR (kennt keine Jugendrekorde).
        // National/Regional zeigen hier nur noch die Region (Österreich bzw. der jeweilige
        // Landesverband) — ob Jugend- oder offene Rekorde gemeint sind, entscheidet das
        // separate Jugend/Offen/Alle-Dropdown weiter unten, nicht mehr eine eigene Options-Zeile
        // je Verband.
        $baseTypes = match ($category) {
            'international' => [
                'WR' => 'Weltrekorde',
                'ER' => 'Europarekorde',
                'OR' => 'Olympische Rekorde',
            ],
            'regional' => $this->buildRegionalTypes(),
            default => ['AUT' => 'Österreich'],
        };

        $defaultBaseType = array_key_first($baseTypes);
        $baseType = $request->input('type', $defaultBaseType);
        if (! array_key_exists($baseType, $baseTypes)) {
            $baseType = $defaultBaseType;
        }

        // Jugend/Offen/Alle — nur für National/Regional relevant. 'ALL' = alle (Jugend + Offen
        // zusammen), 'JR' = nur Jugend, 'OPEN' = nur offene/allgemeine Rekorde (bisheriger
        // Default-Zustand, als es diese Unterscheidung noch nicht gab). Bewusst KEIN value=""
        // für "Alle" — Flux' <flux:select> liest ein leeres value nicht zuverlässig (bekannter
        // Bug, siehe "Combobox-Fix gefunden" oben): beim Absenden kam serverseitig nie ein
        // age_group-Parameter an, das Ergebnis fiel immer auf den OPEN-Default zurück.
        $ageGroup = $category === 'international' ? 'ALL' : $request->input('age_group', 'OPEN');
        if (! in_array($ageGroup, ['ALL', 'JR', 'OPEN'], true)) {
            $ageGroup = 'OPEN';
        }

        // record_type in der DB kodiert Region und Jugend-Status in einem String (z.B.
        // "AUT.WBSV.JR") — bei "Alle" gibt es keinen einzelnen Wert dafür, daher whereIn statt
        // eines exakten Vergleichs.
        $recordTypes = match (true) {
            $ageGroup === 'JR' => ["$baseType.JR"],
            $category === 'international', $ageGroup === 'OPEN' => [$baseType],
            default => [$baseType, "$baseType.JR"],
        };
        $recordTypeLabel = $baseTypes[$baseType];

        // Einzel/Staffel-Filter — Default: Einzel. 'ALL' = alle, 'single' = Einzel, 'relay' =
        // Staffeln. Bewusst KEIN value="" für "Alle" (gleicher Flux-Bug wie beim
        // Jugend/Offen/Alle-Dropdown oben — ein leeres value kommt beim Absenden nie im
        // Request an).
        $relayFilter = $request->input('relay', 'single');
        if (! in_array($relayFilter, ['ALL', 'single', 'relay'], true)) {
            $relayFilter = 'single';
        }

        // Sportklassen-Dropdown: Optionen UND Default hängen vom Einzel/Staffel-Filter ab.
        // Staffeln kennen nur die kombinierten Staffelklassen (RelayClassValidator), Einzel
        // orientiert sich an den in den Basiswerten gepflegten Klassennummern (S1…S19, S21 —
        // je Nummer zusammengefasst über S/SB/SM, da ein Rekord genau eine dieser drei ist).
        [$sportClassOptions, $defaultSportClass] = $this->buildSportClassOptions($relayFilter);

        $sportClass = $request->input('sport_class', $defaultSportClass);
        if ($sportClass !== null && ! $sportClassOptions->has($sportClass)) {
            $sportClass = $defaultSportClass;
        }

        // Bahn — Default: SCM (25m), die in Österreich häufigste Bahnlänge. Ein geleertes
        // clearable-Select kommt dank Laravels ConvertEmptyStringsToNull-Middleware als null
        // im Request an (nicht als '') — das ist ein gültiger "kein Filter"-Zustand und darf
        // nicht auf den Default zurückfallen, nur echte Ausreißerwerte sollen das (gleiches
        // Muster wie $sportClass oben).
        $course = $request->input('course', 'SCM');
        if ($course !== null && ! in_array($course, ['', 'LCM', 'SCM'], true)) {
            $course = 'SCM';
        }

        // Sortierung: Sportklasse → Stil (feste Reihenfolge Freistil/Brust/Rücken/Schmetterling/
        // Lagen, alles andere danach) → Distanz → Geschlecht (Frauen vor Herren). Die
        // Geschlecht-Sortierung greift nur sichtbar, wenn nicht schon auf ein Geschlecht gefiltert
        // ist — bei einem einzelnen Geschlecht im Ergebnis ist sie ein No-op, daher immer aktiv,
        // keine bedingte SQL nötig. Für die Stil-Reihenfolge muss stroke_types gejoint werden
        // (SwimRecord speichert nur die FK, keinen Code) — explizites select() nötig, sonst
        // überschreiben gleichnamige Spalten aus stroke_types (z.B. id) die von swim_records.
        $query = SwimRecord::query()
            ->select('swim_records.*')
            ->join('stroke_types', 'stroke_types.id', '=', 'swim_records.stroke_type_id')
            ->with(['strokeType', 'athlete.nation', 'athlete.club', 'nation', 'club', 'relayTeam', 'meetNation'])
            ->whereIn('swim_records.record_type', $recordTypes)
            ->where('swim_records.is_current', true)
            ->when($relayFilter === 'single', fn ($q) => $q->where('swim_records.relay_count', 1))
            ->when($relayFilter === 'relay', fn ($q) => $q->where('swim_records.relay_count', '>', 1))
            ->orderBy('swim_records.sport_class')
            ->orderByRaw("case stroke_types.lenex_code
                when 'FREE' then 1
                when 'BREAST' then 2
                when 'BACK' then 3
                when 'FLY' then 4
                when 'MEDLEY' then 5
                else 6 end")
            ->orderBy('swim_records.distance')
            ->orderByRaw("case swim_records.gender when 'F' then 1 when 'M' then 2 else 3 end");

        $gender = $request->input('gender');
        if (! in_array($gender, [null, 'M', 'F'], true)) {
            $gender = null;
        }

        // Status — dieselbe null-vs-leer-Behandlung wie bei $course oben.
        $status = $request->input('status');
        if ($status !== null && ! array_key_exists($status, self::STATUS_OPTIONS)) {
            $status = null;
        }

        if ($sportClass) {
            $query->whereIn('swim_records.sport_class', explode(',', $sportClass));
        }
        if ($gender) {
            $query->where('swim_records.gender', $gender);
        }
        if ($course) {
            $query->where('swim_records.course', $course);
        }
        if ($status) {
            $query->where('swim_records.record_status', $status);
        }

        $records = $query->paginate(30)->withQueryString();

        return view('records.index', [
            'records' => $records,
            'category' => $category,
            'baseType' => $baseType,
            'baseTypes' => $baseTypes,
            'ageGroup' => $ageGroup,
            'recordTypeLabel' => $recordTypeLabel,
            'relayFilter' => $relayFilter,
            'sportClassOptions' => $sportClassOptions,
            'sportClass' => $sportClass,
            'gender' => $gender,
            'course' => $course,
            'status' => $status,
            'statusOptions' => self::STATUS_OPTIONS,
        ]);
    }

    /**
     * Status-Schnelländerung aus der Liste heraus, ohne das Bearbeiten-Formular zu öffnen.
     */
    public function updateStatus(Request $request, SwimRecord $record): RedirectResponse
    {
        $validated = $request->validate([
            'record_status' => 'required|in:'.implode(',', array_keys(self::STATUS_OPTIONS)),
        ]);

        $record->update($validated);

        return back()->with('success', 'Status aktualisiert.');
    }

    // ── Rekord-Check eines gesamten Meets ────────────────────────────────────

    public function show(SwimRecord $record): View
    {
        $record->load([
            'strokeType', 'athlete.nation', 'athlete.club', 'nation', 'meetNation', 'club', 'relayTeam', 'result',
            'splits',
        ]);

        $history = $record->getHistoryChain();

        return view('records.show', compact('record', 'history'));
    }

    // ── LENEX Import ──────────────────────────────────────────────────────────

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
            $this->storeRelayMembers($record, $data);
        });

        return redirect()
            ->route('records.show', $record)
            ->with('success', 'Rekord aktualisiert.');
    }

    /**
     * Prüft alle Ergebnisse eines Wettkampfs auf neue Rekorde.
     *
     * Neue und ausstehende Rekorde werden als Liste in der Session gespeichert
     * und in der meets/show View über das Partial records.check-result angezeigt.
     */
    public function checkMeet(Meet $meet): RedirectResponse
    {
        try {
            $result = $this->checker->checkMeet($meet);
        } catch (Throwable $e) {
            return back()->withErrors([
                'check' => 'Rekord-Check fehlgeschlagen: '.$e->getMessage(),
            ]);
        }

        $newCount = count($result['new_records']);
        $pendingCount = count($result['pending_records']);

        $message = $result['checked'].' Ergebnis(se) geprüft';
        if ($newCount > 0) {
            $message .= ', '.$newCount.' neuer '.($newCount === 1 ? 'Rekord' : 'Rekorde');
        }
        if ($pendingCount > 0) {
            $message .= ', '.$pendingCount.' ausstehend (Nationalität unklar)';
        }
        if ($newCount === 0 && $pendingCount === 0) {
            $message .= ' — keine neuen Rekorde';
        }

        // SwimRecord-Objekte können nicht direkt in der Session gespeichert werden.
        // Nur die IDs serialisieren und in der View per eager load nachladen.
        $sessionData = [
            'checked' => $result['checked'],
            'new_record_ids' => collect($result['new_records'])
                ->map(fn ($item) => [
                    'id' => $item['record']->id,
                    'types' => $item['types'],
                ])
                ->all(),
            'pending_record_ids' => collect($result['pending_records'])
                ->map(fn ($item) => [
                    'id' => $item['record']->id,
                    'athlete_name' => $item['athlete_name'],
                ])
                ->all(),
        ];

        return redirect()
            ->route('meets.show', $meet)
            ->with('success', $message)
            ->with('record_check_result', $sessionData);
    }

    public function createManual(): View
    {
        return view('records.form', $this->formData());
    }

    // ── manuell einen Rekord anlegen / bearbeiten ─────────────────────────────

    public function edit(SwimRecord $record): View
    {
        $record->load(['splits', 'relayTeam', 'club', 'athlete.club']);

        // club_id vorausfüllen, wenn leer aber Athlet einen Verein hat
        if (! $record->club_id && $record->athlete?->club_id) {
            $record->club_id = $record->athlete->club_id;
        }

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
            $this->storeRelayMembers($newRecord, $data);
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

    /**
     * Optionen für das Sportklassen-Dropdown auf records/index.
     *
     * Einzel: eine Option je in den Basiswerten gepflegter Klassennummer, zusammengefasst über
     * S/SB/SM (ein SwimRecord hat immer nur eine dieser drei Kategorien) — Wert
     * "S{n},SB{n},SM{n}" (ungepolstert, passend zu den tatsächlich gespeicherten
     * sport_class-Werten), Label "S{n},SB{n},SM{n}" zweistellig gepolstert.
     * Staffel: die sechs kombinierten Staffelklassen aus RelayClassValidator, keine Vorauswahl.
     *
     * @return array{0: Collection<string, string>, 1: ?string}
     */
    private function buildSportClassOptions(string $relayFilter): array
    {
        if ($relayFilter === 'relay') {
            $relayClasses = ['S14', 'S15', 'S20', 'S21', 'S34', 'S49'];

            return [collect($relayClasses)->mapWithKeys(fn ($code) => [$code => $code]), null];
        }

        $numbers = BaseTimeSportClass::query()
            ->pluck('code')
            ->map(fn ($code) => (int) preg_replace('/\D+/', '', $code))
            ->unique()
            ->sort()
            ->values();

        $options = $numbers->mapWithKeys(fn ($n) => [
            "S$n,SB$n,SM$n" => sprintf('S%02d,SB%02d,SM%02d', $n, $n, $n),
        ]);

        return [$options, $options->keys()->first()];
    }

    /**
     * Ein Eintrag je Landesverband — Abkürzung + Bundesland statt des vollen Vereinsnamens, der
     * in der schmalen Dropdown-Spalte abgeschnitten wurde (z.B. "BBSV Burgenland" statt "BBSV –
     * Burgenländischer Behindertensportverband"). Die Jugend/Offen-Unterscheidung läuft seit dem
     * Design-Feedback über das eigene Jugend/Offen/Alle-Dropdown, nicht mehr über eine eigene
     * Options-Zeile je Verband.
     */
    private function buildRegionalTypes(): array
    {
        $types = [];

        foreach (Club::REGIONAL_ASSOCIATIONS as $code => $name) {
            $state = Club::REGIONAL_ASSOCIATION_STATES[$code] ?? $name;
            $types["AUT.$code"] = "$code $state";
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
            'club_id' => 'nullable|exists:clubs,id',
            'swim_time' => ['required', ...$timeRegex],
            'record_status' => 'required|in:APPROVED,PENDING,INVALID,APPROVED.HISTORY,PENDING.HISTORY,TARGETTIME',
            'athlete_id' => 'nullable|exists:athletes,id',
            'nation_id' => 'nullable|exists:nations,id',
            'set_date' => 'nullable|date',
            'meet_name' => 'nullable|string|max:255',
            'meet_city' => 'nullable|string|max:100',
            'meet_nation_id' => 'nullable|exists:nations,id',
            'meet_course' => 'nullable|in:LCM,SCM,SCY,SCM16,SCM20,SCM33,SCY20,SCY27,SCY33,SCY36,OPEN',
            'comment' => 'nullable|string|max:255',
            'splits' => 'nullable|array',
            'splits.*.distance' => 'nullable|integer|min:1',
            'splits.*.split_time' => ['nullable', ...$timeRegex],
            'relay_members' => 'nullable|array',
            'relay_members.*.last_name' => 'nullable|string|max:100',
            'relay_members.*.first_name' => 'nullable|string|max:100',
            'relay_members.*.birth_date' => 'nullable|date',
        ];
    }

    // ── historischen Rekord wiederherstellen ──────────────────────────────────

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

    // ── Private Hilfsmethoden ─────────────────────────────────────────────────

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

    // ── LENEX Export ──────────────────────────────────────────────────────────

    /** Gemeinsame View-Daten für create und edit */
    private function formData(): array
    {
        return [
            'strokeTypes' => StrokeType::active()->standard()->orderBy('name_de')->get(),
            'nations' => Nation::active()->orderBy('code')->get(),
            'athletes' => Athlete::with('club')->orderBy('last_name')->orderBy('first_name')->get(),
            'clubs' => Club::orderBy('name')->get(),
        ];
    }

    /**
     * Staffelmitglieder speichern (löscht bestehende und schreibt neu).
     */
    private function storeRelayMembers(SwimRecord $record, array $data): void
    {
        // Nur bei Staffeln
        if ($record->relay_count <= 1) {
            return;
        }

        $record->relayTeam()->delete();

        foreach ($data['relay_members'] ?? [] as $i => $member) {
            $last = trim($member['last_name'] ?? '');
            $first = trim($member['first_name'] ?? '');
            if (! $last && ! $first) {
                continue;
            }
            RelayTeamMember::create([
                'swim_record_id' => $record->id,
                'position' => $i + 1,
                'last_name' => $last,
                'first_name' => $first,
                'birth_date' => TimeParser::sanitizeDate($member['birth_date'] ?? null),
                'gender' => null,
                'athlete_id' => null,
            ]);
        }
    }
}
