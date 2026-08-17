<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use JsonException;

class CitySeeder extends Seeder
{
    /**
     * @throws JsonException
     */
    public function run(): void
    {
        City::truncate();
        $path = database_path('data/russia_cities.json');

        $cities = json_decode(
            File::get($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($cities as $city) {
            City::firstOrCreate([
                'name' => $city['name'],
            ]);
        }
    }
}
