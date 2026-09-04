<?php

namespace App\Support;

use App\Models\Result;

/**
 * Filterzustand einer WPS-Rangliste (Spec "WPS Rankings" §10).
 *
 * Gekapselt als Support-Objekt nach dem Vorbild von `ReportConfiguration`, damit
 * Livewire-Komponente, Service und PDF dieselbe Definition verwenden — und die Filter im
 * PDF-Kopf ausgegeben werden können, ohne sie ein zweites Mal zu beschreiben.
 */
final readonly class WpsRankingFilter
{
    public const string TYPE_SEASON = 'season';

    public const string TYPE_MEET = 'meet';

    public const string TYPE_EVENT = 'event';

    /** @var list<string> */
    public const array TYPES = [self::TYPE_SEASON, self::TYPE_MEET, self::TYPE_EVENT];

    public const string COURSE_LCM = 'LCM';

    public const string COURSE_SCM = 'SCM';

    /** Beide Bahnlängen gemeinsam — löst den Hinweis nach §11.4 aus. */
    public const string COURSE_MIXED = 'MIXED';

    /** Kaderfilter wirkt nicht. */
    public const string KADER_ALL = 'all';

    /** Nur Athleten der gewählten Kaderarten zeigen. */
    public const string KADER_ONLY = 'only';

    /** Athleten der gewählten Kaderarten ausblenden. */
    public const string KADER_EXCEPT = 'except';

    /** @var list<string> */
    public const array KADER_MODES = [self::KADER_ALL, self::KADER_ONLY, self::KADER_EXCEPT];

    /**
     * Platzhalter für Athleten ohne Kaderzugehörigkeit.
     *
     * Bewusst wählbar: Ohne ihn ließe sich "nur Kaderathleten" nicht ausdrücken, und beim
     * Ausblenden verschwänden Athleten ohne Zuordnung entweder immer oder nie — beides wäre
     * eine stille Festlegung.
     */
    public const int KADER_NONE = 0;

    /**
     * @param  string  $type  Ranglistenart nach §6
     * @param  int|null  $year  Wettkampfjahr; null nur bei der Veranstaltungsrangliste
     * @param  int|null  $meetId  Veranstaltung (§6.1)
     * @param  int|null  $strokeTypeId  Bewerb: Schwimmstil …
     * @param  int|null  $distance  … und Strecke
     * @param  string  $gender  M, F oder leer für alle
     * @param  string  $sportClass  z.B. "S9,SB9,SM9" (Klassennummer, über S/SB/SM zusammengefasst); leer für alle
     * @param  string  $course  LCM, SCM oder MIXED
     * @param  int|null  $clubId  Verein
     * @param  int|null  $minPoints  Mindestpunktzahl
     * @param  int|null  $maxAge  Altersobergrenze für die Jugendrangliste (§6.3)
     * @param  int|null  $ageGroupId  Altersgruppe aus dem Cup-Modul (§5)
     * @param  bool  $includeExhibition  EXH einbeziehen (§4)
     * @param  string  $calculationType  official, estimated oder leer für beide
     */
    public function __construct(
        public string $type = self::TYPE_SEASON,
        public ?int $year = null,
        public ?int $meetId = null,
        public ?int $strokeTypeId = null,
        public ?int $distance = null,
        public string $gender = '',
        public string $sportClass = '',
        public string $course = self::COURSE_SCM,
        public ?int $clubId = null,
        public ?int $minPoints = null,
        public ?int $maxAge = null,
        public bool $includeExhibition = false,
        public string $calculationType = '',
        public ?int $ageGroupId = null,
        /** Bezeichnung der Altersgruppe — nur für describe(), nicht für die Auswahl. */
        public ?string $ageGroupLabel = null,
        public string $kaderMode = self::KADER_ALL,
        /** @var list<int> Kaderarten-IDs; KADER_NONE steht für "ohne Zuordnung" */
        public array $kaderIds = [],
    ) {}

    /**
     * Vorbelegung für das laufende Jahr.
     *
     * Bahnlänge ist **SCM**, abweichend von Version 1.1 der Spec: In Österreich wird
     * ausschließlich Kurzbahn geschwommen (`wps-points` §2.3), eine LCM-Standardansicht wäre
     * für nationale Auswertungen nahezu leer (§4).
     */
    public static function default(?int $year = null): self
    {
        return new self(year: $year ?? (int) date('Y'));
    }

    /**
     * Baut den Filter aus Abfrageparametern — für die PDF-Route.
     *
     * Unbekannte Werte fallen auf den Standard zurück, statt eine leere Rangliste zu
     * erzeugen: Ein vertippter Parameter soll eine vollständige Liste liefern, keine leere.
     *
     * @param  array<string, mixed>  $query
     */
    public static function fromQuery(array $query): self
    {
        $art = (string) ($query['type'] ?? self::TYPE_SEASON);
        $bahn = strtoupper((string) ($query['course'] ?? self::COURSE_SCM));
        $rechenart = (string) ($query['calc'] ?? '');

        // Benannte Argumente: Bei sechzehn Parametern ist die Reihenfolge nicht mehr
        // überschaubar, und ein neuer Parameter in der Mitte verschöbe still alle folgenden.
        return new self(
            type: in_array($art, self::TYPES, true) ? $art : self::TYPE_SEASON,
            year: self::intOrNull($query['year'] ?? null) ?? (int) date('Y'),
            meetId: self::intOrNull($query['meet'] ?? null),
            strokeTypeId: self::intOrNull($query['stroke'] ?? null),
            distance: self::intOrNull($query['distance'] ?? null),
            gender: in_array($query['gender'] ?? '', ['M', 'F'], true) ? (string) $query['gender'] : '',
            sportClass: trim((string) ($query['class'] ?? '')),
            course: in_array($bahn, self::courses(), true) ? $bahn : self::COURSE_SCM,
            clubId: self::intOrNull($query['club'] ?? null),
            minPoints: self::intOrNull($query['minPoints'] ?? null),
            maxAge: self::intOrNull($query['maxAge'] ?? null),
            includeExhibition: filter_var($query['exh'] ?? false, FILTER_VALIDATE_BOOL),
            calculationType: in_array($rechenart, Result::WPS_CALCULATION_TYPES, true) ? $rechenart : '',
            ageGroupId: self::intOrNull($query['ageGroup'] ?? null),
            kaderMode: in_array($query['kaderMode'] ?? '', self::KADER_MODES, true)
                ? (string) $query['kaderMode']
                : self::KADER_ALL,
            kaderIds: self::intList($query['kader'] ?? ''),
        );
    }

    /** @return list<string> */
    public static function courses(): array
    {
        return [self::COURSE_LCM, self::COURSE_SCM, self::COURSE_MIXED];
    }

    /** Wirkt der Kaderfilter? */
    public function hasKaderFilter(): bool
    {
        return $this->kaderMode !== self::KADER_ALL && $this->kaderIds !== [];
    }

    /**
     * Soll ein Athlet mit dieser Kaderart angezeigt werden?
     *
     * @param  int|null  $kaderTypeId  null = keine Kaderzugehörigkeit zum Stichtag
     */
    public function allowsKader(?int $kaderTypeId): bool
    {
        if (! $this->hasKaderFilter()) {
            return true;
        }

        $gewaehlt = in_array($kaderTypeId ?? self::KADER_NONE, $this->kaderIds, true);

        return $this->kaderMode === self::KADER_ONLY ? $gewaehlt : ! $gewaehlt;
    }

    /**
     * Als Abfrageparameter — damit der PDF-Link denselben Ausschnitt mitnimmt wie der
     * Bildschirm, von dem aus er erzeugt wurde.
     *
     * Nur abweichende Werte werden aufgenommen; der Standardzustand ergibt eine leere Liste
     * und damit eine Adresse ohne Fragezeichen.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'type' => $this->type === self::TYPE_SEASON ? '' : $this->type,
            'year' => (string) ($this->year ?? ''),
            'meet' => (string) ($this->meetId ?? ''),
            'stroke' => (string) ($this->strokeTypeId ?? ''),
            'distance' => (string) ($this->distance ?? ''),
            'gender' => $this->gender,
            'class' => $this->sportClass,
            'course' => $this->course === self::COURSE_SCM ? '' : $this->course,
            'club' => (string) ($this->clubId ?? ''),
            'minPoints' => (string) ($this->minPoints ?? ''),
            'maxAge' => (string) ($this->maxAge ?? ''),
            'ageGroup' => (string) ($this->ageGroupId ?? ''),
            'exh' => $this->includeExhibition ? '1' : '',
            'calc' => $this->calculationType,
            'kaderMode' => $this->kaderMode === self::KADER_ALL ? '' : $this->kaderMode,
            'kader' => implode(',', $this->kaderIds),
        ], static fn (string $wert): bool => $wert !== '');
    }

    /**
     * Kopie mit erzwungener Vereinseinschränkung.
     *
     * Für die Vereinsauswertung: Ein Vereinsnutzer sieht nur den eigenen Verein (**[R2]**),
     * und diese Regel muss eine etwaige Auswahl im Filter überschreiben — nicht umgekehrt.
     */
    public function withClub(int $clubId): self
    {
        return new self(
            type: $this->type,
            year: $this->year,
            meetId: $this->meetId,
            strokeTypeId: $this->strokeTypeId,
            distance: $this->distance,
            gender: $this->gender,
            sportClass: $this->sportClass,
            course: $this->course,
            clubId: $clubId,
            minPoints: $this->minPoints,
            maxAge: $this->maxAge,
            includeExhibition: $this->includeExhibition,
            calculationType: $this->calculationType,
            ageGroupId: $this->ageGroupId,
            ageGroupLabel: $this->ageGroupLabel,
            kaderMode: $this->kaderMode,
            kaderIds: $this->kaderIds,
        );
    }

    /**
     * Bezeichnung der Ranglistenart für Überschrift und Dateiname.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_MEET => 'Veranstaltungsrangliste',
            self::TYPE_EVENT => 'Bewerbsrangliste',
            default => 'Saisonrangliste',
        };
    }

    /**
     * Werden Langbahn- und Kurzbahnergebnisse gemeinsam gezeigt?
     *
     * Löst den verpflichtenden Hinweis nach §11.4 aus: Offizielle und geschätzte Punkte
     * werden standardmäßig nicht vermischt (§4), und wenn doch, muss das sichtbar sein.
     */
    public function isMixedCourse(): bool
    {
        return $this->course === self::COURSE_MIXED;
    }

    /**
     * Beschreibung des Filterstands für den PDF-Kopf und die Bildschirmzeile.
     *
     * Nennt nur, was vom Standard abweicht — eine Aufzählung aller Felder wäre unlesbar und
     * würde die tatsächliche Einschränkung verdecken.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        $teile = [];

        if ($this->year !== null && $this->type !== self::TYPE_MEET) {
            $teile[] = "Jahr $this->year";
        }

        $teile[] = match ($this->course) {
            self::COURSE_MIXED => 'Lang- und Kurzbahn gemeinsam',
            default => "Bahnlänge $this->course",
        };

        if ($this->gender !== '') {
            $teile[] = $this->gender === 'M' ? 'männlich' : 'weiblich';
        }

        if ($this->sportClass !== '') {
            $teile[] = "Sportklasse $this->sportClass";
        }

        if ($this->maxAge !== null) {
            $teile[] = "bis $this->maxAge Jahre";
        }

        if ($this->ageGroupId !== null && $this->ageGroupLabel !== null) {
            $teile[] = "Altersgruppe $this->ageGroupLabel";
        }

        if ($this->minPoints !== null) {
            $teile[] = "ab $this->minPoints Punkten";
        }

        if ($this->calculationType === Result::WPS_TYPE_OFFICIAL) {
            $teile[] = 'nur offizielle Punkte';
        }

        if ($this->calculationType === Result::WPS_TYPE_ESTIMATED) {
            $teile[] = 'nur geschätzte Punkte';
        }

        if ($this->includeExhibition) {
            $teile[] = 'einschließlich außer Konkurrenz (EXH)';
        }

        if ($this->hasKaderFilter()) {
            $teile[] = $this->kaderMode === self::KADER_ONLY
                ? 'nur ausgewählte Kaderarten'
                : 'ausgewählte Kaderarten ausgeblendet';
        }

        return $teile;
    }

    /**
     * Kommaliste von Kennungen aus der Adresse.
     *
     * Nicht numerische Werte werden verworfen statt zu einem Fehler zu führen. Die Null ist
     * hier zulässig — sie steht für "ohne Kaderzuordnung".
     *
     * @return list<int>
     */
    private static function intList(mixed $wert): array
    {
        if (! is_string($wert) || trim($wert) === '') {
            return [];
        }

        return collect(explode(',', $wert))
            ->map(static fn (string $eintrag): string => trim($eintrag))
            ->filter(static fn (string $eintrag): bool => is_numeric($eintrag))
            ->map(static fn (string $eintrag): int => (int) $eintrag)
            ->unique()
            ->values()
            ->all();
    }

    private static function intOrNull(mixed $wert): ?int
    {
        // is_numeric() deckt null und den leeren String bereits ab.
        if (! is_numeric($wert)) {
            return null;
        }

        $zahl = (int) $wert;

        return $zahl > 0 ? $zahl : null;
    }
}
