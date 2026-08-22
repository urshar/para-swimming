<?php

namespace App\Support;

use App\Models\BaseTimeDiscipline;
use App\Models\StrokeType;
use Illuminate\Support\Collection;

/**
 * Eine Lagen-Gruppe der öffentlichen Punktetabelle (PointConversionService::buildTable()) —
 * siehe BaseTimeSportClassRow für die Begründung.
 */
final readonly class BaseTimeStrokeGroup
{
    /**
     * @param  Collection<int, BaseTimeDiscipline>  $disciplines
     * @param  Collection<int, BaseTimeSportClassRow>  $rows
     */
    public function __construct(
        public StrokeType $stroke,
        public Collection $disciplines,
        public Collection $rows,
    ) {}
}
