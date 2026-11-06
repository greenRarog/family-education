<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use App\Models\Country;
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
        $country = Country::where('code', 'RU')->firstOrFail();
        $path = database_path('data/russia_cities.json');

        $cities = json_decode(
            File::get($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($cities as $city) {
            City::firstOrCreate([
                'country_id' => $country->id,
                'name' => $city['name'],
            ]);
        }

        $data = json_decode(File::get(database_path('data/international_cities.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['countries'] as $entry) {
            $country = Country::where('code', $entry['code'])->firstOrFail();
            foreach ($entry['cities'] as $city) {
                City::firstOrCreate(['country_id' => $country->id, 'name' => $city['name']]);
            }
        }
    }
}
