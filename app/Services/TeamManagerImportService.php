<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\AthleteClassification;
use App\Models\AthleteClubHistory;
use App\Models\AthleteLevelHistory;
use App\Models\Classifier;
use App\Models\Club;
use App\Models\ExceptionCode;
use App\Models\Nation;
use App\Support\TeamManagerImportPreview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Einmalige Übernahme von Vereinen und Athleten aus dem Splash Team Manager.
 *
 * Zwei Schritte wie bei den anderen Importen: preview() bereitet die Rohzeilen der Quelle auf und schreibt nichts,
 * import() legt an bzw. aktualisiert. Vorhandene Vereine werden über den Code erkannt (dabei nur Typ und
 * Landesverband gesetzt), vorhandene Athleten über die Lizenznummer oder Name + Geburtsdatum (dann mit den Werten
 * aus der Datei überschrieben) — ein zweiter Lauf legt also nichts doppelt an.
 */
final readonly class TeamManagerImportService
{
    /** Landessportclub-Kürzel des Team Managers → Landesverband. */
    private const array LSC_TO_ASSOCIATION = [
        'BLSV' => 'BBSV',
        'KLSV' => 'KBSV',
        'NOELSV' => 'NOEVSV',
        'OOELSV' => 'OOEBSV',
        'SLSV' => 'SBSV',
        'STLSV' => 'STBSV',
        'TLSV' => 'TBSV',
        'VLSV' => 'VBSV',
        'WLSV' => 'WBSV',
    ];

    /** Code des Bundesverbands im Team Manager; bei uns heißt er "ÖBSV". */
    private const string NATIONAL_FEDERATION_CODE = 'AUT';

    /** Feld "Gruppen" → [Behinderungsgruppe, PI-Untergruppe]. */
    private const array GROUP_MAP = [
        'PI' => ['PI', null],
        'PIA' => ['PI', 'A'],
        'PIC' => ['PI', 'C'],
        'PIR' => ['PI', 'R'],
        'VI' => ['VI', null],
        'MI' => ['MI', null],
        'HI' => ['HI', null],
        'T21' => ['T21', null],
    ];

    /** Feld "Ausbildung (Richter)" → [Behinderungsgruppe, PI-Untergruppe]; füllt Lücken in "Gruppen". */
    private const array GRADE_MAP = [
        'PI' => ['PI', null],
        'PI-A' => ['PI', 'A'],
        'PI-C' => ['PI', 'C'],
        'PI-R' => ['PI', 'R'],
        'VI' => ['VI', null],
        'MI' => ['MI', null],
        'II' => ['MI', null],
        'HI' => ['HI', null],
        'DI' => ['HI', null],
        'T21' => ['T21', null],
        'II-DS' => ['T21', null],
        'DS' => ['T21', null],
    ];

    /** Übernommen wird nur die ÖBSV-Einstufung; andere Werte im Feld sind Gruppenangaben. */
    private const array LEVELS = ['A', 'B', 'T'];

    /** Länderkürzel der Adresse (ISO, zwei Buchstaben) → IOC-Code. */
    private const array ADDRESS_COUNTRIES = ['AT' => 'AUT', 'DE' => 'GER', 'IT' => 'ITA', 'CH' => 'SUI'];

    /** Klassennummern, die als Sportklasse übernommen werden (0 = keine Klasse). */
    private const array SPORT_CLASS_NUMBERS = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 21];

    /** Nur national vergebene Klassen (Intellektuelle Beeinträchtigung, Down-Syndrom). */
    private const array NATIONAL_CLASS_NUMBERS = ['14', '21'];

    private const array SPORT_CLASS_COLUMNS = ['S' => 'HANDICAPS', 'SB' => 'HANDICAPSB', 'SM' => 'HANDICAPSM'];

    private const string IMPORT_NOTE = 'Übernahme aus dem Splash Team Manager';

    /**
     * @param  array{clubs: list<array<string, string|null>>, members: list<array<string, string|null>>}  $data
     */
    public function preview(array $data): TeamManagerImportPreview
    {
        $austria = Nation::query()->where('code', 'AUT')->first()
            ?? throw new RuntimeException('Die Nation AUT fehlt — bitte zuerst die Seeder ausführen.');

        $warnings = [];
        $clubs = $this->prepareClubs($data['clubs'], $warnings);
        $clubsByAccessId = array_column($clubs, null, 'access_id');

        $classifiers = $this->classifierLookup();
        $unknownClassifiers = [];
        $athletes = $this->prepareAthletes(
            $data['members'], $clubsByAccessId, $austria, $classifiers, $unknownClassifiers, $warnings,
        );

        uasort($unknownClassifiers, fn (array $a, array $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);

        return new TeamManagerImportPreview(
            clubs: $clubs,
            athletes: $athletes,
            knownClassifiers: $classifiers,
            unknownClassifiers: $unknownClassifiers,
            warnings: $warnings,
            counts: $this->counts($clubs, $athletes),
        );
    }

    /**
     * @param  array<string, int|null>  $assignments  normalisierter Name → Klassifizierer-ID oder null (übergehen)
     * @return array<string, int> Kennzahlen des Imports
     *
     * @throws Throwable
     */
    public function import(TeamManagerImportPreview $preview, array $assignments): array
    {
        $missing = array_diff_key($preview->unknownClassifiers, $assignments);

        if ($missing !== []) {
            throw new RuntimeException('Nicht alle unbekannten Klassifizierer sind zugeordnet.');
        }

        $classifierIds = $preview->knownClassifiers + $assignments;

        return DB::transaction(function () use ($preview, $classifierIds) {
            $counts = ['clubs_created' => 0, 'clubs_updated' => 0, 'athletes_created' => 0, 'athletes_updated' => 0,
                'classifications' => 0];

            $clubIds = [];
            foreach ($preview->clubs as $club) {
                $clubIds[$club['access_id']] = $this->importClub($club, $counts);
            }

            foreach ($preview->athletes as $row) {
                $this->importAthlete($row, $clubIds, $classifierIds, $counts);
            }

            return $counts;
        });
    }

    /** Vergleichsform eines Klassifizierer-Namens: klein, einfache Leerzeichen. */
    public static function classifierKey(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }

    // ── Vereine ───────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, string|null>>  $rows
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function prepareClubs(array $rows, array &$warnings): array
    {
        $existing = [];
        foreach (Club::query()->get() as $club) {
            if ($club->code !== null) {
                $existing['code:'.$this->clubCodeKey($club->code)] = $club;
            }
            $existing['name:'.mb_strtolower($club->name)] = $club;
        }

        $clubs = [];

        foreach ($rows as $row) {
            $code = $this->text($row['CODE'] ?? null);
            $name = $this->text($row['NAME'] ?? null) ?? $code ?? '';
            $lsc = $this->text($row['LSC'] ?? null);

            [$type, $association] = $this->clubTypeAndAssociation($code);

            if ($type === 'CLUB' && $lsc !== null) {
                $association = self::LSC_TO_ASSOCIATION[strtoupper($lsc)] ?? null;

                if ($association === null) {
                    $warnings[] = "Verein \"$name\": Landessportclub \"$lsc\" ist keinem Landesverband zugeordnet.";
                }
            }

            $match = ($code !== null ? $existing['code:'.$this->clubCodeKey($code)] ?? null : null)
                ?? $existing['name:'.mb_strtolower($name)] ?? null;

            $clubs[] = [
                'access_id' => (int) $row['CLUBSID'],
                'club_id' => $match?->id,
                'name' => $name,
                'short_name' => $this->limit($this->text($row['SHORTNAME'] ?? null), 40),
                'code' => $this->limit($code, 10),
                'type' => $type,
                'regional_association' => $association,
                'changed' => $match === null
                    || $match->type !== $type
                    || $match->regional_association !== $association,
            ];
        }

        return $clubs;
    }

    /** @return array{0: string, 1: string|null} Typ und Landesverband allein aus dem Vereinscode */
    private function clubTypeAndAssociation(?string $code): array
    {
        $code = $code === null ? null : strtoupper($code);

        if ($code === self::NATIONAL_FEDERATION_CODE) {
            return [Club::TYPE_VERBAND, null];
        }

        if ($code !== null && array_key_exists($code, Club::REGIONAL_ASSOCIATIONS)) {
            return [Club::TYPE_VERBAND, $code];
        }

        return ['CLUB', null];
    }

    /** "ÖBSV" und "AUT", "NÖVSV" und "NOEVSV" gelten als derselbe Code. */
    private function clubCodeKey(string $code): string
    {
        $key = strtr(mb_strtoupper(trim($code)), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE']);

        return $key === self::NATIONAL_FEDERATION_CODE ? 'OEBSV' : $key;
    }

    /**
     * @param  array<string, mixed>  $club
     * @param  array<string, int>  $counts
     */
    private function importClub(array $club, array &$counts): int
    {
        $attributes = ['type' => $club['type'], 'regional_association' => $club['regional_association']];

        if ($club['club_id'] !== null) {
            $model = Club::query()->findOrFail($club['club_id']);
            $model->fill($attributes);

            if ($model->isDirty()) {
                $model->save();
                $counts['clubs_updated']++;
            }

            return $model->id;
        }

        $counts['clubs_created']++;

        return Club::query()->create($attributes + [
            'name' => $club['name'],
            'short_name' => $club['short_name'],
            'code' => $club['code'],
            'nation_id' => Nation::query()->where('code', 'AUT')->value('id'),
        ])->id;
    }

    // ── Athleten ──────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, string|null>>  $rows
     * @param  array<int, array<string, mixed>>  $clubsByAccessId
     * @param  array<string, int>  $classifiers
     * @param  array<string, array{name: string, count: int}>  $unknownClassifiers
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function prepareAthletes(
        array $rows,
        array $clubsByAccessId,
        Nation $austria,
        array $classifiers,
        array &$unknownClassifiers,
        array &$warnings,
    ): array {
        $nations = Nation::query()->pluck('id', 'code')->all();
        $exceptionCodes = ExceptionCode::query()->pluck('id', 'code')->all();

        $holders = [];
        foreach ($rows as $row) {
            $license = $this->license($row['REGISTRATIONID'] ?? null);

            if ($license !== null) {
                $holders[$license][] = $this->memberLabel($row);
            }
        }
        $licenseCounts = array_map('count', $holders);

        foreach ($holders as $license => $names) {
            if (count($names) > 1) {
                $warnings[] = sprintf(
                    'Lizenznummer %s kommt %d× vor (%s) — diese Athleten werden über Name und Geburtsdatum zugeordnet.',
                    $license,
                    count($names),
                    implode(', ', $names),
                );
            }
        }

        [$byLicense, $byName] = $this->existingAthletes();
        $withoutNation = 0;
        $withoutClub = 0;
        $athletes = [];

        foreach ($rows as $row) {
            $label = $this->memberLabel($row);
            $issue = function (string $text) use (&$warnings, $label) {
                $warnings[] = "$label: $text";
            };

            $license = $this->license($row['REGISTRATIONID'] ?? null);
            $birthDate = $this->accessDate($row['BIRTHDATE'] ?? null);
            $firstName = $this->text($row['FIRSTNAME'] ?? null) ?? '';
            $lastName = $this->text($row['LASTNAME'] ?? null) ?? '';

            $nationCode = $this->text($row['NATION'] ?? null);
            if ($nationCode === null) {
                $withoutNation++;
            } elseif (! isset($nations[strtoupper($nationCode)])) {
                $issue("Nationalität \"$nationCode\" ist unbekannt — AUT angenommen.");
            }

            $accessClubId = (int) ($row['CLUBSID1'] ?? 0);
            if ($accessClubId === 0) {
                $withoutClub++;
            } elseif (! isset($clubsByAccessId[$accessClubId])) {
                $issue('Der Verein (Nr. '.$accessClubId.') fehlt in der Datei — ohne Verein übernommen.');
            }

            $sportClasses = $this->sportClasses($row, $issue);
            [$group, $subgroup] = $this->disabilityGroup($row, $issue);
            $scope = $this->sdmsId($row) !== null ? 'INTL' : 'NAT';

            $athletes[] = [
                'access_id' => (int) $row['MEMBERSID'],
                'athlete_id' => $this->matchAthlete($license, $licenseCounts, $lastName, $firstName, $birthDate, $byLicense, $byName),
                'access_club_id' => isset($clubsByAccessId[$accessClubId]) ? $accessClubId : null,
                'attributes' => [
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'name_prefix' => $this->text($row['NAMEPREFIX'] ?? null),
                    'birth_date' => $birthDate,
                    'gender' => $this->gender($row['GENDER'] ?? null, $issue),
                    'nation_id' => $nations[strtoupper($nationCode ?? '')] ?? $austria->id,
                    'license' => $license,
                    'license_ipc' => $this->sdmsId($row),
                    'is_active' => strtoupper($row['ACTIVE'] ?? '') === 'T',
                    'notes' => $this->text($row['NOTES'] ?? null),
                    'address_street' => $this->text($row['STREET'] ?? null),
                    'address_zip' => $this->text($row['ZIP'] ?? null),
                    'address_city' => $this->text($row['PLACE'] ?? null),
                    'address_country' => $this->addressCountry($row['ADDRESSNATION'] ?? null, $nations, $issue),
                    'level' => $this->level($row['SWIMLEVEL'] ?? null),
                    'disability_group' => $group,
                    'disability_subgroup' => $subgroup,
                    'last_medical_check_at' => $this->accessDate($row['LASTMEDICAL'] ?? null),
                    'next_medical_check_at' => $this->accessDate($row['NEXTMEDICAL'] ?? null),
                ],
                'sport_classes' => $sportClasses,
                'exceptions' => $this->exceptions($row['HANDICAPEX'] ?? null, $exceptionCodes, $issue),
                'classification' => $this->classification($row, $sportClasses, $scope, $classifiers, $unknownClassifiers, $issue),
                'club_history' => $this->clubHistory($row),
            ];
        }

        if ($withoutClub > 0) {
            $warnings[] = "$withoutClub Athlet(en) ohne Verein.";
        }
        if ($withoutNation > 0) {
            $warnings[] = "$withoutNation Athlet(en) ohne Nationalität — AUT angenommen.";
        }

        return $athletes;
    }

    /** @return array{0: array<string, int>, 1: array<string, int>} vorhandene Athleten nach Lizenz und nach Name + Geburtsdatum */
    private function existingAthletes(): array
    {
        $byLicense = [];
        $byName = [];

        foreach (Athlete::query()->get(['id', 'license', 'first_name', 'last_name', 'birth_date']) as $athlete) {
            $license = $this->license($athlete->license);
            if ($license !== null) {
                $byLicense[$license] ??= $athlete->id;
            }
            $byName[$this->nameKey($athlete->last_name, $athlete->first_name, $athlete->birth_date?->toDateString())] ??= $athlete->id;
        }

        return [$byLicense, $byName];
    }

    /**
     * @param  array<string, int>  $licenseCounts
     * @param  array<string, int>  $byLicense
     * @param  array<string, int>  $byName
     */
    private function matchAthlete(
        ?string $license,
        array $licenseCounts,
        string $lastName,
        string $firstName,
        ?string $birthDate,
        array $byLicense,
        array $byName,
    ): ?int {
        if ($license !== null && $licenseCounts[$license] === 1 && isset($byLicense[$license])) {
            return $byLicense[$license];
        }

        return $byName[$this->nameKey($lastName, $firstName, $birthDate)] ?? null;
    }

    /**
     * Name und Mitgliedsnummer für Hinweise, z. B. "Muster Carina (Nr. 716)".
     *
     * @param  array<string, string|null>  $row
     */
    private function memberLabel(array $row): string
    {
        return sprintf('%s %s (Nr. %s)', $this->text($row['LASTNAME'] ?? null), $this->text($row['FIRSTNAME'] ?? null), $row['MEMBERSID']);
    }

    private function nameKey(string $lastName, string $firstName, ?string $birthDate): string
    {
        return mb_strtolower(trim($lastName).'|'.trim($firstName).'|'.$birthDate);
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, int>  $clubIds  Team-Manager-Vereinsnummer → Vereins-ID
     * @param  array<string, int|null>  $classifierIds
     * @param  array<string, int>  $counts
     */
    private function importAthlete(array $row, array $clubIds, array $classifierIds, array &$counts): void
    {
        $attributes = $row['attributes'] + [
            'club_id' => $row['access_club_id'] === null ? null : $clubIds[$row['access_club_id']],
        ];

        $athlete = $row['athlete_id'] === null ? null : Athlete::query()->find($row['athlete_id']);
        $previousLevel = $athlete?->level;

        if ($athlete === null) {
            $athlete = Athlete::query()->create($attributes);
            $counts['athletes_created']++;
        } else {
            $athlete->update($attributes);
            $counts['athletes_updated']++;
        }

        if ($athlete->level !== null && $athlete->level !== $previousLevel) {
            AthleteLevelHistory::query()->create([
                'athlete_id' => $athlete->id,
                'user_id' => auth()->id(),
                'level' => $athlete->level,
                'previous_level' => $previousLevel,
                'changed_at' => today(),
                'notes' => self::IMPORT_NOTE,
            ]);
        }

        $classification = $row['classification'];

        foreach ($row['sport_classes'] as $category => $sportClass) {
            $athlete->sportClasses()->updateOrCreate(['category' => $category], [
                'class_number' => substr($sportClass, strlen($category)),
                'sport_class' => $sportClass,
                'classification_scope' => $classification['classification_scope'] ?? ($attributes['license_ipc'] !== null ? 'INTL' : 'NAT'),
                'classification_status' => $classification['classification_status'] ?? null,
                'frd_year' => $classification['frd_year'] ?? null,
            ]);
        }

        $athlete->exceptions()->detach();
        foreach ($row['exceptions'] as $exception) {
            $athlete->exceptions()->attach($exception['id'], ['category' => null, 'note' => $exception['note']]);
        }

        if ($classification !== null && $this->storeClassification($athlete, $classification, $row['exceptions'], $classifierIds)) {
            $counts['classifications']++;
        }

        if ($row['club_history'] !== null && $attributes['club_id'] !== null) {
            AthleteClubHistory::query()->firstOrCreate(
                ['athlete_id' => $athlete->id, 'club_id' => $attributes['club_id'], 'joined_at' => $row['club_history']['joined_at']],
                ['left_at' => $row['club_history']['left_at'], 'is_active' => $row['club_history']['left_at'] === null],
            );
        }
    }

    /**
     * Legt die Klassifizierung an, sofern dieselbe (Datum und Ort) nicht schon da ist.
     *
     * @param  array<string, mixed>  $classification
     * @param  list<array{id: int, code: string, note: string|null}>  $exceptions
     * @param  array<string, int|null>  $classifierIds
     */
    private function storeClassification(Athlete $athlete, array $classification, array $exceptions, array $classifierIds): bool
    {
        $exists = $athlete->classifications()
            ->where('location', $classification['location'])
            ->when(
                $classification['classified_at'] === null,
                fn ($query) => $query->whereNull('classified_at'),
                fn ($query) => $query->whereDate('classified_at', $classification['classified_at']),
            )
            ->exists();

        if ($exists) {
            return false;
        }

        $resolve = fn (?string $name) => $name === null ? null : $classifierIds[self::classifierKey($name)] ?? null;

        $model = AthleteClassification::query()->create([
            'athlete_id' => $athlete->id,
            'med_classifier_id' => $resolve($classification['med']),
            'tech1_classifier_id' => $resolve($classification['tech1']),
            'tech2_classifier_id' => $resolve($classification['tech2']),
            'classified_at' => $classification['classified_at'],
            'location' => $classification['location'],
            'result_s' => $classification['result_s'],
            'result_sb' => $classification['result_sb'],
            'result_sm' => $classification['result_sm'],
            'classification_scope' => $classification['classification_scope'],
            'classification_status' => $classification['classification_status'],
            'frd_year' => $classification['frd_year'],
            'notes' => self::IMPORT_NOTE,
        ]);

        foreach ($exceptions as $exception) {
            $model->exceptions()->attach($exception['id'], ['category' => null, 'note' => $exception['note']]);
        }

        return true;
    }

    // ── Einzelne Felder ───────────────────────────────────────────────────────

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string> Kategorie → Sportklasse, z. B. ['S' => 'S3', 'SB' => 'SB2']
     */
    private function sportClasses(array $row, callable $issue): array
    {
        $classes = [];

        foreach (self::SPORT_CLASS_COLUMNS as $category => $column) {
            $number = (int) ($row[$column] ?? 0);

            if ($number === 0) {
                continue;
            }

            if (! in_array($number, self::SPORT_CLASS_NUMBERS, true)) {
                $issue("Klasse $category$number ist keine gültige Sportklasse — nicht übernommen.");

                continue;
            }

            $classes[$category] = $category.$number;
        }

        return $classes;
    }

    /**
     * "Gruppen" hat Vorrang, "Ausbildung (Richter)" füllt Lücken.
     *
     * @param  array<string, string|null>  $row
     * @return array{0: string|null, 1: string|null}
     */
    private function disabilityGroup(array $row, callable $issue): array
    {
        $groups = $this->text($row['GROUPS'] ?? null);
        $grade = $this->text($row['GRADE'] ?? null);

        $fromGroups = $groups === null ? null : self::GROUP_MAP[strtoupper($groups)] ?? null;
        $fromGrade = $grade === null ? null : self::GRADE_MAP[strtoupper($grade)] ?? null;

        if ($groups !== null && $fromGroups === null) {
            $issue("Gruppe \"$groups\" ist unbekannt.");
        }
        if ($grade !== null && $fromGrade === null) {
            $issue("Ausbildung \"$grade\" ist keine bekannte Gruppe.");
        }
        if ($fromGroups !== null && $fromGrade !== null && $fromGroups !== $fromGrade) {
            $issue("Gruppe \"$groups\" und Ausbildung \"$grade\" widersprechen sich — \"$groups\" übernommen.");
        }

        return $fromGroups ?? $fromGrade ?? [null, null];
    }

    /**
     * Ausnahme-Codes in den Schreibweisen der Datei: "A,3,5,12+", "A 12", "A12", "AE 3, 12", "Y(50CM)".
     *
     * @param  array<string, int>  $known  Code → ID
     * @return list<array{id: int, code: string, note: string|null}>
     */
    private function exceptions(?string $value, array $known, callable $issue): array
    {
        $value = mb_strtoupper(trim((string) $value));
        $codes = [];
        $unknown = [];

        // Code mit Zusatz in Klammern, z. B. "Y(50CM)": der Zusatz wird zur Notiz.
        $value = (string) preg_replace_callback('/([A-Z])\(([^)]*)\)/', function (array $m) use (&$codes) {
            $codes[$m[1]] = trim($m[2]) === '' ? null : trim($m[2]);

            return ' ';
        }, $value);

        foreach (preg_split('/[\s,;]+/', $value, flags: PREG_SPLIT_NO_EMPTY) as $token) {
            if (isset($known[$token])) {
                $codes[$token] ??= null;

                continue;
            }

            // Zusammengeschrieben ("A12", "12+", "TB"): nur übernehmen, wenn jeder Teil ein bekannter Code ist.
            preg_match_all('/12|[0-9]|[A-Z]|\+/', $token, $parts);
            $parts = $parts[0];

            if (implode('', $parts) === $token && array_diff($parts, array_keys($known)) === []) {
                foreach ($parts as $part) {
                    $codes[$part] ??= null;
                }
            } else {
                $unknown[] = $token;
            }
        }

        if ($unknown !== []) {
            $issue('Ausnahme-Code(s) "'.implode('", "', $unknown).'" unbekannt — nicht übernommen.');
        }

        $result = [];
        foreach ($codes as $code => $note) {
            if (isset($known[$code])) {
                $result[] = ['id' => $known[$code], 'code' => (string) $code, 'note' => $note];
            } else {
                $issue("Ausnahme-Code \"$code\" unbekannt — nicht übernommen.");
            }
        }

        return $result;
    }

    /**
     * Klassifizierungsangaben (Status, Datum, Ort, Mediziner, Klassifizierer) aus den Feldern FREE1 bis FREE6.
     *
     * @param  array<string, string|null>  $row
     * @param  array<string, string>  $sportClasses
     * @param  array<string, int>  $classifiers
     * @param  array<string, array{name: string, count: int}>  $unknownClassifiers
     * @return array<string, mixed>|null
     */
    private function classification(
        array $row,
        array $sportClasses,
        string $scope,
        array $classifiers,
        array &$unknownClassifiers,
        callable $issue,
    ): ?array {
        $fields = [];
        foreach (range(1, 6) as $number) {
            $fields[$number] = $this->text($row['FREE'.$number] ?? null);
        }

        if (array_filter($fields) === []) {
            return $this->nationalClassification($sportClasses);
        }

        [$status, $frdYear] = $this->classificationStatus($fields[1], $issue);

        $date = null;
        if ($fields[2] !== null) {
            $parsed = CarbonImmutable::createFromFormat('!d.m.Y', $fields[2]);

            if ($parsed === false || $parsed->format('d.m.Y') !== $fields[2]) {
                $issue("Klassifizierungsdatum \"$fields[2]\" ist nicht lesbar — ohne Datum übernommen.");
            } else {
                $date = $parsed->toDateString();
            }
        }

        foreach ([4, 5, 6] as $number) {
            $name = $fields[$number];

            if ($name === null || isset($classifiers[self::classifierKey($name)])) {
                continue;
            }

            $key = self::classifierKey($name);
            $unknownClassifiers[$key] ??= ['name' => trim($name), 'count' => 0];
            $unknownClassifiers[$key]['count']++;
        }

        return [
            'classified_at' => $date,
            'location' => $fields[3],
            'med' => $fields[4],
            'tech1' => $fields[5],
            'tech2' => $fields[6],
            'result_s' => $sportClasses['S'] ?? null,
            'result_sb' => $sportClasses['SB'] ?? null,
            'result_sm' => $sportClasses['SM'] ?? null,
            'classification_scope' => $scope,
            'classification_status' => $status,
            'frd_year' => $frdYear,
        ];
    }

    /**
     * S14 und S21 vergibt der ÖBSV national, ohne Klassifizierung mit Klassifizierern — der Team Manager führt dafür
     * keine Angaben in FREE1 bis FREE6. Damit die Klassen trotzdem in der Historie stehen, wird ein nationaler,
     * bestätigter Eintrag ohne Datum angelegt.
     *
     * @param  array<string, string>  $sportClasses
     * @return array<string, mixed>|null
     */
    private function nationalClassification(array $sportClasses): ?array
    {
        $numbers = array_map(fn (string $category) => substr($sportClasses[$category], strlen($category)), array_keys($sportClasses));

        if ($numbers === [] || array_diff($numbers, self::NATIONAL_CLASS_NUMBERS) !== []) {
            return null;
        }

        return [
            'classified_at' => null,
            'location' => null,
            'med' => null,
            'tech1' => null,
            'tech2' => null,
            'result_s' => $sportClasses['S'] ?? null,
            'result_sb' => $sportClasses['SB'] ?? null,
            'result_sm' => $sportClasses['SM'] ?? null,
            'classification_scope' => 'NAT',
            'classification_status' => 'CONFIRMED',
            'frd_year' => null,
        ];
    }

    /**
     * C = bestätigt, N = neu, R/Review = Überprüfung; mit Jahr ("R-2025", "Review 2020") = Überprüfung ab bzw. im
     * Jahr (FRD). "P-2029" ist ein Tippfehler für "R-2029".
     *
     * @return array{0: string|null, 1: int|null}
     */
    private function classificationStatus(?string $value, callable $issue): array
    {
        if ($value === null) {
            return [null, null];
        }

        $normalized = mb_strtoupper(trim($value));

        return match (true) {
            (bool) preg_match('/^C(\s*-?\s*\d{4})?$/', $normalized) => ['CONFIRMED', null],
            $normalized === 'N' => ['NEW', null],
            in_array($normalized, ['R', 'REVIEW'], true) => ['REVIEW', null],
            (bool) preg_match('/^(?:R|P|REVIEW)\s*-?\s*(\d{4})$/', $normalized, $m) => ['FRD', (int) $m[1]],
            default => (function () use ($value, $issue) {
                $issue("Klassifizierungsstatus \"$value\" ist unbekannt — ohne Status übernommen.");

                return [null, null];
            })(),
        };
    }

    /** @return array{joined_at: string, left_at: string|null}|null */
    private function clubHistory(array $row): ?array
    {
        $joined = $this->accessDate($row['ENTRYDATE'] ?? null);

        return $joined === null ? null : ['joined_at' => $joined, 'left_at' => $this->accessDate($row['EXITDATE'] ?? null)];
    }

    /** @return array<string, int> normalisierter Name ("nachname vorname" und "vorname nachname") → ID */
    private function classifierLookup(): array
    {
        $lookup = [];

        foreach (Classifier::query()->get(['id', 'first_name', 'last_name']) as $classifier) {
            $lookup[self::classifierKey($classifier->last_name.' '.$classifier->first_name)] = $classifier->id;
            $lookup[self::classifierKey($classifier->first_name.' '.$classifier->last_name)] = $classifier->id;
        }

        return $lookup;
    }

    private function gender(?string $value, callable $issue): string
    {
        return match ((string) $value) {
            '1' => 'M',
            '2' => 'F',
            default => (function () use ($value, $issue) {
                $issue("Geschlecht \"$value\" ist unbekannt — männlich angenommen.");

                return 'M';
            })(),
        };
    }

    /** Leerzeichen raus, Gedankenstriche zu Bindestrichen: "W - 1234" → "W-1234". */
    private function license(?string $value): ?string
    {
        $license = (string) preg_replace('/\s+/u', '', str_replace(['–', '—'], '-', (string) $value));

        return $license === '' ? null : $license;
    }

    /** @param  array<string, string|null>  $row */
    private function sdmsId(array $row): ?string
    {
        $id = (int) ($row['SDMSID'] ?? 0);

        return $id > 0 ? (string) $id : null;
    }

    private function level(?string $value): ?string
    {
        $level = strtoupper(trim((string) $value));

        return in_array($level, self::LEVELS, true) ? $level : null;
    }

    /** @param  array<string, int>  $nations */
    private function addressCountry(?string $value, array $nations, callable $issue): ?string
    {
        $code = strtoupper(trim((string) $value));

        if ($code === '') {
            return null;
        }

        if (isset(self::ADDRESS_COUNTRIES[$code])) {
            return self::ADDRESS_COUNTRIES[$code];
        }

        if (strlen($code) === 3 && isset($nations[$code])) {
            return $code;
        }

        $issue("Land der Adresse \"$value\" ist unbekannt — leer gelassen.");

        return null;
    }

    /** Access-Datum ("2001-01-20 00:00:00"); Platzhalter wie 01.01.1800 gelten als leer. */
    private function accessDate(?string $value): ?string
    {
        $date = substr(trim((string) $value), 0, 10);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || (int) substr($date, 0, 4) < 1900) {
            return null;
        }

        return $date;
    }

    private function text(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function limit(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    /**
     * @param  list<array<string, mixed>>  $clubs
     * @param  list<array<string, mixed>>  $athletes
     * @return array<string, int>
     */
    private function counts(array $clubs, array $athletes): array
    {
        $count = fn (array $rows, callable $filter) => count(array_filter($rows, $filter));

        return [
            'clubs_new' => $count($clubs, fn ($c) => $c['club_id'] === null),
            'clubs_changed' => $count($clubs, fn ($c) => $c['club_id'] !== null && $c['changed']),
            'clubs_unchanged' => $count($clubs, fn ($c) => ! $c['changed']),
            'athletes_new' => $count($athletes, fn ($a) => $a['athlete_id'] === null),
            'athletes_existing' => $count($athletes, fn ($a) => $a['athlete_id'] !== null),
            'athletes_inactive' => $count($athletes, fn ($a) => ! $a['attributes']['is_active']),
            'classifications' => $count($athletes, fn ($a) => $a['classification'] !== null),
            'sport_classes' => array_sum(array_map(fn ($a) => count($a['sport_classes']), $athletes)),
            'exceptions' => array_sum(array_map(fn ($a) => count($a['exceptions']), $athletes)),
        ];
    }
}
