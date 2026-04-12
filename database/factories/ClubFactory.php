<?php

namespace Database\Factories;

use App\Models\Club;
use App\Models\Nation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Club>
 */
class ClubFactory extends Factory
{
    public function definition(): array
    {
        // Nation direkt anlegen statt über factory() — Nation hat kein HasFactory
        $nation = Nation::firstOrCreate(
            ['code' => 'AUT'],
            ['name_de' => 'Österreich', 'name_en' => 'Austria', 'is_active' => true]
        );

        return [
            'name' => $this->faker->company(),
            'short_name' => strtoupper($this->faker->lexify('???')),
            'code' => strtoupper($this->faker->unique()->lexify()),
            'nation_id' => $nation->id,
        ];
    }
}
