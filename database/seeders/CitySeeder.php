<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\City;
use Illuminate\Database\Seeder;

class CitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        City::factory()->create([
            'name' => 'Москва',
            'fias_id' => '00000000-0000-0000-0000-000000000001',
        ]);

        City::factory()->create([
            'name' => 'Санкт-Петербург',
            'fias_id' => '00000000-0000-0000-0000-000000000002',
        ]);

        City::factory()->create([
            'name' => 'Краснодар',
            'fias_id' => '00000000-0000-0000-0000-000000000003',
        ]);
    }
}
