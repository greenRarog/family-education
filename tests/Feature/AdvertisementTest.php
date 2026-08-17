<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\BannedWord;
use App\Models\Child;
use App\Models\City;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertisementTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_can_create_group_advertisement(): void
    {
        [$user, $family, $children] = $this->familyWithChildren(2);
        $city = City::factory()->create();
        $district = District::factory()->create(['city_id' => $city->id]);
        $station = MetroStation::factory()->create(['city_id' => $city->id]);

        $this->actingAs($user)
            ->postJson('/api/advertisements', $this->payload($children, $city, $district, $station))
            ->assertCreated()
            ->assertJsonPath('advertisement.type', AdvertisementType::FAMILY_TO_FAMILY->value)
            ->assertJsonPath('advertisement.status', AdvertisementStatus::DRAFT->value)
            ->assertJsonCount(2, 'advertisement.children');

        $this->assertDatabaseHas('advertisements', [
            'user_id' => $user->id,
            'type' => AdvertisementType::FAMILY_TO_FAMILY->value,
            'status' => AdvertisementStatus::DRAFT->value,
            'city_id' => $city->id,
            'district_id' => $district->id,
            'metro_station_id' => $station->id,
        ]);

        $advertisement = Advertisement::query()->firstOrFail();
        $this->assertDatabaseHas('advertisement_child', ['advertisement_id' => $advertisement->id, 'child_id' => $children[0]->id]);
        $this->assertDatabaseHas('advertisement_child', ['advertisement_id' => $advertisement->id, 'child_id' => $children[1]->id]);
    }

    public function test_group_advertisement_requires_all_creation_fields(): void
    {
        [$user] = $this->familyWithChildren();

        $this->actingAs($user)
            ->postJson('/api/advertisements', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'child_ids',
                'participant_age_from',
                'participant_age_to',
                'city_id',
                'description',
            ]);
    }

    public function test_advertisement_cannot_include_another_familys_child(): void
    {
        [$user] = $this->familyWithChildren();
        [, , $foreignChildren] = $this->familyWithChildren();
        $city = City::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/advertisements', $this->payload($foreignChildren, $city))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['child_ids']);
    }

    public function test_advertisement_description_cannot_contain_banned_word(): void
    {
        [$user, , $children] = $this->familyWithChildren();
        $city = City::factory()->create();
        BannedWord::factory()->create(['word' => 'запрещено']);

        $payload = $this->payload($children, $city);
        $payload['description'] = 'В этом тексте запрещено размещать это слово.';

        $this->actingAs($user)
            ->postJson('/api/advertisements', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);
    }

    public function test_advertisement_description_does_not_match_banned_word_inside_another_word(): void
    {
        [$user, , $children] = $this->familyWithChildren();
        $city = City::factory()->create();
        BannedWord::factory()->create(['word' => 'мат']);

        $payload = $this->payload($children, $city);
        $payload['description'] = 'Ищем занятия по математике.';

        $this->actingAs($user)
            ->postJson('/api/advertisements', $payload)
            ->assertCreated();
    }

    public function test_family_can_update_own_draft_advertisement(): void
    {
        [$user, , $children] = $this->familyWithChildren(2);
        $city = City::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $user->id, 'city_id' => $city->id]);
        $advertisement->children()->attach($children[0]);

        $payload = $this->payload([$children[1]], $city);
        $payload['description'] = 'Обновлённое описание учебной группы.';

        $this->actingAs($user)
            ->putJson("/api/advertisements/{$advertisement->id}", $payload)
            ->assertOk()
            ->assertJsonPath('advertisement.description', $payload['description'])
            ->assertJsonPath('advertisement.children.0.id', $children[1]->id);

        $this->assertDatabaseMissing('advertisement_child', ['advertisement_id' => $advertisement->id, 'child_id' => $children[0]->id]);
    }

    public function test_user_cannot_view_or_edit_another_users_advertisement(): void
    {
        [$owner] = $this->familyWithChildren();
        [$user, , $children] = $this->familyWithChildren();
        $city = City::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $owner->id, 'city_id' => $city->id]);

        $this->actingAs($user)
            ->getJson("/api/advertisements/{$advertisement->id}")
            ->assertForbidden();

        $this->actingAs($user)
            ->putJson("/api/advertisements/{$advertisement->id}", $this->payload($children, $city))
            ->assertForbidden();
    }

    public function test_owner_can_publish_and_close_advertisement(): void
    {
        [$user] = $this->familyWithChildren();
        $advertisement = Advertisement::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->postJson("/api/advertisements/{$advertisement->id}/publish")
            ->assertOk()
            ->assertJsonPath('advertisement.status', AdvertisementStatus::PUBLISHED->value);

        $this->assertDatabaseMissing('advertisements', ['id' => $advertisement->id, 'published_at' => null]);

        $this->actingAs($user)
            ->postJson("/api/advertisements/{$advertisement->id}/close")
            ->assertOk()
            ->assertJsonPath('advertisement.status', AdvertisementStatus::CLOSED->value);

        $this->assertDatabaseMissing('advertisements', ['id' => $advertisement->id, 'closed_at' => null]);
    }

    public function test_closed_advertisement_cannot_be_edited(): void
    {
        [$user, , $children] = $this->familyWithChildren();
        $city = City::factory()->create();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $user->id,
            'city_id' => $city->id,
            'status' => AdvertisementStatus::CLOSED,
        ]);

        $this->actingAs($user)
            ->putJson("/api/advertisements/{$advertisement->id}", $this->payload($children, $city))
            ->assertUnprocessable();
    }

    public function test_non_family_user_cannot_create_group_advertisement(): void
    {
        $user = User::factory()->create(['user_type' => UserType::TEACHER]);

        $this->actingAs($user)
            ->postJson('/api/advertisements', [])
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Family, 2: array<int, Child>}
     */
    private function familyWithChildren(int $count = 1): array
    {
        $user = User::factory()->create(['user_type' => UserType::FAMILY]);
        $family = Family::factory()->create(['user_id' => $user->id]);
        $children = Child::factory()->count($count)->create(['family_id' => $family->id])->all();

        return [$user, $family, $children];
    }

    /**
     * @param  array<int, Child>  $children
     * @return array<string, mixed>
     */
    private function payload(array $children, City $city, ?District $district = null, ?MetroStation $station = null): array
    {
        return [
            'child_ids' => array_map(fn (Child $child) => $child->id, $children),
            'participant_age_from' => 7,
            'participant_age_to' => 10,
            'city_id' => $city->id,
            'district_id' => $district?->id,
            'metro_station_id' => $station?->id,
            'description' => 'Ищем семьи для совместных регулярных занятий.',
        ];
    }
}
