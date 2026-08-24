<?php

declare(strict_types=1);

namespace Feature\Listeners;

use App\Enums\AdvertisementResponseStatus;
use App\Events\NewMessage;
use App\Listeners\SendNewMessageNotification;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendNewMessageNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_from_response_author_notifies_advertisement_owner(): void
    {
        Notification::fake();
        [$owner, $responseAuthor, $conversation] = $this->createConversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $responseAuthor->id,
        ]);

        (new SendNewMessageNotification)->handle(new NewMessage($message));

        Notification::assertSentTo(
            $owner,
            NewMessageNotification::class,
            fn (NewMessageNotification $notification) => $notification->message->is($message)
        );
        Notification::assertNotSentTo($responseAuthor, NewMessageNotification::class);
    }

    public function test_message_from_advertisement_owner_notifies_response_author(): void
    {
        Notification::fake();
        [$owner, $responseAuthor, $conversation] = $this->createConversation();
        $message = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $owner->id,
        ]);

        (new SendNewMessageNotification)->handle(new NewMessage($message));

        Notification::assertSentTo(
            $responseAuthor,
            NewMessageNotification::class,
            fn (NewMessageNotification $notification) => $notification->message->is($message)
        );
        Notification::assertNotSentTo($owner, NewMessageNotification::class);
    }

    /**
     * @return array{User, User, Conversation}
     */
    private function createConversation(): array
    {
        $owner = User::factory()->create();
        $responseAuthor = User::factory()->create();

        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
        ]);

        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $responseAuthor->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        $conversation = Conversation::factory()->create([
            'advertisement_response_id' => $response->id,
        ]);

        return [
            $owner,
            $responseAuthor,
            $conversation,
        ];
    }
}
