<?php

namespace App\Http\Controllers;

use App\Models\Athlete;
use App\Models\Club;
use App\Models\Cup;
use App\Models\Nation;
use App\Models\QualifyingTimeList;
use App\Services\AthleteMergeService;
use App\Support\ListUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Throwable;

/**
 * Athleten zusammenführen (Dubletten): zweiten Athleten wählen → Vorschau mit Gegenüberstellung, Kollisionen und
 * Auswahl → zusammenführen (AthleteMergeService). Admin-only.
 */
class AthleteMergeController extends Controller
{
    /** Bezeichnungen der Stammdatenfelder (AthleteMergeService::FIELDS) für die Vorschau. */
    private const array FIELD_LABELS = [
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'name_prefix' => 'Namenszusatz',
        'birth_date' => 'Geburtsdatum',
        'gender' => 'Geschlecht',
        'nation_id' => 'Nation',
        'club_id' => 'Verein',
        'license' => 'Lizenznummer',
        'license_ipc' => 'SDMS ID',
        'swrid' => 'swimrankings-ID',
        'status' => 'Status',
        'disability_group' => 'Behinderungsgruppe',
        'disability_subgroup' => 'PI-Untergruppe',
        'is_active' => 'Aktiv',
        'notes' => 'Notizen',
        'email' => 'E-Mail',
        'phone' => 'Telefon',
        'address_street' => 'Straße',
        'address_city' => 'Ort',
        'address_zip' => 'PLZ',
        'address_country' => 'Land',
        'last_medical_check_at' => 'Letzte medizinische Kontrolle',
        'next_medical_check_at' => 'Nächste medizinische Kontrolle',
    ];

    private const array DATE_FIELDS = ['birth_date', 'last_medical_check_at', 'next_medical_check_at'];

    /** Wert von "from", wenn die Vorschau von der Dublettenliste aus geöffnet wurde. */
    private const string FROM_DUPLICATES = 'duplicates';

    public function __construct(
        private readonly AthleteMergeService $mergeService,
    ) {}

    /**
     * GET /athletes/{athlete}/merge — zweiten Athleten wählen.
     */
    public function create(Athlete $athlete): View
    {
        $athletes = Athlete::with('club')
            ->whereKeyNot($athlete->id)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get();

        return view('athletes.merge.create', compact('athlete', 'athletes'));
    }

    /**
     * GET /athletes/{keep}/merge/{drop} — Vorschau.
     */
    public function preview(Request $request, Athlete $keep, Athlete $drop): View
    {
        abort_if($keep->is($drop), 404);

        // Von der Dublettenliste aus führen Zurück, Abbrechen und die Weiterleitung danach wieder dorthin.
        $fromDuplicates = $request->query('from') === self::FROM_DUPLICATES;

        $analysis = $this->mergeService->analyze($keep, $drop);

        $fields = [];
        foreach ($analysis['fields'] as $field => $values) {
            $fields[$field] = [
                'label' => self::FIELD_LABELS[$field] ?? $field,
                'keep' => $this->displayValue($field, $values['keep']),
                'drop' => $this->displayValue($field, $values['drop']),
                'suggestion' => $values['suggestion'],
                'options' => $field === 'notes'
                    ? [AthleteMergeService::KEEP, AthleteMergeService::DROP, AthleteMergeService::BOTH]
                    : [AthleteMergeService::KEEP, AthleteMergeService::DROP],
            ];
        }

        $counts = collect($analysis['counts'])
            ->filter(fn (array $count) => $count['keep'] > 0 || $count['drop'] > 0)
            ->map(fn (array $count, string $table) => [...$count, 'label' => AthleteMergeService::TABLES[$table]]);

        return view('athletes.merge.preview', [
            'keep' => $keep,
            'drop' => $drop,
            'fromDuplicates' => $fromDuplicates,
            'backUrl' => $fromDuplicates ? ListUrl::to('athletes.duplicates') : route('athletes.show', $keep),
            'fields' => $fields,
            // Folgt der Verein aus der Vereins-History, ist er kein Auswahlfeld und zählt nicht als übereinstimmend.
            'identicalFieldCount' => count(AthleteMergeService::FIELDS) - count($fields)
                - ($analysis['club_from_history'] ? 1 : 0),
            'clubFromHistory' => $analysis['club_from_history'],
            'counts' => $counts,
            'blocking' => $analysis['blocking'],
            'sportClasses' => $analysis['sport_classes'],
            'hints' => $analysis['hints'],
            'cups' => Cup::whereKey($analysis['cup_ids'])->orderBy('year')->get(),
            'qualifyingTimeLists' => QualifyingTimeList::whereKey($analysis['qualifying_time_list_ids'])
                ->orderBy('year')->get(),
        ]);
    }

