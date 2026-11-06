<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Advertisement;
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
        $this->assertDatabaseCount('advertisements', 24);
        $this->assertDatabaseMissing('users', ['user_type' => 'admin']);
    }

    public function test_demo_advertisements_are_browsable_in_each_language(): void
    {
        $this->seed(DemoSeeder::class);
        $ids = Advertisement::query()->orderBy('id')->pluck('id')->all();
        $this->seed(DemoSeeder::class);
        $this->assertSame($ids, Advertisement::query()->orderBy('id')->pluck('id')->all());

        foreach (['ru' => 'ТЕСТОВОЕ ОБЪЯВЛЕНИЕ!', 'en' => 'DEMO ADVERTISEMENT!', 'es' => '¡ANUNCIO DE PRUEBA!'] as $language => $prefix) {
            $items = $this->getJson('/api/advertisements/feed?language='.$language)
                ->assertOk()
                ->assertJsonPath('advertisements.total', 8)
                ->assertJsonCount(8, 'advertisements.data')
                ->json('advertisements.data');

            $this->assertEqualsCanonicalizing(
                ['family_to_family', 'family_to_teacher', 'teacher_to_service'],
                array_values(array_unique(array_column($items, 'type'))),
            );

            foreach ($items as $item) {
                $this->assertSame($language, $item['language']);
                $this->assertStringStartsWith($prefix, $item['description']);
                $this->getJson('/api/advertisements/'.$item['id'])
                    ->assertOk()
                    ->assertJsonPath('advertisement.description', $item['description'])
                    ->assertJsonPath('advertisement.language', $language)
                    ->assertJsonMissingPath('advertisement.children');
            }

            // The new language field must combine with the existing filters.
            $this->getJson('/api/advertisements/feed?'.http_build_query([
                'language' => $language,
                'type' => 'family_to_teacher',
                'format' => 'online',
                'age' => 15,
            ]))->assertOk()
                ->assertJsonPath('advertisements.total', 1)
                ->assertJsonPath('advertisements.data.0.language', $language)
                ->assertJsonCount(2, 'advertisements.data.0.subjects');
        }
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
            'advertisement_child',
            'advertisement_subject',
            'countries',
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
