<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
use App\Models\User;
use Illuminate\Database\Seeder;

class FamilySeeder extends Seeder
{
    public function run(): void
    {
        $moscow = City::where('name', 'Москва')->firstOrFail();
        $spb = City::where('name', 'Санкт-Петербург')->firstOrFail();
        $krasnodar = City::where('name', 'Краснодар')->firstOrFail();

        $khamovniki = District::where('city_id', $moscow->id)
            ->where('name', 'Хамовники')
            ->firstOrFail();

        $centralSpb = District::where('city_id', $spb->id)
            ->where('name', 'Центральный')
            ->firstOrFail();

        $centralKrasnodar = District::where('city_id', $krasnodar->id)
            ->where('name', 'Центральный')
            ->firstOrFail();

        $kropotkinskaya = MetroStation::where('city_id', $moscow->id)
            ->where('name', 'Кропоткинская')
            ->firstOrFail();

        Family::factory()->create([
            'user_id' => User::factory()->create([
                'name' => 'Алексей',
                'email' => 'alexey@example.com',
            ])->id,
            'surname' => 'Иванов',
            'city_id' => $moscow->id,
            'district_id' => $khamovniki->id,
            'metro_station_id' => $kropotkinskaya->id,
        ]);

        Family::factory()->create([
            'user_id' => User::factory()->create([
                'name' => 'Мария',
                'email' => 'maria@example.com',
            ])->id,
            'surname' => 'Петрова',
            'city_id' => $spb->id,
            'district_id' => $centralSpb->id,
            'metro_station_id' => null,
        ]);

        Family::factory()->create([
            'user_id' => User::factory()->create([
                'name' => 'Дмитрий',
                'email' => 'dmitry@example.com',
            ])->id,
            'surname' => 'Соколов',
            'city_id' => $krasnodar->id,
            'district_id' => $centralKrasnodar->id,
            'metro_station_id' => null,
        ]);
    }
}
