<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Child;
use App\Models\Family;
use Illuminate\Database\Seeder;

class ChildSeeder extends Seeder
{
    public function run(): void
    {
        $ivanov = Family::where('surname', 'Иванов')->firstOrFail();
        $petrova = Family::where('surname', 'Петрова')->firstOrFail();
        $sokolov = Family::where('surname', 'Соколов')->firstOrFail();

        Child::factory()->create([
            'family_id' => $ivanov->id,
            'name' => 'Михаил',
            'birth_date' => '2016-04-12',
            'sex' => 'male',
        ]);

        Child::factory()->create([
            'family_id' => $ivanov->id,
            'name' => 'Анна',
            'birth_date' => '2019-09-03',
            'sex' => 'female',
        ]);

        Child::factory()->create([
            'family_id' => $petrova->id,
            'name' => 'София',
            'birth_date' => '2015-11-20',
            'sex' => 'female',
        ]);

        Child::factory()->create([
            'family_id' => $sokolov->id,
            'name' => 'Артём',
            'birth_date' => '2020-02-15',
            'sex' => 'male',
        ]);
    }
}
