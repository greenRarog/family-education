<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserType;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            [
                'email' => 'admin@example.com',
            ],
            [
                'name' => 'Administrator',
                'password' => 'Admin123!', // todo убрать в .env
                'user_type' => UserType::ADMIN,
            ],
        );
        $this->call([
            CitySeeder::class,
            DistrictSeeder::class,
            MetroStationSeeder::class,
            FamilySeeder::class,
            ChildSeeder::class,

            SubjectSeeder::class,
            BannedWordSeeder::class,
            AdvertisementSeeder::class,
        ]);
    }
}
