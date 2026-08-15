<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use App\Models\MetroStation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetroStation>
 */
class MetroStationFactory extends Factory
{
    protected $model = MetroStation::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => $this->faker->unique()->words(2, true),
        ];
    }
}
