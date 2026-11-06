<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\City;
use App\Models\Country;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CityControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_get_cities(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        City::factory()->create(['country_id' => Country::where('code', 'RU')->sole()->id,
            'name' => 'Москва']);
        City::factory()->create(['country_id' => Country::where('code', 'RU')->sole()->id,
            'name' => 'Санкт-Петербург']);

        $response = $this
            ->actingAs($admin)
            ->getJson('/api/admin/cities');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Москва'])
            ->assertJsonFragment(['country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Санкт-Петербург']);
    }

    public function test_family_cannot_get_cities(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/admin/cities');

        $response->assertForbidden();
    }

    public function test_guest_cannot_get_cities(): void
    {
        $response = $this->getJson('/api/admin/cities');

        $response->assertUnauthorized();
    }

    public function test_admin_can_create_city(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $response = $this
            ->actingAs($admin)
            ->postJson('/api/admin/cities', [
                'country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Краснодар',
            ]);

        $response
            ->assertCreated()
            ->assertJson([
                'country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Краснодар',
            ]);

        $this->assertDatabaseHas('cities', [
            'country_id' => Country::where('code', 'RU')->sole()->id,
            'name' => 'Краснодар',
        ]);
    }

    public function test_admin_can_update_city(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create([
            'country_id' => Country::where('code', 'RU')->sole()->id,
            'name' => 'Краснодар',
        ]);

        $response = $this
            ->actingAs($admin)
            ->putJson("/api/admin/cities/{$city->id}", [
                'country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Новый Краснодар',
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Новый Краснодар',
            ]);

        $this->assertDatabaseHas('cities', [
            'id' => $city->id,
            'country_id' => Country::where('code', 'RU')->sole()->id,
            'name' => 'Новый Краснодар',
        ]);
    }

    public function test_admin_can_delete_city(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create();

        $response = $this
            ->actingAs($admin)
            ->deleteJson("/api/admin/cities/{$city->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('cities', [
            'id' => $city->id,
        ]);
    }

    public function test_family_cannot_create_city(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $response = $this
            ->actingAs($user)
            ->postJson('/api/admin/cities', [
                'country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Краснодар',
            ]);

        $response->assertForbidden();
    }

    public function test_family_cannot_update_city(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $city = City::factory()->create();

        $response = $this
            ->actingAs($user)
            ->putJson("/api/admin/cities/{$city->id}", [
                'country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Новый город',
            ]);

        $response->assertForbidden();
    }

    public function test_family_cannot_delete_city(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $city = City::factory()->create();

        $response = $this
            ->actingAs($user)
            ->deleteJson("/api/admin/cities/{$city->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('cities', [
            'id' => $city->id,
        ]);
    }

    public function test_guest_cannot_create_city(): void
    {
        $response = $this->postJson('/api/admin/cities', [
            'country_id' => Country::where('code', 'RU')->sole()->id,
            'name' => 'Краснодар',
        ]);

        $response->assertUnauthorized();
    }

    public function test_guest_cannot_update_city(): void
    {
        $city = City::factory()->create();

        $response = $this
            ->putJson("/api/admin/cities/{$city->id}", [
                'country_id' => Country::where('code', 'RU')->sole()->id,
                'name' => 'Новый город',
            ]);

        $response->assertUnauthorized();
    }

    public function test_guest_cannot_delete_city(): void
    {
        $city = City::factory()->create();

        $response = $this
            ->deleteJson("/api/admin/cities/{$city->id}");

        $response->assertUnauthorized();
    }
}
