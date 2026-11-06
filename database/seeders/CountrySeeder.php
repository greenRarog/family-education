<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class CountrySeeder extends Seeder
{
    public function run(): void
    {
        Country::firstOrCreate(['code' => 'RU'], ['name' => 'Россия']);
        $data = json_decode(File::get(database_path('data/international_cities.json')), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data['countries'] as $country) {
            Country::firstOrCreate(['code' => $country['code']], ['name' => $country['name']]);
        }
    }
}
