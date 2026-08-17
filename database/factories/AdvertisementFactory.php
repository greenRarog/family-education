<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Models\Advertisement;
use App\Models\City;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Advertisement>
 */
class AdvertisementFactory extends Factory
{
    protected $model = Advertisement::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => AdvertisementType::FAMILY_TO_FAMILY,
            'status' => AdvertisementStatus::DRAFT,
            'city_id' => City::factory(),
            'district_id' => null,
            'metro_station_id' => null,
            'participant_age_from' => 7,
            'participant_age_to' => 10,
            'description' => $this->faker->paragraph(),
            'published_at' => null,
            'closed_at' => null,
        ];
    }
}
