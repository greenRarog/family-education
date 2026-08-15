<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\MetroStation;
use Illuminate\Database\Seeder;

class MetroStationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $moscow = City::where('name', 'Москва')->firstOrFail();
        $spb = City::where('name', 'Санкт-Петербург')->firstOrFail();

        MetroStation::factory()->create([
            'city_id' => $moscow->id,
            'name' => 'Кропоткинская',
        ]);

        MetroStation::factory()->create([
            'city_id' => $moscow->id,
            'name' => 'Парк культуры',
        ]);

        MetroStation::factory()->create([
            'city_id' => $moscow->id,
            'name' => 'Университет',
        ]);

        MetroStation::factory()->create([
            'city_id' => $spb->id,
            'name' => 'Петроградская',
        ]);

        MetroStation::factory()->create([
            'city_id' => $spb->id,
            'name' => 'Горьковская',
        ]);
    }
}
