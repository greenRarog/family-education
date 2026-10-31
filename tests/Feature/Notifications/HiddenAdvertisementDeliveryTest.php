<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\UserType;
use App\Events\NewResponse;
use App\Listeners\SendNewResponseNotification;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Notifications\NewResponseNotification;
use App\Services\AdvertisementModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HiddenAdvertisementDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public static function cases(): array
    {
        return [['response', false, false], ['response', true, false], ['response', true, true], ['message', false, false], ['message', true, false]];
    }

    #[DataProvider('cases')]
    public function test_delivery_cancels_responses_permanently_but_keeps_old_messages(string $type, bool $restore, bool $legacy): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $ad = $response->advertisement;
        $owner = $ad->user;
        $owner->update(['telegram_chat_id' => 'test-chat']);
        $owner->notificationSetting()->create(['email_enabled' => true, 'telegram_enabled' => true]);
        $notification = $type === 'response' ? new NewResponseNotification($response)
            : new NewMessageNotification(Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $response->user_id]));
        Queue::fake();
        Event::fake([MessageSent::class]);
        $owner->notify($notification);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $jobs = Queue::pushed(SendQueuedNotifications::class)->map(function ($job) use ($legacy) {
            if ($legacy) {
                (function (): void {
                    unset($this->hideVersion);
                })->call($job->notification);
            }

            return serialize($job);
        });
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $service = app(AdvertisementModerationService::class);
        $service->change($admin, $ad, true, ['reason_code' => 'spam', 'reason' => 'Hide before delivery.']);
        if ($restore) {
            $service->change($admin, $ad, false, ['reason' => 'Correction reviewed']);
        }
        $telegram = new class
        {
            public int $deliveries = 0;

            public function send($notifiable, $notification): void
            {
                $notification->toTelegram($notifiable);
                $this->deliveries++;
            }
        };
        $manager = app(ChannelManager::class);
        $manager->extend('telegram', fn () => $telegram);
        foreach ($jobs as $job) {
            unserialize($job)->handle($manager);
        }
        Event::assertDispatchedTimes(MessageSent::class, $type === 'message' ? 1 : 0);
        $this->assertSame($type === 'message' ? 1 : 0, $telegram->deliveries);
    }

    public function test_hidden_ad_does_not_enqueue_response_even_with_stale_models(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        app(AdvertisementModerationService::class)->change(User::factory()->create(['user_type' => UserType::ADMIN]), $response->advertisement, true, ['reason_code' => 'spam', 'reason' => 'Hide before enqueue.']);
        Queue::fake();
        $owner->notify(new NewResponseNotification($response));
        Queue::assertNothingPushed();
    }

    public function test_hide_restore_between_response_commit_and_listener_does_not_queue_old_response(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $event = new NewResponse($response);
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $service = app(AdvertisementModerationService::class);
        $service->change($admin, $response->advertisement, true, ['reason_code' => 'spam', 'reason' => 'Hidden before the listener runs.']);
        $service->change($admin, $response->advertisement, false, ['reason' => 'Review']);
        Queue::fake();
        app(SendNewResponseNotification::class)->handle($event);
        Queue::assertNothingPushed();
        // A new event after restore is allowed, even in the same timestamp second.
        app(SendNewResponseNotification::class)->handle(new NewResponse($response));
        Queue::assertPushed(SendQueuedNotifications::class);
    }
}
