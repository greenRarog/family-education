<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Child;
use App\Models\City;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_get_family(): void
    {
        $user = User::factory()->create();

        $city = City::factory()->create();

        $district = District::factory()->create([
            'city_id' => $city->id,
        ]);

        $metroStation = MetroStation::factory()->create([
            'city_id' => $city->id,
        ]);

        $family = Family::factory()->create([
            'user_id' => $user->id,
            'surname' => 'Иванов',
            'city_id' => $city->id,
            'district_id' => $district->id,
            'metro_station_id' => $metroStation->id,
        ]);

        $child = Child::factory()->create([
            'family_id' => $family->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/family');

        $response
            ->assertSuccessful()
            ->assertJson([
                'family' => [
                    'id' => $family->id,
                    'user_id' => $user->id,
                    'surname' => 'Иванов',
                    'city_id' => $city->id,
                    'district_id' => $district->id,
                    'metro_station_id' => $metroStation->id,
                    'children' => [
                        [
                            'id' => $child->id,
                            'family_id' => $family->id,
                            'name' => $child->name,
                            'birth_date' => $child->birth_date->toISOString(),
                            'sex' => $child->sex->value,
                        ],
                    ],
                ],
            ]);
    }

    public function test_authenticated_user_can_update_family_and_children(): void
    {
        $user = User::factory()->create();

        $oldCity = City::factory()->create();

        $family = Family::factory()->create([
            'user_id' => $user->id,
            'city_id' => $oldCity->id,
        ]);

        $childToUpdate = Child::factory()->create([
            'family_id' => $family->id,
            'name' => 'Старое имя',
        ]);

        $childToDelete = Child::factory()->create([
            'family_id' => $family->id,
            'name' => 'Будет удалён',
        ]);

        $city = City::factory()->create();

        $district = District::factory()->create([
            'city_id' => $city->id,
        ]);

        $metroStation = MetroStation::factory()->create([
            'city_id' => $city->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson('/api/family', [
                'surname' => 'Петров',
                'city_id' => $city->id,
                'district_id' => $district->id,
                'metro_station_id' => $metroStation->id,

                'children' => [
                    [
                        'id' => $childToUpdate->id,
                        'name' => 'Новое имя',
                        'birth_date' => '2020-05-15 00:00:00',
                        'sex' => $childToUpdate->sex->value,
                    ],
                    [
                        'name' => 'Новый ребёнок',
                        'birth_date' => '2022-10-20 00:00:00',
                        'sex' => $childToUpdate->sex->value,
                    ],
                ],
            ]);

        $response
            ->assertSuccessful()
            ->assertJsonPath('family.id', $family->id)
            ->assertJsonPath('family.user_id', $user->id)
            ->assertJsonPath('family.surname', 'Петров')
            ->assertJsonPath('family.city_id', $city->id)
            ->assertJsonPath('family.district_id', $district->id)
            ->assertJsonPath('family.metro_station_id', $metroStation->id);

        $response->assertJsonCount(2, 'family.children');

        $this->assertDatabaseHas('families', [
            'id' => $family->id,
            'surname' => 'Петров',
            'city_id' => $city->id,
            'district_id' => $district->id,
            'metro_station_id' => $metroStation->id,
        ]);

        $this->assertDatabaseHas('children', [
            'id' => $childToUpdate->id,
            'family_id' => $family->id,
            'name' => 'Новое имя',
            'birth_date' => '2020-05-15 00:00:00',
        ]);

        $this->assertDatabaseMissing('children', [
            'id' => $childToDelete->id,
        ]);

        $this->assertDatabaseHas('children', [
            'family_id' => $family->id,
            'name' => 'Новый ребёнок',
            'birth_date' => '2022-10-20 00:00:00',
        ]);
    }

    public function test_guest_cannot_get_family(): void
    {
        $response = $this->getJson('/api/family');

        $response->assertUnauthorized();
    }

    public function test_guest_cannot_update_family(): void
    {
        $response = $this->putJson('/api/family', [
            'surname' => 'Петров',
            'city_id' => City::factory()->create()->id,
            'children' => [
                [
                    'name' => 'Ребёнок',
                    'birth_date' => '2020-01-01 00:00:00',
                    'sex' => 'male',
                ],
            ],
        ]);

        $response->assertUnauthorized();
    }

    public function test_family_update_requires_required_fields(): void
    {
        $user = User::factory()->create();

        Family::factory()->create([
            'user_id' => $user->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson('/api/family', []);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'surname',
                'city_id',
                'children',
            ]);
    }

    public function test_family_requires_at_least_one_child(): void
    {
        $user = User::factory()->create();

        Family::factory()->create([
            'user_id' => $user->id,
        ]);

        $city = City::factory()->create();

        $response = $this
            ->actingAs($user)
            ->putJson('/api/family', [
                'surname' => 'Петров',
                'city_id' => $city->id,
                'children' => [],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['children']);
    }

    public function test_family_cannot_use_district_from_another_city(): void
    {
        $user = User::factory()->create();

        Family::factory()->create([
            'user_id' => $user->id,
        ]);

        $city = City::factory()->create();
        $anotherCity = City::factory()->create();

        $district = District::factory()->create([
            'city_id' => $anotherCity->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson('/api/family', [
                'surname' => 'Петров',
                'city_id' => $city->id,
                'district_id' => $district->id,
                'children' => [
                    [
                        'name' => 'Ребёнок',
                        'birth_date' => '2020-01-01 00:00:00',
                        'sex' => 'male',
                    ],
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['district_id']);
    }

    public function test_family_cannot_use_metro_station_from_another_city(): void
    {
        $user = User::factory()->create();

        Family::factory()->create([
            'user_id' => $user->id,
        ]);

        $city = City::factory()->create();
        $anotherCity = City::factory()->create();

        $metroStation = MetroStation::factory()->create([
            'city_id' => $anotherCity->id,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson('/api/family', [
                'surname' => 'Петров',
                'city_id' => $city->id,
                'metro_station_id' => $metroStation->id,
                'children' => [
                    [
                        'name' => 'Ребёнок',
                        'birth_date' => '2020-01-01 00:00:00',
                        'sex' => 'male',
                    ],
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['metro_station_id']);
    }

    public function test_family_cannot_update_child_of_another_family(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();

        $family = Family::factory()->create([
            'user_id' => $user->id,
        ]);

        $anotherFamily = Family::factory()->create([
            'user_id' => $anotherUser->id,
        ]);

        $foreignChild = Child::factory()->create([
            'family_id' => $anotherFamily->id,
        ]);

        $city = City::factory()->create();

        $response = $this
            ->actingAs($user)
            ->putJson('/api/family', [
                'surname' => 'Петров',
                'city_id' => $city->id,
                'children' => [
                    [
                        'id' => $foreignChild->id,
                        'name' => 'Попытка изменить чужого ребёнка',
                        'birth_date' => '2020-01-01 00:00:00',
                        'sex' => $foreignChild->sex->value,
                    ],
                ],
            ]);

        $response->assertNotFound();

        $this->assertDatabaseHas('children', [
            'id' => $foreignChild->id,
            'family_id' => $anotherFamily->id,
            'name' => $foreignChild->name,
        ]);

        $this->assertDatabaseHas('families', [
            'id' => $family->id,
            'surname' => $family->surname,
        ]);
    }
}
