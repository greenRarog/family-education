<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\District;
use Illuminate\Database\Seeder;

class DistrictSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $moscow = City::where('name', 'Москва')->firstOrFail();
        $spb = City::where('name', 'Санкт-Петербург')->firstOrFail();
        $krasnodar = City::where('name', 'Краснодар')->firstOrFail();

        District::factory()->create([
            'city_id' => $moscow->id,
            'name' => 'Хамовники',
        ]);

        District::factory()->create([
            'city_id' => $moscow->id,
            'name' => 'Тверской',
        ]);

        District::factory()->create([
            'city_id' => $moscow->id,
            'name' => 'Юго-Запад',
        ]);

        District::factory()->create([
            'city_id' => $spb->id,
            'name' => 'Петроградский',
        ]);

        District::factory()->create([
            'city_id' => $spb->id,
            'name' => 'Центральный',
        ]);

        District::factory()->create([
            'city_id' => $krasnodar->id,
            'name' => 'Центральный',
        ]);

        District::factory()->create([
            'city_id' => $krasnodar->id,
            'name' => 'Северный',
        ]);
    }
}
