<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\City;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<City>
 */
class CityFactory extends Factory
{
    protected $model = City::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::firstOrCreate(['code' => 'RU'], ['name' => 'Россия'])->id,
            'name' => $this->faker->unique()->city(),
        ];
    }
}
