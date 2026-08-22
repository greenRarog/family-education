<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Family;
use App\Models\Message;
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

    public function test_unread_count_returns_unread_messages_from_conversations_where_user_is_response_author(): void
    {
        [$owner, $child] = $this->familyWithUser();
        $responseAuthor = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $responseAuthor->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $conversation = Conversation::factory()->create([
            'advertisement_response_id' => $response->id,
        ]);
        Message::factory()->count(3)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'read_at' => null,
        ]);

        $this->actingAs($responseAuthor)
            ->getJson('/api/conversations/unread-count')
            ->assertOk()
            ->assertJson([
                'count' => 3,
            ]);
    }

    public function test_unread_count_does_not_include_read_messages(): void
    {
        [$owner] = $this->familyWithUser();
        $responseAuthor = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $responseAuthor->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $conversation = Conversation::factory()->create([
            'advertisement_response_id' => $response->id,
        ]);
        Message::factory()->count(2)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'read_at' => null,
        ]);
        Message::factory()->count(5)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'read_at' => now(),
        ]);

        $this->actingAs($responseAuthor)
            ->getJson('/api/conversations/unread-count')
            ->assertOk()
            ->assertJson([
                'count' => 2,
            ]);
    }

    public function test_unread_count_does_not_include_messages_from_other_users_conversations(): void
    {
        [$owner] = $this->familyWithUser();
        $user = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);
        $otherUser = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $userResponse = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $user->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $userConversation = Conversation::factory()->create([
            'advertisement_response_id' => $userResponse->id,
        ]);
        $otherAdvertisement = Advertisement::factory()->create([
            'user_id' => $otherUser->id,
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
        Message::factory()->count(2)->create([
            'conversation_id' => $userConversation->id,
            'user_id' => $owner->id,
            'read_at' => null,
        ]);
        Message::factory()->count(10)->create([
            'conversation_id' => $otherConversation->id,
            'user_id' => $otherUser->id,
            'read_at' => null,
        ]);

        $this->actingAs($user)
            ->getJson('/api/conversations/unread-count')
            ->assertOk()
            ->assertJson([
                'count' => 2,
            ]);
    }

    public function test_unread_count_includes_messages_for_advertisement_owner(): void
    {
        [$owner] = $this->familyWithUser();
        $responseAuthor = User::factory()->create([
            'user_type' => UserType::FAMILY,
        ]);
        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
            'status' => AdvertisementStatus::PUBLISHED,
        ]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $responseAuthor->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $conversation = Conversation::factory()->create([
            'advertisement_response_id' => $response->id,
        ]);
        Message::factory()->count(4)->create([
            'conversation_id' => $conversation->id,
            'user_id' => $responseAuthor->id,
            'read_at' => null,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/conversations/unread-count')
            ->assertOk()
            ->assertJson([
                'count' => 4,
            ]);
    }

    public function test_unread_count_returns_zero_when_there_are_no_unread_messages(): void
    {
        [$user] = $this->familyWithUser();

        $this->actingAs($user)
            ->getJson('/api/conversations/unread-count')
            ->assertOk()
            ->assertJson([
                'count' => 0,
            ]);
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
