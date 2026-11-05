<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;

class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data is allowed only in local or testing environments.');
        }

        $this->call([DatabaseSeeder::class, FamilySeeder::class, ChildSeeder::class, AdvertisementSeeder::class]);
    }
}
