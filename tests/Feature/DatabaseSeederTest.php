<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_can_run_repeatedly_without_duplicates(): void
    {
        Artisan::call('db:seed', ['--force' => true]);
        $firstRunCounts = $this->seededRecordCounts();

        Artisan::call('db:seed', ['--force' => true]);

        $this->assertSame($firstRunCounts, $this->seededRecordCounts());
        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseCount('families', 0);
        $this->assertDatabaseCount('children', 0);
        $this->assertDatabaseCount('advertisements', 0);
        $this->assertGreaterThan(0, DB::table('cities')->count());
        $this->assertGreaterThan(0, DB::table('subjects')->count());
    }

    public function test_demo_data_is_explicit_and_repeatable(): void
    {
        $this->seed(DemoSeeder::class);
        $counts = $this->seededRecordCounts();
        $this->seed(DemoSeeder::class);
        $this->assertSame($counts, $this->seededRecordCounts());
        $this->assertSame(3, User::query()->count());
        $this->assertGreaterThan(0, DB::table('advertisements')->count());
        $this->assertDatabaseMissing('users', ['user_type' => 'admin']);
    }

    public function test_demo_seeder_refuses_production_before_writing(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->seed(DemoSeeder::class);
            $this->fail('Production demo seed should fail.');
        } catch (LogicException) {
            $this->assertDatabaseCount('users', 0);
            $this->assertDatabaseCount('cities', 0);
        }
    }

    /** @return array<string, int> */
    private function seededRecordCounts(): array
    {
        return collect([
            'users',
            'families',
            'children',
            'advertisements',
            'cities',
            'districts',
            'metro_stations',
            'subjects',
            'banned_words',
        ])->mapWithKeys(fn (string $table) => [
            $table => DB::table($table)->count(),
        ])->all();
    }
}
