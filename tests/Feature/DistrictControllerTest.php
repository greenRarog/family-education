<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\UserType;
use App\Models\City;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DistrictControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_user_cannot_access_districts(): void
    {
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);

        $this->actingAs($user)
            ->getJson('/api/admin/districts')
            ->assertForbidden();
    }

    public function test_admin_can_get_districts(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create();

        $district = District::factory()->create([
            'city_id' => $city->id,
            'name' => 'Центральный район',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/admin/districts')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $district->id,
                'name' => 'Центральный район',
            ]);
    }

    public function test_admin_can_create_district(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create();

        $this->actingAs($admin)
            ->postJson('/api/admin/districts', [
                'city_id' => $city->id,
                'name' => 'Новый район',
            ])
            ->assertCreated()
            ->assertJson([
                'city_id' => $city->id,
                'name' => 'Новый район',
            ]);

        $this->assertDatabaseHas('districts', [
            'city_id' => $city->id,
            'name' => 'Новый район',
        ]);
    }

    public function test_admin_can_update_district(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $city = City::factory()->create();

        $district = District::factory()->create([
            'city_id' => $city->id,
            'name' => 'Старый район',
        ]);

        $this->actingAs($admin)
            ->putJson("/api/admin/districts/{$district->id}", [
                'city_id' => $city->id,
                'name' => 'Новый район',
            ])
            ->assertOk()
            ->assertJson([
                'id' => $district->id,
                'city_id' => $city->id,
                'name' => 'Новый район',
            ]);

        $this->assertDatabaseHas('districts', [
            'id' => $district->id,
            'name' => 'Новый район',
        ]);
    }

    public function test_admin_can_delete_district(): void
    {
        $admin = User::factory()->create([
            'user_type' => UserType::ADMIN,
        ]);

        $district = District::factory()->create();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/districts/{$district->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('districts', [
            'id' => $district->id,
        ]);
    }
}
