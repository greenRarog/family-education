<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
        $admin = User::query()->where('email', 'admin@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('Admin123!', $admin->password));
        $this->assertSame('ru', $admin->locale);
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
