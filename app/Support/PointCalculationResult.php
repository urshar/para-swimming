<?php

namespace App\Support;

/**
 * PointCalculationResult
 *
 * Rückgabe von PointConversionService::timeToPoints()/pointsToTime() — ein Wertobjekt statt
 * eines Tupel-Arrays (CLAUDE.md: "Wertobjekte statt assoziativer Arrays"), damit Aufrufer nicht
 * per Listen-Destrukturierung auf eine array-shape-Annotation angewiesen sind.
 */
final readonly class PointCalculationResult
{
    private function __construct(
        public ?int $value,
        public string $errorCode,
    ) {}

    public static function success(int $value): self
    {
        return new self($value, '');
    }

    /** $errorCode siehe lang/*\/public.php point_calculator.errors.* */
    public static function failure(string $errorCode): self
    {
        return new self(null, $errorCode);
    }

    public function failed(): bool
    {
        return $this->value === null;
    }
}
