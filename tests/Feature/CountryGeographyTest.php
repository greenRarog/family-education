<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\City;
use App\Models\Country;
use App\Models\Family;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class CountryGeographyTest extends TestCase
{
    use RefreshDatabase;

    private function spain(): Country
    {
        return Country::create(['code' => 'ES', 'name' => 'Испания']);
    }

    public function test_migration_preserves_existing_cities_and_family_references(): void
    {
        $original = DB::getDefaultConnection();
        // SQLite cannot toggle foreign_keys inside RefreshDatabase's transaction.
        // Exercise the real migration lifecycle on a separate, non-transactional store.
        if (DB::getDriverName() === 'sqlite') {
            config(['database.connections.country_migration_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]]);
            DB::setDefaultConnection('country_migration_test');
            Artisan::call('migrate', ['--database' => 'country_migration_test', '--force' => true]);
        }
        try {
            $family = Family::factory()->create();
            $city = $family->city;
            $migration = require database_path('migrations/2026_11_06_120000_add_countries_to_cities.php');
            $migration->down();
            $this->assertFalse(Schema::hasColumn('cities', 'country_id'));
            $legacy = DB::table('cities')->where('id', $city->id)->first();
            $migration->up();
            $this->assertDatabaseHas('cities', ['id' => $legacy->id, 'name' => $legacy->name, 'country_id' => Country::where('code', 'RU')->sole()->id]);
            $this->assertSame($city->id, $family->fresh()->city_id);
            $this->assertSame('RU', $family->fresh()->city->country->code);
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('country_migration_test');
        }
    }

    public function test_rollback_refuses_to_erase_foreign_geography(): void
    {
        $city = City::factory()->create(['country_id' => $this->spain()->id]);
        $migration = require database_path('migrations/2026_11_06_120000_add_countries_to_cities.php');
        try {
            $migration->down();
            $this->fail('A rollback must not silently erase country information.');
        } catch (LogicException) {
            $this->assertTrue(Schema::hasColumn('cities', 'country_id'));
            $this->assertSame('ES', $city->fresh()->country->code);
        }
    }

    public function test_public_directories_include_countries_and_filter_cities(): void
    {
        $ru = City::factory()->create(['name' => 'Same name']);
        $es = City::factory()->create(['name' => 'Same name', 'country_id' => $this->spain()->id]);
        $this->getJson('/api/countries')->assertOk()->assertJsonCount(2)->assertJsonFragment(['code' => 'RU']);
        $this->getJson('/api/cities?country_id='.$es->country_id)->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $es->id)->assertJsonPath('0.country.code', 'ES');
        $this->getJson('/api/cities')->assertOk()->assertJsonCount(2);
        $this->actingAs(User::factory()->create())->getJson('/api/locations')->assertOk()->assertJsonCount(2, 'countries')->assertJsonCount(2, 'cities');
        $this->getJson('/api/cities?country_id=99999')->assertUnprocessable()->assertJsonValidationErrors('country_id');
        $this->assertSame('RU', $ru->country->code);
    }

    public function test_admin_manages_countries_and_country_scoped_city_names(): void
    {
        $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN]));
        $es = $this->postJson('/api/admin/countries', ['code' => 'ES', 'name' => 'Испания'])->assertCreated()->json('id');
        $this->postJson('/api/admin/countries', ['code' => 'ES', 'name' => 'Duplicate'])->assertUnprocessable();
        $this->postJson('/api/admin/countries', ['code' => 'es', 'name' => 'Invalid'])->assertUnprocessable();
        $this->putJson('/api/admin/countries/'.$es, ['code' => 'ES', 'name' => 'España'])->assertOk()->assertJsonPath('name', 'España');
        $ru = City::factory()->create(['name' => 'Same name']);
        $foreign = $this->postJson('/api/admin/cities', ['country_id' => $es, 'name' => $ru->name])
            ->assertCreated()->assertJsonPath('country.code', 'ES')->json('id');
        $this->postJson('/api/admin/cities', ['country_id' => $es, 'name' => $ru->name])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/cities', ['name' => 'No country'])->assertUnprocessable()->assertJsonValidationErrors('country_id');
        $this->putJson('/api/admin/cities/'.$foreign, ['country_id' => $ru->country_id, 'name' => $ru->name])->assertUnprocessable();
        $this->putJson('/api/admin/cities/'.$foreign, ['country_id' => $es, 'name' => 'Renamed'])->assertOk();
        $this->deleteJson('/api/admin/countries/'.$es)->assertUnprocessable();
        $this->deleteJson('/api/admin/cities/'.$foreign)->assertNoContent();
        $this->deleteJson('/api/admin/countries/'.$es)->assertNoContent();
    }

    public function test_country_management_requires_admin(): void
    {
        $ru = Country::where('code', 'RU')->sole();
        $this->getJson('/api/admin/countries')->assertUnauthorized();
        foreach ([UserType::FAMILY, UserType::TEACHER] as $role) {
            $this->actingAs(User::factory()->create(['user_type' => $role]));
            $this->getJson('/api/admin/countries')->assertForbidden();
            $this->postJson('/api/admin/countries', ['code' => 'ES', 'name' => 'España'])->assertForbidden();
            $this->putJson('/api/admin/countries/'.$ru->id, ['code' => 'RU', 'name' => 'Changed'])->assertForbidden();
            $this->deleteJson('/api/admin/countries/'.$ru->id)->assertForbidden();
        }
    }

    public function test_country_filter_and_public_card_use_city_country(): void
    {
        $ru = City::factory()->create();
        $es = City::factory()->create(['country_id' => $this->spain()->id]);
        Advertisement::factory()->create(['city_id' => $ru->id, 'status' => AdvertisementStatus::PUBLISHED]);
        $ad = Advertisement::factory()->create(['city_id' => $es->id, 'status' => AdvertisementStatus::PUBLISHED]);
        $this->getJson('/api/advertisements/feed?country_id='.$es->country_id)->assertOk()
            ->assertJsonPath('advertisements.total', 1)->assertJsonPath('advertisements.data.0.id', $ad->id)
            ->assertJsonPath('advertisements.data.0.city.country.code', 'ES');
        $this->getJson('/api/advertisements/'.$ad->id)->assertOk()->assertJsonPath('advertisement.city.country.code', 'ES');
        $this->getJson('/api/advertisements/feed?country_id='.$ru->country_id.'&city_id='.$es->id)
            ->assertUnprocessable()->assertJsonValidationErrors('city_id');
        $this->getJson('/api/advertisements/feed?country_id=999999')->assertUnprocessable();
    }

    public function test_seeders_remain_russian_even_with_identical_city_names_abroad(): void
    {
        $foreign = City::factory()->create(['name' => 'Москва', 'country_id' => $this->spain()->id]);
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);
        $this->assertDatabaseCount('advertisements', 24);
        $this->assertDatabaseCount('countries', 6);
        $this->assertSame(2, City::where('name', 'Москва')->count());
        $this->assertSame(0, $foreign->districts()->count());
        $this->assertSame(0, $foreign->metroStations()->count());
        $this->assertFalse(Advertisement::whereHas('city.country', fn ($query) => $query->where('code', '!=', 'RU'))->exists());
        $this->assertFalse(Family::whereHas('city.country', fn ($query) => $query->where('code', '!=', 'RU'))->exists());
    }

    public function test_database_enforces_city_country_uniqueness_and_references(): void
    {
        $city = City::factory()->create();
        foreach ([
            fn () => DB::table('cities')->insert(['name' => $city->name, 'country_id' => $city->country_id]),
            fn () => DB::table('cities')->insert(['name' => 'Missing country', 'country_id' => 999999]),
            fn () => DB::table('countries')->where('id', $city->country_id)->delete(),
        ] as $operation) {
            try {
                DB::transaction($operation);
                $this->fail('Invalid geography must be rejected by the database.');
            } catch (QueryException) {
                $this->assertDatabaseHas('cities', ['id' => $city->id, 'country_id' => $city->country_id]);
            }
        }
    }
}
