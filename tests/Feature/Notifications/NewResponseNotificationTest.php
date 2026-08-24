<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Enums\AdvertisementResponseStatus;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\User;
use App\Notifications\NewResponseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class NewResponseNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_mail_channel(): void
    {
        $owner = User::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $owner->id]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => User::factory()->create()->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        $notification = new NewResponseNotification($response);

        $channels = $notification->via($owner);
        $this->assertSame(['mail'], $channels);
    }

    public function test_builds_email_for_new_response(): void
    {
        $owner = User::factory()->create(['name' => 'Автор объявления']);
        $respondent = User::factory()->create(['name' => 'Пользователь']);
        $advertisement = Advertisement::factory()->create(['user_id' => $owner->id]);
        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $respondent->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        $notification = new NewResponseNotification($response);

        $mail = $notification->toMail($owner);
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame(__('notifications.new_response.subject'), $mail->subject);
    }
}
