<?php

declare(strict_types=1);

namespace Feature\Listeners;

use App\Events\AdvertisementExpired;
use App\Listeners\SendAdvertisementExpiredNotification;
use App\Models\Advertisement;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendAdvertisementExpiredNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_notification_to_advertisement_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $owner->id]);

        (new SendAdvertisementExpiredNotification)->handle(new AdvertisementExpired($advertisement));

        Notification::assertSentTo(
            $owner,
            AdvertisementExpiredNotification::class,
            fn (AdvertisementExpiredNotification $notification) => $notification->advertisement->is($advertisement)
        );
    }
}
