<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\MetroStation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use JsonException;

class MetroStationSeeder extends Seeder
{
    private array $except = [
        'Минск', 'Днепр (Днепропетровск)', 'Харьков', 'Алматы', 'Киев',
    ];

    /**
     * @throws JsonException
     */
    public function run(): void
    {
        MetroStation::truncate();
        $path = database_path('data/metro.json');

        $metros = json_decode(
            File::get($path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($metros as $metro) {
            if (in_array($metro['name'], $this->except, true)) {
                continue;
            }
            $city = City::where('name', $metro['name'])->first();
            if ($city === null) {
                dd($metro);
            }
            foreach ($metro['lines'] as $line) {
                foreach ($line['stations'] as $station) {
                    MetroStation::firstOrCreate([
                        'name' => $station['name'],
                        'city_id' => $city->id,
                    ]);
                }
            }
        }
    }
}
