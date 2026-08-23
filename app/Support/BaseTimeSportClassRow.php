<?php

namespace App\Support;

use App\Models\BaseTimeSportClass;

/**
 * Eine Zeile der öffentlichen Punktetabelle-Matrix (PointConversionService::buildTable()) —
 * ersetzt ein anonymes (object) [...], das PhpStorm nicht typisieren kann (jeder Zugriff auf
 * →sportClass/→cells an den Verbrauchsstellen wurde als "Potentially polymorphic call"
 * gemeldet). CLAUDE.md: "Wertobjekte statt assoziativer Arrays".
 */
final readonly class BaseTimeSportClassRow
{
    /** @param  array<string, array<int, ?string>>  $cells  [Geschlecht][disciplineId] => formatierte Zeit oder null */
    public function __construct(
        public BaseTimeSportClass $sportClass,
        public array $cells,
    ) {}
}
