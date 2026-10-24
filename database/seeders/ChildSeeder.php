<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Child;
use App\Models\Family;
use App\Models\User;
use Illuminate\Database\Seeder;

class ChildSeeder extends Seeder
{
    public function run(): void
    {
        $ivanov = Family::where('user_id', User::where('email', 'alexey@example.com')->value('id'))->firstOrFail();
        $petrova = Family::where('user_id', User::where('email', 'maria@example.com')->value('id'))->firstOrFail();
        $sokolov = Family::where('user_id', User::where('email', 'dmitry@example.com')->value('id'))->firstOrFail();

        foreach ([
            [$ivanov, 'Михаил', '2016-04-12', 'male'],
            [$ivanov, 'Анна', '2019-09-03', 'female'],
            [$petrova, 'София', '2015-11-20', 'female'],
            [$sokolov, 'Артём', '2020-02-15', 'male'],
        ] as [$family, $name, $birthDate, $sex]) {
            Child::query()->updateOrCreate(
                ['family_id' => $family->id, 'name' => $name],
                ['birth_date' => $birthDate, 'sex' => $sex],
            );
        }
    }
}
