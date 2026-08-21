<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Family;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_participant_can_send_message(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);
        $conversation->advertisementResponse->update(['status' => AdvertisementResponseStatus::ACCEPTED]);

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'message' => 'Новое сообщение.',
            ])->assertCreated()
            ->assertJsonPath(
                'message.message',
                'Новое сообщение.'
            );

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Новое сообщение.',
        ]);
    }

    public function test_advertisement_owner_can_send_message(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($owner)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'message' => 'Ответ автора объявления.',
            ])->assertCreated();

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'body' => 'Ответ автора объявления.',
        ]);
    }

    public function test_other_user_cannot_send_message_to_conversation(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        [$otherUser] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($otherUser)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'message' => 'Чужое сообщение.',
            ])->assertForbidden();

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $otherUser->id,
        ]);
    }

    public function test_guest_cannot_send_message(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->postJson("/api/conversations/{$conversation->id}/messages", [
            'message' => 'Сообщение гостя.',
        ])->assertUnauthorized();
    }

    public function test_message_requires_body(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'message',
            ]);
    }

    public function test_user_can_mark_other_users_messages_as_read(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'read_at' => null,
        ]);

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/read")
            ->assertOk();

        $this->assertDatabaseMissing('messages', [
            'id' => $message->id,
            'read_at' => null,
        ]);
    }

    public function test_mark_as_read_does_not_mark_own_messages(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'read_at' => null,
        ]);

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/read")
            ->assertOk();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'read_at' => null,
        ]);
    }

    public function test_user_cannot_mark_messages_in_foreign_conversation_as_read(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        [$otherUser] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'read_at' => null,
        ]);

        $this->actingAs($otherUser)
            ->postJson("/api/conversations/{$conversation->id}/read")
            ->assertForbidden();

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'read_at' => null,
        ]);
    }

    public function test_conversation_participant_cannot_send_message_before_response_is_accepted(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($user)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'message' => 'Новое сообщение.',
            ])->assertUnprocessable();

        $this->assertDatabaseMissing('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $user->id,
            'body' => 'Новое сообщение.',
        ]);
    }

    public function test_advertisement_owner_can_send_message_before_response_is_accepted(): void
    {
        [$owner] = $this->familyWithUser();
        [$user] = $this->familyWithUser();
        $conversation = $this->createConversation($owner, $user);

        $this->actingAs($owner)
            ->postJson("/api/conversations/{$conversation->id}/messages", [
                'message' => 'Я получил ваш отклик.',
            ])->assertCreated()
            ->assertJsonPath(
                'message.message',
                'Я получил ваш отклик.'
            );

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
            'body' => 'Я получил ваш отклик.',
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