    /**
     * POST /athletes/{keep}/merge/{drop} — zusammenführen; $drop wird danach endgültig gelöscht.
     *
     * @throws Throwable
     */
    public function store(Request $request, Athlete $keep, Athlete $drop): RedirectResponse
    {
        abort_if($keep->is($drop), 404);

        $choices = [AthleteMergeService::KEEP, AthleteMergeService::DROP];
        $validated = $request->validate([
            'fields' => ['array'],
            'fields.*' => [Rule::in([...$choices, AthleteMergeService::BOTH])],
            'sport_classes' => ['array'],
            'sport_classes.*' => [Rule::in($choices)],
        ]);

        $analysis = $this->mergeService->analyze($keep, $drop);
        $fromDuplicates = $request->input('from') === self::FROM_DUPLICATES;
        $previewUrl = route('athletes.merge.preview',
            $fromDuplicates ? [$keep, $drop, 'from' => self::FROM_DUPLICATES] : [$keep, $drop]);

        if ($analysis['blocking'] !== []) {
            return redirect()->to($previewUrl)
                ->with('error', 'Zusammenführen nicht möglich: '.implode(' ', $analysis['blocking']));
        }

        $sportClassChoices = $validated['sport_classes'] ?? [];
        $missing = array_diff(array_keys($analysis['sport_classes']), array_keys($sportClassChoices));
        if ($missing !== []) {
            return redirect()->to($previewUrl)->withInput()->withErrors(collect($missing)
                ->mapWithKeys(fn (string $category) => ["sport_classes.$category" => 'Bitte eine Sportklasse wählen.'])
                ->all());
        }

        $keepName = "#$keep->id $keep->display_name";
        $dropName = "#$drop->id $drop->display_name";

        try {
            $result = $this->mergeService->merge($keep, $drop, $validated['fields'] ?? [], $sportClassChoices,
                $request->boolean('recalculate'));
        } catch (InvalidArgumentException $e) {
            return redirect()->to($previewUrl)->withInput()->withErrors(['fields' => $e->getMessage()]);
        }

        $redirect = redirect()
            ->to($fromDuplicates ? ListUrl::to('athletes.duplicates') : route('athletes.show', $keep))
            ->with('success', $this->successMessage($keepName, $dropName, $result));

        $errors = $result['recalculation']['errors'] ?? [];

        return $errors === [] ? $redirect : $redirect->with('error', 'Neuberechnung: '.implode(' ', $errors));
    }

    private function displayValue(string $field, mixed $value): string
    {
        return match (true) {
            $value === null => '–',
            $field === 'nation_id' => Nation::find($value)?->code ?? (string) $value,
            $field === 'club_id' => Club::find($value)?->display_name ?? (string) $value,
            $field === 'gender' => ['M' => 'männlich', 'F' => 'weiblich', 'N' => 'nicht binär'][$value] ?? $value,
            $field === 'is_active' => $value ? 'ja' : 'nein',
            $field === 'disability_group' => $value.' – '.(Athlete::DISABILITY_GROUPS[$value] ?? $value),
            $field === 'disability_subgroup' => Athlete::DISABILITY_SUBGROUPS[$value] ?? $value,
            in_array($field, self::DATE_FIELDS, true) => Carbon::parse($value)->format('d.m.Y'),
            default => (string) $value,
        };
    }

    /**
     * @param  array{moved: array<string, int>, recalculation: ?array{cups: list<array{id: int, name: string,
     *     stages: list<string>}>, qualifying_time_lists: list<array{id: int, year: int, qualified: int}>,
     *     errors: list<string>}}  $result
     */
    private function successMessage(string $keepName, string $dropName, array $result): string
    {
        $moved = collect($result['moved'])
            ->map(fn (int $count, string $table) => $count.' '.AthleteMergeService::TABLES[$table])
            ->implode(', ');

        $message = "$dropName wurde in $keepName übernommen und gelöscht"
            .($moved !== '' ? " (übernommen: $moved)." : '.');

        $recalculated = collect($result['recalculation']['cups'] ?? [])
            ->map(fn (array $cup) => $cup['name'].' ('.implode(', ', $cup['stages']).')')
            ->merge(collect($result['recalculation']['qualifying_time_lists'] ?? [])
                ->map(fn (array $list) => 'Richtzeitenliste '.$list['year']));

        return $recalculated->isEmpty() ? $message : $message.' Neu berechnet: '.$recalculated->implode(', ').'.';
    }
}
