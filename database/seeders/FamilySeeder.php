<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\City;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

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

        $families = [
            ['Алексей', 'alexey@example.com', 'Иванов', $moscow, $khamovniki, $kropotkinskaya],
            ['Мария', 'maria@example.com', 'Петрова', $spb, $centralSpb, null],
            ['Дмитрий', 'dmitry@example.com', 'Соколов', $krasnodar, $centralKrasnodar, null],
        ];

        foreach ($families as [$name, $email, $surname, $city, $district, $station]) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Str::random(48),
                    'user_type' => UserType::FAMILY,
                ],
            );

            Family::query()->updateOrCreate(
                ['user_id' => $user->id],
                [
                    'surname' => $surname,
                    'city_id' => $city->id,
                    'district_id' => $district->id,
                    'metro_station_id' => $station?->id,
                ],
            );
        }
    }
}
