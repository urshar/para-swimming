<?php

namespace App\Support;

/**
 * Bewertung eines Athleten in einem Bewerb gegen die Norm einer Meisterschaft
 * (Spec "WPS Qualification" §7.2, §12).
 *
 * Rein beschreibend — die Bewertung selbst trifft QualificationEvaluationService.
 *
 * Der Abstand zur Norm wird IMMER mitgeführt, auch bei Nichterfüllung: Er ist laut §7.3 die
 * eigentliche Information für die Förderentscheidung. Ob jemand zwei Zehntel oder vier
 * Sekunden fehlt, ist ein Unterschied, den ein bloßes "nicht erreicht" verschweigt.
 */
final readonly class QualificationStatus
{
    /** Reale Zeit unterhalb der MQS. */
    public const string MQS_MET = 'mqs_met';

    /** Reale Zeit zusätzlich unterhalb der ÖBSV-Norm. */
    public const string OBSV_MET = 'obsv_met';

    /** Reale Zeit unterhalb der MET, aber nicht der MQS. */
    public const string MET_ONLY = 'met_only';

    /** Umgerechnete Kurzbahnzeit unterhalb der MQS — KEIN Nachweis ([Q4]). */
    public const string ESTIMATED_MQS = 'estimated_mqs';

    public const string NOT_MET = 'not_met';

    /** Für Bewerb und Klasse existiert keine Norm — nicht dasselbe wie "nicht erreicht". */
    public const string NO_STANDARD = 'no_standard';

    /**
     * @param  string  $status  eine der Konstanten oben
     * @param  int|null  $swimTime  die zugrunde liegende Zeit in Hundertstelsekunden
     * @param  string|null  $course  Bahnlänge, auf der sie geschwommen wurde
     * @param  int|null  $estimatedLcmTime  umgerechnete Langbahnzeit, falls umgerechnet wurde
     * @param  float|null  $conversionFactor  verwendeter Faktor, für die Nachvollziehbarkeit
     * @param  int|null  $gapToMqs  Abstand zur MQS in Hundertstelsekunden; negativ = darunter
     * @param  int|null  $gapToObsv  Abstand zur ÖBSV-Norm, gleiche Vorzeichenkonvention
     * @param  bool  $meetApproved  Wettkampf von World Para Swimming sanktioniert
     * @param  bool  $exhibition  außer Konkurrenz geschwommen (EXH)
     * @param  string|null  $note  Begründung, wenn nicht bewertet oder nicht umgerechnet wurde
     */
    public function __construct(
        public string $status,
        public ?int $swimTime,
        public ?string $course,
        public ?int $estimatedLcmTime,
        public ?float $conversionFactor,
        public ?int $gapToMqs,
        public ?int $gapToObsv,
        public bool $meetApproved,
        public bool $exhibition,
        public ?string $note,
        public ?int $resultId,
        public ?int $meetId,
        public ?string $meetName,
        public ?string $meetDate,
    ) {}

    /**
     * Taugt diese Bewertung als Qualifikationsnachweis?
     *
     * Zwei Bedingungen müssen zusammenkommen: reale (nicht umgerechnete) Zeit unterhalb der
     * MQS, und ein von World Para Swimming sanktionierter Wettkampf. Fällt eine davon weg,
     * ist es ein Planungswert und gehört nicht in die Qualifikantenliste ([Q4], Q-R1).
     */
    public function isProof(): bool
    {
        return in_array($this->status, [self::MQS_MET, self::OBSV_MET], true)
            && $this->meetApproved;
    }

    /** Beruht die Bewertung auf einer umgerechneten Zeit? */
    public function isEstimate(): bool
    {
        return $this->status === self::ESTIMATED_MQS;
    }

    public function hasStandard(): bool
    {
        return $this->status !== self::NO_STANDARD;
    }

    /**
     * Farbe der Statuskennzeichnung (§10).
     *
     * amber für "rechnerisch erreicht": Hier lohnt ein zweiter Blick, weil die Zahl gut
     * aussieht, aber nichts beweist.
     *
     * rot für "nicht erreicht": In einer Liste, in der die meisten Zeilen erfüllt sind, geht
     * ein neutrales Grau unter — gerade die offenen Bewerbe sind aber das, worauf der Blick
     * fallen soll. "Ohne Norm" bleibt grau: Das ist keine verfehlte Leistung, sondern eine
     * Aussage über die Ausschreibung.
     */
    public function colour(): string
    {
        return match ($this->status) {
            self::OBSV_MET => 'green',
            self::MQS_MET => 'blue',
            self::ESTIMATED_MQS, self::MET_ONLY => 'amber',
            self::NOT_MET => 'red',
            default => 'zinc',
        };
    }

    public function label(): string
    {
        return match ($this->status) {
            self::OBSV_MET => 'ÖBSV-Norm erfüllt',
            self::MQS_MET => 'MQS erfüllt',
            self::MET_ONLY => 'nur MET erreicht',
            self::ESTIMATED_MQS => 'rechnerisch erreicht · kein Qualifikationsnachweis',
            self::NO_STANDARD => 'ohne Norm ausgeschrieben',
            default => 'nicht erreicht',
        };
    }

    /**
     * Der Abstand zur maßgeblichen Norm als Text, z.B. "+0,11 s" oder "−1,47 s".
     *
     * Minuszeichen als U+2212, nicht als Bindestrich: In einer Tabelle mit Zeiten ist ein
     * Bindestrich zu leicht mit einem Trennstrich zu verwechseln.
     */
    public function formattedGap(): ?string
    {
        $abstand = $this->gapToObsv ?? $this->gapToMqs;

        if ($abstand === null) {
            return null;
        }

        return sprintf(
            '%s%s s',
            $abstand < 0 ? "\u{2212}" : '+',
            number_format(abs($abstand) / 100, 2, ',', '.'),
        );
    }
}
