<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\UserType;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\NewResponseNotification;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModerationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public static function deliveries(): array
    {
        $cases = [];
        foreach (['message', 'response', 'expired'] as $type) {
            foreach (['unchanged', 'recipient', 'other', 'recipient_cycle', 'other_cycle', 'legacy_unchanged', 'legacy_cycle'] as $change) {
                $cases[$type.' '.$change] = [$type, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('deliveries')]
    public function test_serialized_jobs_recheck_both_channels_and_never_replay_after_unblock(string $type, string $change): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $recipient = $response->advertisement->user;
        $recipient->update(['telegram_chat_id' => 'test-chat']);
        $recipient->notificationSetting()->create(['email_enabled' => true, 'telegram_enabled' => true]);
        $notification = match ($type) {
            'message' => new NewMessageNotification(Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $response->user_id])),
            'response' => new NewResponseNotification($response),
            'expired' => new AdvertisementExpiredNotification($response->advertisement),
        };
        Queue::fake();
        Event::fake([MessageSent::class]);
        $recipient->notify($notification);
        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $jobs = Queue::pushed(SendQueuedNotifications::class)->map(function ($job) use ($change) {
            if (str_starts_with($change, 'legacy_')) {
                // Reproduce the notification shape serialized by the pre-C05 code.
                (function (): void {
                    unset($this->moderationVersion, $this->moderationUserIds);
                })->call($job->notification);
            }

            return serialize($job);
        });
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $target = str_starts_with($change, 'other') ? $response->user : $recipient;
        if (! in_array($change, ['unchanged', 'legacy_unchanged'])) {
            app(UserBlockService::class)->block($admin, $target, ['reason_code' => 'spam', 'reason' => 'Block before delivery.']);
            if (str_ends_with($change, '_cycle')) {
                app(UserBlockService::class)->unblock($admin, $target, ['reason' => 'Review']);
            }
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
        $allowed = in_array($change, ['unchanged', 'legacy_unchanged']) || ($type === 'expired' && str_starts_with($change, 'other'));
        Event::assertDispatchedTimes(MessageSent::class, $allowed ? 1 : 0);
        $this->assertSame($allowed ? 1 : 0, $telegram->deliveries);
        if (! $allowed) {
            // The same old job remains suppressed even if a worker retries after unblock.
            app(UserBlockService::class)->unblock($admin, $target, ['reason' => 'Review']);
            foreach ($jobs as $job) {
                unserialize($job)->handle($manager);
            }
            Event::assertNotDispatched(MessageSent::class);
            $this->assertSame(0, $telegram->deliveries);
        }
    }

    public function test_stale_recipient_does_not_queue_ordinary_notifications_while_blocked(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        app(UserBlockService::class)->block($admin, $owner, ['reason_code' => 'spam', 'reason' => 'Block before enqueue.']);
        Queue::fake();
        $owner->notify(new NewResponseNotification($response));
        $owner->notify(new AdvertisementExpiredNotification($response->advertisement));
        $owner->notify(new NewMessageNotification(Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $response->user_id])));
        Queue::assertNothingPushed();
    }
}
