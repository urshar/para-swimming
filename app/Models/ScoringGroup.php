<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ScoringGroup — Wertungsgruppe eines Bewerbs (LENEX AGEGROUP).
 *
 * Bestimmt, welche Ergebnisse gemeinsam gewertet werden: Geschlecht, Sportklassen (Nummern; Kategorie aus der Lage,
 * bei Staffeln die Staffelklasse), Alter, Landesverband des Vereins ("nur diese" oder "alle außer diesen") und
 * Athleten-Level. Die Zuordnung berechnet App\Services\ScoringGroupService.
 *
 * @property int $id
 * @property int $swim_event_id
 * @property string $name
 * @property string $gender
 * @property string|null $sport_classes
 * @property int|null $age_min
 * @property int|null $age_max
 * @property string|null $regional_associations
 * @property string|null $regional_mode
 * @property string|null $levels
 * @property string|null $title
 * @property int $sort_order
 * @property string|null $lenex_agegroup_id
 */
class ScoringGroup extends Model
{
    public const string TITLE_STATE = 'OSTM';

    public const string TITLE_NATIONAL = 'OM';

    /** Meisterschaftstitel für Auswahl und Anzeige. */
    public const array TITLES = [
        self::TITLE_STATE => 'Österr. Staatsmeisterschaft',
        self::TITLE_NATIONAL => 'Österr. Meisterschaft',
    ];

    /** Nur Vereine der angegebenen Landesverbände. */
    public const string REGION_ONLY = 'only';

    /** Alle Vereine außer denen der angegebenen Landesverbände (auch ohne Verband und ausländische), z. B. Gäste. */
    public const string REGION_EXCEPT = 'except';

    public const array REGION_MODES = [
        self::REGION_ONLY => 'nur diese',
        self::REGION_EXCEPT => 'alle außer diesen',
    ];

    /**
     * Schreibweisen der Bundesländer in Gruppennamen je Verbandscode (zusätzlich zum Code selbst), für
     * associationFromName(); ein abschließender Punkt ("Wr.", "Stmk.") ist immer erlaubt.
     */
    private const array ASSOCIATION_NAME_TOKENS = [
        'BBSV' => ['Burgenland', 'Burgenländische', 'Bgld'],
        'KBSV' => ['Kärnten', 'Kärntner', 'Ktn'],
        'NOEVSV' => ['Niederösterreich', 'Niederösterreichische', 'NÖ', 'NOE'],
        'OOEBSV' => ['Oberösterreich', 'Oberösterreichische', 'OÖ', 'OOE'],
        'SBSV' => ['Salzburg', 'Salzburger', 'Sbg'],
        'STBSV' => ['Steiermark', 'Steirische', 'Stmk'],
        'TBSV' => ['Tirol', 'Tiroler'],
        'VBSV' => ['Vorarlberg', 'Vorarlberger', 'Vbg'],
        'WBSV' => ['Wien', 'Wiener', 'Wr'],
    ];

    protected $fillable = [
        'swim_event_id',
        'name',
        'gender',
        'sport_classes',
        'age_min',
        'age_max',
        'regional_associations',
        'regional_mode',
        'levels',
        'title',
        'sort_order',
        'lenex_agegroup_id',
    ];

    protected $casts = [
        'age_min' => 'integer',
        'age_max' => 'integer',
        'sort_order' => 'integer',
    ];

    public function swimEvent(): BelongsTo
    {
        return $this->belongsTo(SwimEvent::class);
    }

    /**
     * Sportklassen als Nummern; leer = alle Klassen.
     *
     * @return list<int>
     */
    public function classNumbers(): array
    {
        return self::parseClassNumbers($this->sport_classes);
    }

    /**
     * Landesverbände der Einschränkung; leer = alle Vereine.
     *
     * @return list<string>
     */
    public function regionalAssociationCodes(): array
    {
        return self::parseCodes($this->regional_associations);
    }

    /**
     * Athleten-Level der Einschränkung; leer = alle Athleten. Die Werte sind nicht fest (z. B. A/B/T), sondern kommen
     * aus den Daten der Athleten.
     *
     * @return list<string>
     */
    public function levelCodes(): array
    {
        return self::parseLevels($this->levels);
    }

    /**
     * Ob ein Ergebnis mit diesen Merkmalen in die Gruppe fällt. Unbekanntes Alter passt nur, wenn die Gruppe keine
     * Altersgrenze hat; eine unbekannte Klasse nur, wenn die Gruppe alle Klassen umfasst; ein unbekanntes Level nur,
     * wenn die Gruppe kein Level verlangt. Ein Verein ohne Landesverband passt nie zu "nur diese", immer zu "alle außer
     * diesen".
     */
    public function matches(?string $gender, ?int $classNumber, ?int $age, ?string $regionalAssociation, ?string $level): bool
    {
        $associations = $this->regionalAssociationCodes();
        if ($associations !== []) {
            $listed = $regionalAssociation !== null && in_array($regionalAssociation, $associations, true);
            if ($listed === ($this->regional_mode === self::REGION_EXCEPT)) {
                return false;
            }
        }

        // Level ohne Rücksicht auf Groß-/Kleinschreibung ("Elite" = "elite").
        $levels = array_map(mb_strtolower(...), $this->levelCodes());
        if ($levels !== [] && ($level === null || ! in_array(mb_strtolower(trim($level)), $levels, true))) {
            return false;
        }

        if ($this->gender !== 'A' && $this->gender !== $gender) {
            return false;
        }

        $classes = $this->classNumbers();
        if ($classes !== [] && ! in_array($classNumber, $classes, true)) {
            return false;
        }

        if ($this->age_min !== null || $this->age_max !== null) {
            if ($age === null) {
                return false;
            }
            if (($this->age_min !== null && $age < $this->age_min) || ($this->age_max !== null && $age > $this->age_max)) {
                return false;
            }
        }

        return true;
    }

