<?php

declare(strict_types=1);

namespace Feature\Notifications;

use App\Enums\AdvertisementResponseStatus;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Message;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use App\Notifications\NewMessageNotification;
use App\Notifications\NewResponseNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_email_notifications_are_queueable(): void
    {
        $user = User::factory()->create();
        $sender = User::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $user->id]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $sender->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        $notifications = [
            new NewResponseNotification($response),
            new NewMessageNotification(
                Message::factory()->create()
            ),
            new AdvertisementExpiredNotification($advertisement),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification);
        }
    }
}
