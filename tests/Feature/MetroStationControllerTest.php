<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\City;
use App\Models\MetroStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetroStationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_user_cannot_access_metro_stations(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $this->actingAs($user)
            ->getJson('/api/admin/metro-stations')
            ->assertForbidden();
    }

    public function test_admin_can_get_metro_stations(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create();

        $station = MetroStation::factory()->create([
            'city_id' => $city->id,
            'name' => 'Центральная',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/metro-stations')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $station->id,
                'name' => 'Центральная',
            ]);
    }

    public function test_admin_can_create_metro_station(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/metro-stations', [
                'city_id' => $city->id,
                'name' => 'Новая станция',
            ])
            ->assertCreated()
            ->assertJson([
                'city_id' => $city->id,
                'name' => 'Новая станция',
            ]);

        $this->assertDatabaseHas('metro_stations', [
            'city_id' => $city->id,
            'name' => 'Новая станция',
        ]);
    }

    public function test_admin_can_update_metro_station(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create();

        $station = MetroStation::factory()->create([
            'city_id' => $city->id,
            'name' => 'Старая станция',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/metro-stations/{$station->id}", [
                'city_id' => $city->id,
                'name' => 'Новая станция',
            ])
            ->assertOk()
            ->assertJson([
                'id' => $station->id,
                'city_id' => $city->id,
                'name' => 'Новая станция',
            ]);

        $this->assertDatabaseHas('metro_stations', [
            'id' => $station->id,
            'name' => 'Новая станция',
        ]);
    }

    public function test_admin_can_delete_metro_station(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $station = MetroStation::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/metro-stations/{$station->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('metro_stations', [
            'id' => $station->id,
        ]);
    }
}
