<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\City;
use App\Models\Country;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternationalCitySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_seed_adds_the_agreed_countries_and_preserves_existing_city_ids(): void
    {
        $russian = City::factory()->create(['name' => 'Москва']);
        $spain = Country::create(['code' => 'ES', 'name' => 'España']);
        $madrid = City::create(['country_id' => $spain->id, 'name' => 'Madrid']);
        $this->seed(DatabaseSeeder::class);
        $ids = City::orderBy('id')->pluck('id', 'name')->all();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame($ids, City::orderBy('id')->pluck('id', 'name')->all());
        $this->assertEqualsCanonicalizing(['RU', 'ES', 'DE', 'PL', 'GE', 'RS'], Country::pluck('code')->all());
        foreach (['ES' => 50, 'DE' => 50, 'PL' => 50, 'GE' => 20, 'RS' => 30] as $code => $count) {
            $country = Country::where('code', $code)->sole();
            $this->assertSame($count, $country->cities()->count(), $code);
            $this->getJson('/api/cities?country_id='.$country->id)->assertOk()->assertJsonCount($count)
                ->assertJsonPath('0.country.code', $code);
        }
        $this->assertSame($russian->id, City::whereRelation('country', 'code', 'RU')->where('name', 'Москва')->sole()->id);
        $this->assertSame($madrid->id, City::whereRelation('country', 'code', 'ES')->where('name', 'Madrid')->sole()->id);
        $this->assertSame('España', $spain->fresh()->name);
        foreach (['Málaga', 'München', 'Łódź', 'Tbilisi', 'Niš'] as $name) {
            $this->assertDatabaseHas('cities', ['name' => $name]);
        }
        foreach (['Ciudad Lineal', 'Chamartín', 'Mokotów', 'Novi Beograd'] as $district) {
            $this->assertDatabaseMissing('cities', ['name' => $district]);
        }
        $this->assertDatabaseCount('users', 0);
    }
}
