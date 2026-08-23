<?php

namespace App\Support;

use App\Models\BaseTime;

/** Rückgabe von PointConversionService::resolveBaseTime() — siehe PointCalculationResult. */
final readonly class BaseTimeLookupResult
{
    private function __construct(
        public ?BaseTime $baseTime,
        public string $errorCode,
    ) {}

    public static function found(BaseTime $baseTime): self
    {
        return new self($baseTime, '');
    }

    /** $errorCode siehe lang/*\/public.php point_calculator.errors.* */
    public static function missing(string $errorCode): self
    {
        return new self(null, $errorCode);
    }
}
