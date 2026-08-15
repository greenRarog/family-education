<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Family>
 */
class FamilyFactory extends Factory
{
    protected $model = Family::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'surname' => $this->faker->lastName(),
            'city_id' => City::factory(),
            'district_id' => null,
            'metro_station_id' => null,
        ];
    }
}
