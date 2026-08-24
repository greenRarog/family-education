<?php

declare(strict_types=1);

namespace Feature\Listeners;

use App\Enums\AdvertisementResponseStatus;
use App\Events\NewResponse;
use App\Listeners\SendNewResponseNotification;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\User;
use App\Notifications\NewResponseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendNewResponseNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_notification_to_advertisement_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $responseAuthor = User::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $owner->id]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $responseAuthor->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);
        $event = new NewResponse($response);

        (new SendNewResponseNotification)->handle($event);

        Notification::assertSentTo(
            $owner,
            NewResponseNotification::class,
            function (NewResponseNotification $notification) use ($response) {
                return $notification->response->is($response);
            }
        );
        Notification::assertNotSentTo($responseAuthor, NewResponseNotification::class);
    }
}
