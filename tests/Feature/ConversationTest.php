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

class ConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_response_creates_one_conversation(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $this->actingAs($user)
            ->postJson(
                "/api/advertisements/{$advertisement->id}/responses", [
                    'message' => 'Здравствуйте!',
                ])->assertCreated();

        $response = AdvertisementResponse::query()->firstOrFail();
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversations', ['advertisement_response_id' => $response->id]);
    }

    public function test_response_creates_conversation_with_initial_message(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $message = 'Здравствуйте! Расскажите подробнее о занятиях.';

        $this->actingAs($user)
            ->postJson("/api/advertisements/{$advertisement->id}/responses", [
                'message' => $message,
            ])->assertCreated();

        $conversation = Conversation::query()->firstOrFail();
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => $message,
        ]);
    }

    public function test_response_user_can_view_conversation(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($user)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath(
                'conversation.id',
                $conversation->id
            );
    }

    public function test_advertisement_owner_can_view_conversation(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($owner)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath(
                'conversation.id',
                $conversation->id
            );
    }

    public function test_other_user_cannot_view_conversation(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        [$otherUser] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($otherUser)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_guest_cannot_view_conversation(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation(
            $owner,
            $user
        );

        $this->getJson("/api/conversations/{$conversation->id}")
            ->assertUnauthorized();
    }

    public function test_user_can_view_only_own_conversations_in_index(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        [$otherUser] = $this->familyWithUser();
        $ownAdvertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $ownResponse = AdvertisementResponse::factory()->create([
            'advertisement_id' => $ownAdvertisement->id,
            'user_id' => $user->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $ownConversation = Conversation::factory()->create([
            'advertisement_response_id' => $ownResponse->id,
        ]);
        $otherAdvertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $otherResponse = AdvertisementResponse::factory()->create([
            'advertisement_id' => $otherAdvertisement->id,
            'user_id' => $otherUser->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $otherConversation = Conversation::factory()->create([
            'advertisement_response_id' => $otherResponse->id,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/conversations')
            ->assertOk();

        $conversationIds = collect($response->json('conversations'))->pluck('id');
        $this->assertTrue($conversationIds->contains($ownConversation->id));
        $this->assertFalse($conversationIds->contains($otherConversation->id));
    }

    public function test_conversation_can_be_opened_after_advertisement_is_closed(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);
        $conversation->advertisementResponse
            ->advertisement
            ->update([
                'status' => AdvertisementStatus::CLOSED,
            ]);

        $this->actingAs($user)
            ->getJson("/api/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath(
                'conversation.id',
                $conversation->id
            );
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

    private function createConversation(User $owner, User $responseUser): Conversation
    {
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);

        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $responseUser->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        return Conversation::factory()->create([
            'advertisement_response_id' => $response->id,
        ]);
    }
}