    /** Anzeigename mit Geschlecht, z. B. "Damen – ÖSTM: S01 - S08". */
    public function label(): string
    {
        $gender = match ($this->gender) {
            'M' => 'Herren',
            'F' => 'Damen',
            'X' => 'Mixed',
            default => null,
        };

        return $gender ? $gender.' – '.$this->name : $this->name;
    }

    /**
     * Kurzer Hinweis auf die Einschränkung nach Landesverband und Level, z. B. "nur WBSV · Level B"; null = keine.
     */
    public function restrictionLabel(): ?string
    {
        $parts = [];
        $associations = $this->regionalAssociationCodes();
        if ($associations !== []) {
            $parts[] = ($this->regional_mode === self::REGION_EXCEPT ? 'ohne ' : 'nur ').implode(', ', $associations);
        }
        $levels = $this->levelCodes();
        if ($levels !== []) {
            $parts[] = 'Level '.implode(', ', $levels);
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * "WBSV, SBSV" oder "a b" → ["SBSV", "WBSV"] bzw. ["A", "B"]; Großbuchstaben, dedupliziert und sortiert.
     *
     * @return list<string>
     */
    public static function parseCodes(?string $codes): array
    {
        return collect(preg_split('/[\s,;]+/', mb_strtoupper(trim((string) $codes))))
            ->filter(fn (string $c): bool => $c !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Auswahl für das Level einer Wertungsgruppe: alle vergebenen Level der Athleten (Athlete::knownLevels()) plus die
     * Level der übergebenen Listen (vorhandene Gruppen, abgeschicktes Formular), auch wenn sie kein Athlet mehr hat.
     *
     * @param  list<?string>  $levelLists  kommagetrennte Level-Listen
     * @return list<string>
     */
    public static function levelOptions(array $levelLists): array
    {
        return self::parseLevels(implode(',', [...Athlete::knownLevels(), ...array_map(strval(...), $levelLists)]));
    }

    /**
     * Level-Liste "B, A" oder "Elite; Talent" → ["A", "B"] bzw. ["Elite", "Talent"]. Getrennt nur an Komma und
     * Semikolon (ein Level kann Leerzeichen enthalten), Schreibweise bleibt; dedupliziert (ohne Rücksicht auf
     * Groß-/Kleinschreibung) und natürlich sortiert.
     *
     * @return list<string>
     */
    public static function parseLevels(?string $levels): array
    {
        return collect(preg_split('/[,;]+/', (string) $levels))
            ->map(fn (string $level): string => trim($level))
            ->filter(fn (string $level): bool => $level !== '')
            ->unique(fn (string $level): string => mb_strtolower($level))
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
    }

    /**
     * Landesverband einer Landesmeisterschafts-Gruppe aus ihrem Namen (LENEX kennt dafür kein Attribut): "LM Wien SB 01
     * - 09", "LM NÖ Jugend", "Landesmeisterschaft Tirol", "Wr. LM", "Wiener Landesmeisterschaft", "LM WBSV" →
     * Verbandscode; sonst null.
     */
    public static function associationFromName(string $name): ?string
    {
        // Bundesland-Schreibweise (klein) → Verbandscode; längere zuerst, damit "Wiener" nicht als "Wien" endet.
        $tokens = [];
        foreach (self::ASSOCIATION_NAME_TOKENS as $code => $names) {
            foreach ([$code, ...$names] as $token) {
                $tokens[mb_strtolower($token)] = $code;
            }
        }
        uksort($tokens, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $state = '('.implode('|', array_map(fn (string $t): string => preg_quote($t, '/'), array_keys($tokens))).')\.?';
        $lm = '(?:LM|Landesmeisterschaft(?:en)?)';
        $end = '(?=[\s\-:,]|$)';

        if (preg_match('/^'.$lm.'\s+'.$state.$end.'/iu', trim($name), $m) === 1
            || preg_match('/^'.$state.'\s+'.$lm.$end.'/iu', trim($name), $m) === 1) {
            return $tokens[mb_strtolower($m[1])];
        }

        return null;
    }

    /** Gäste-Gruppe einer Landesmeisterschaft ("Gäste SB 01 - 15", "Gastschwimmer"). */
    public static function isGuestName(string $name): bool
    {
        return preg_match('/^(Gäste|Gast)/iu', trim($name)) === 1;
    }

    /**
     * "1,2,3", "1 2 3", "S1 S2" oder "SB4" → [1, 2, 3] bzw. [4]; dedupliziert und sortiert.
     *
     * @return list<int>
     */
    public static function parseClassNumbers(?string $classes): array
    {
        return collect(preg_split('/[\s,;]+/', trim((string) $classes)))
            ->map(fn (string $c): string => preg_replace('/\D+/', '', $c))
            ->filter(fn (string $c): bool => $c !== '')
            ->map(fn (string $c): int => (int) $c)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
