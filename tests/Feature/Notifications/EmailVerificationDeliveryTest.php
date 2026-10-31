<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\NewResponseNotification;
use App\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public static function notifications(): array
    {
        return [['response'], ['message'], ['expired']];
    }

    public static function deliveryChanges(): array
    {
        $cases = [];
        foreach (['response', 'message', 'expired'] as $type) {
            foreach (['unverified', 'disabled', 'unchanged'] as $change) {
                $cases[] = [$type, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('notifications')]
    public function test_unverified_user_does_not_queue_mail_and_telegram_policy_is_preserved(string $type): void
    {
        [$user, $notification] = $this->notification($type);
        $user->forceFill(['email_verified_at' => null, 'telegram_chat_id' => 'test-chat'])->save();
        $user->notificationSetting()->create(['email_enabled' => true, 'telegram_enabled' => true]);
        $this->assertSame(['telegram'], $notification->via($user));
        $this->assertTrue($notification->shouldSend($user, 'telegram'));
        Queue::fake();
        $user->notify($notification);
        Queue::assertPushed(SendQueuedNotifications::class, fn ($job) => $job->channels === ['telegram']);
        Queue::assertNotPushed(SendQueuedNotifications::class, fn ($job) => in_array('mail', $job->channels, true));
        $user->markEmailAsVerified();
        $this->assertSame(['mail', 'telegram'], $notification->via($user));
        Queue::assertPushed(SendQueuedNotifications::class, 1); // No replay of old mail.
    }

    #[DataProvider('deliveryChanges')]
    public function test_serialized_queue_job_rechecks_recipient_before_actual_mail_delivery(string $type, string $change): void
    {
        [$user, $notification] = $this->notification($type);
        Queue::fake();
        Event::fake([MessageSent::class]);
        $user->notify($notification);
        Queue::assertPushed(SendQueuedNotifications::class, 1);
        $job = Queue::pushed(SendQueuedNotifications::class)->first();
        $serialized = serialize($job);
        if ($change === 'unverified') {
            $user->forceFill(['email_verified_at' => null])->save();
        } elseif ($change === 'disabled') {
            $user->notificationSetting()->create(['email_enabled' => false, 'telegram_enabled' => false]);
        }
        unserialize($serialized)->handle(app(ChannelManager::class));
        if ($change === 'unchanged') {
            Event::assertDispatchedTimes(MessageSent::class, 1);
        } else {
            Event::assertNotDispatched(MessageSent::class);
        }
    }

    public function test_verification_mail_ignores_notification_preferences(): void
    {
        $user = User::factory()->unverified()->create();
        $user->notificationSetting()->create(['email_enabled' => false, 'telegram_enabled' => false]);
        Event::fake([MessageSent::class]);
        $this->actingAs($user)->postJson('/email/verification-notification')->assertStatus(202);
        Event::assertDispatchedTimes(MessageSent::class, 1);
        $this->assertSame(['mail'], (new VerifyEmail)->via($user));
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    private function notification(string $type): array
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $notification = match ($type) {
            'response' => new NewResponseNotification($response),
            'expired' => new AdvertisementExpiredNotification($response->advertisement),
            'message' => new NewMessageNotification(Message::factory()->create([
                'conversation_id' => $conversation->id, 'user_id' => $response->user_id, 'body' => 'Текст',
            ])),
        };

        return [$response->advertisement->user, $notification];
    }
}
