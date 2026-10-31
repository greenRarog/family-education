<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Events\NewMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function viewers(): iterable
    {
        foreach (['owner', 'respondent', 'admin owner', 'admin respondent', 'outsider family', 'outsider teacher', 'outsider admin'] as $viewer) {
            foreach (AdvertisementResponseStatus::cases() as $status) {
                yield "$viewer: {$status->value}" => [$viewer, $status];
            }
        }
    }

    #[DataProvider('viewers')]
    public function test_existing_conversation_access_depends_on_participation(string $kind, AdvertisementResponseStatus $status): void
    {
        $this->freezeTime();
        Event::fake([NewMessage::class]);
        $conversation = $this->conversation($status);
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $respondent = $response->user;
        $isOwner = str_ends_with($kind, 'owner');
        $isRespondent = str_ends_with($kind, 'respondent');
        $participant = $isOwner || $isRespondent;
        $viewer = $isOwner ? $owner : ($isRespondent ? $respondent : User::factory()->unverified()->create());
        $viewer->update(['user_type' => str_contains($kind, 'admin') ? UserType::ADMIN : ($kind === 'outsider teacher' ? UserType::TEACHER : UserType::FAMILY)]);
        $beforeConversation = $conversation->fresh()->getAttributes();
        $beforeMessages = Message::query()->orderBy('id')->get()->map->getAttributes()->all();
        $base = "/api/conversations/{$conversation->id}";
        $this->actingAs($viewer);

        foreach ([$base, "$base/messages"] as $url) {
            $result = $this->getJson($url);
            if ($participant) {
                $key = $url === $base ? 'conversation.messages' : 'messages';
                $result->assertOk()->assertJsonCount(2, $key)->assertJsonPath("$key.0.message", 'Private owner message');
            } else {
                $result->assertForbidden()->assertJsonMissingPath('conversation')->assertJsonMissingPath('messages');
            }
        }
        $this->assertSame($beforeMessages, Message::query()->orderBy('id')->get()->map->getAttributes()->all());
        $read = $this->postJson("$base/read", ['through_id' => Message::query()->max('id')]);
        if ($participant) {
            $read->assertOk();
            foreach (Message::all() as $message) {
                $this->assertSame($message->user_id !== $viewer->id, $message->read_at !== null);
            }
        } else {
            $read->assertForbidden();
            $this->assertSame($beforeMessages, Message::query()->orderBy('id')->get()->map->getAttributes()->all());
        }

        $send = $this->postJson("$base/messages", ['message' => 'New message']);
        $canSend = $participant && $status !== AdvertisementResponseStatus::REJECTED
            && ($isOwner || $status === AdvertisementResponseStatus::ACCEPTED);
        if ($canSend) {
            $send->assertCreated()->assertJsonPath('message.message', 'New message');
            $this->assertDatabaseHas('messages', ['conversation_id' => $conversation->id, 'user_id' => $viewer->id, 'body' => 'New message']);
            $this->assertDatabaseCount('messages', 3);
            Event::assertDispatchedTimes(NewMessage::class, 1);
        } else {
            $send->assertStatus($participant ? 422 : 403);
            if ($participant) {
                $send->assertJsonPath('message', $status === AdvertisementResponseStatus::REJECTED
                    ? 'Диалог завершён.' : 'Нельзя отправлять сообщения до принятия отклика.');
            }
            $this->assertDatabaseCount('messages', 2);
            $this->assertSame($beforeConversation, $conversation->fresh()->getAttributes());
            Event::assertNotDispatched(NewMessage::class);
        }
    }

    public function test_guests_are_unauthorized_on_all_four_endpoints(): void
    {
        Event::fake([NewMessage::class]);
        $conversation = $this->conversation();
        $base = "/api/conversations/{$conversation->id}";
        $this->getJson($base)->assertUnauthorized();
        $this->getJson("$base/messages")->assertUnauthorized();
        $this->postJson("$base/messages", ['message' => 'Guest message'])->assertUnauthorized();
        $this->postJson("$base/read")->assertUnauthorized();
        $this->assertDatabaseCount('messages', 2);
        $this->assertSame(2, Message::query()->whereNull('read_at')->count());
        Event::assertNotDispatched(NewMessage::class);
    }

    public function test_authorization_keeps_its_existing_order_relative_to_validation(): void
    {
        Event::fake([NewMessage::class]);
        $conversation = $this->conversation();
        $base = "/api/conversations/{$conversation->id}";
        $this->actingAs(User::factory()->create());
        $this->getJson("$base?after_id=invalid")->assertForbidden();
        $this->getJson("$base/messages?before_id=invalid")->assertForbidden();
        $this->postJson("$base/read", ['through_id' => 'invalid'])->assertForbidden();
        // StoreMessageRequest validates before the controller checks participation.
        $this->postJson("$base/messages", [])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->actingAs($conversation->advertisementResponse->user);
        $this->getJson("$base?after_id=invalid")->assertUnprocessable()->assertJsonValidationErrors('after_id');
        $this->getJson("$base/messages?before_id=invalid")->assertUnprocessable()->assertJsonValidationErrors('before_id');
        $this->postJson("$base/read", ['through_id' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('through_id');
        $this->assertDatabaseCount('messages', 2);
        $this->assertSame(2, Message::query()->whereNull('read_at')->count());
        Event::assertNotDispatched(NewMessage::class);
    }

    public function test_missing_conversations_remain_not_found_for_authenticated_users(): void
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/conversations/999999')->assertNotFound();
        $this->getJson('/api/conversations/999999/messages')->assertNotFound();
        $this->postJson('/api/conversations/999999/messages', ['message' => 'Missing'])->assertNotFound();
        $this->postJson('/api/conversations/999999/read')->assertNotFound();
        $this->assertDatabaseCount('messages', 0);
    }

    private function conversation(AdvertisementResponseStatus $status = AdvertisementResponseStatus::SENT): Conversation
    {
        $conversation = Conversation::factory()->create(['updated_at' => now()->subHour()]);
        $response = $conversation->advertisementResponse;
        $response->update(['status' => $status]);
        $response->advertisement->update(['status' => AdvertisementStatus::CLOSED]);
        $response->advertisement->user->update(['email_verified_at' => null]);
        $response->user->update(['email_verified_at' => null]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $response->advertisement->user_id, 'body' => 'Private owner message', 'read_at' => null]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $response->user_id, 'body' => 'Private respondent message', 'read_at' => null]);

        return $conversation;
    }
}
