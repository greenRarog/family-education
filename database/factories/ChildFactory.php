<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\Child;
use App\Models\Family;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Child>
 */
class ChildFactory extends Factory
{
    protected $model = Child::class;

    public function definition(): array
    {
        return [
            'family_id' => Family::factory(),
            'name' => $this->faker->firstName(),
            'birth_date' => $this->faker->dateTimeBetween('-17 years', '-1 year'),
            'sex' => $this->faker->randomElement(Sex::cases()),
        ];
    }
}
