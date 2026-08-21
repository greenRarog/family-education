<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Family;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdvertisementResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_respond_to_published_advertisement(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $this->actingAs($user)
            ->postJson(
                "/api/advertisements/{$advertisement->id}/responses",
                [
                    'message' => 'Здравствуйте! Хотели бы присоединиться.',
                ]
            )
            ->assertCreated()
            ->assertJsonPath(
                'response.status',
                AdvertisementResponseStatus::SENT->value
            );

        $response = AdvertisementResponse::query()->firstOrFail();
        $this->assertDatabaseHas('advertisement_responses', [
            'id' => $response->id,
            'advertisement_id' => $advertisement->id,
            'user_id' => $user->id,
            'status' => AdvertisementResponseStatus::SENT->value,
        ]);
        $this->assertDatabaseHas('conversations', [
            'advertisement_response_id' => $response->id,
        ]);
        $conversation = Conversation::query()->firstOrFail();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Здравствуйте! Хотели бы присоединиться.',
        ]);
    }

    public function test_user_cannot_respond_to_own_advertisement(): void
    {
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $user->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $this->actingAs($user)
            ->postJson(
                "/api/advertisements/{$advertisement->id}/responses",
                [
                    'message' => 'Мой собственный отклик.',
                ]
            )
            ->assertUnprocessable();

        $this->assertDatabaseCount('advertisement_responses', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_user_cannot_respond_to_unpublished_advertisement(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::DRAFT,
        ]);

        $this->actingAs($user)
            ->postJson(
                "/api/advertisements/{$advertisement->id}/responses",
                [
                    'message' => 'Здравствуйте!',
                ]
            )
            ->assertUnprocessable();

        $this->assertDatabaseCount('advertisement_responses', 0);
    }

    public function test_user_cannot_respond_twice_to_same_advertisement(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $this->actingAs($user)
            ->postJson(
                "/api/advertisements/{$advertisement->id}/responses",
                [
                    'message' => 'Первый отклик.',
                ]
            )
            ->assertCreated();

        $this->actingAs($user)
            ->postJson(
                "/api/advertisements/{$advertisement->id}/responses",
                [
                    'message' => 'Второй отклик.',
                ]
            )
            ->assertUnprocessable();
        $this->assertDatabaseCount('advertisement_responses', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_guest_cannot_respond_to_advertisement(): void
    {
        [$owner] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $this->postJson(
            "/api/advertisements/{$advertisement->id}/responses",
            [
                'message' => 'Отклик гостя.',
            ]
        )
            ->assertUnauthorized();

        $this->assertDatabaseCount('advertisement_responses', 0);
    }

    public function test_advertisement_owner_can_accept_response(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $user->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        $this->actingAs($owner)
            ->postJson(
                "/api/advertisement-responses/{$response->id}/accept"
            )
            ->assertOk()
            ->assertJsonPath(
                'response.status',
                AdvertisementResponseStatus::ACCEPTED->value
            );
        $this->assertDatabaseHas('advertisement_responses', [
            'id' => $response->id,
            'status' => AdvertisementResponseStatus::ACCEPTED->value,
        ]);
    }

    public function test_advertisement_owner_can_reject_response(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $user->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        $this->actingAs($owner)
            ->postJson(
                "/api/advertisement-responses/{$response->id}/reject"
            )
            ->assertOk()
            ->assertJsonPath(
                'response.status',
                AdvertisementResponseStatus::REJECTED->value
            );
    }

    public function test_response_user_cannot_accept_or_reject_own_response(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $user->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        $this->actingAs($user)
            ->postJson(
                "/api/advertisement-responses/{$response->id}/accept"
            )
            ->assertForbidden();
        $this->actingAs($user)
            ->postJson(
                "/api/advertisement-responses/{$response->id}/reject"
            )
            ->assertForbidden();
    }

    public function test_response_cannot_be_processed_twice(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $user->id,
            'status' => AdvertisementResponseStatus::ACCEPTED,
        ]);
        $this->actingAs($owner)
            ->postJson(
                "/api/advertisement-responses/{$response->id}/reject"
            )
            ->assertUnprocessable();
    }

    /**
     * @return array{0: User, 1: Family}
     */
    private function familyWithUser(): array
    {
        $user = User::factory()->create();

        $family = Family::factory()->create([
            'user_id' => $user->id,
        ]);

        return [$user, $family];
    }
}
