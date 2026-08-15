<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    public function definition(): array
    {
        return [
            'city_id' => City::factory(),
            'name' => fake()->unique()->citySuffix(),
        ];
    }
}
