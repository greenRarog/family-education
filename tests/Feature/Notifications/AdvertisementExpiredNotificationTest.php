<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\Advertisement;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class AdvertisementExpiredNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_uses_mail_channel(): void
    {
        $user = User::factory()->create();
        $advertisement = Advertisement::factory()->create(['user_id' => $user->id]);

        $notification = new AdvertisementExpiredNotification($advertisement);

        $channels = $notification->via($user);
        $this->assertSame(['mail'], $channels);
    }

    public function test_builds_email_for_expired_advertisement(): void
    {
        $user = User::factory()->create(['name' => 'Автор объявления']);
        $advertisement = Advertisement::factory()->create(['user_id' => $user->id]);

        $notification = new AdvertisementExpiredNotification($advertisement);

        $mail = $notification->toMail($user);
        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertSame(__('notifications.advertisement_expired.subject'), $mail->subject);
        $this->assertSame('emails.notifications.advertisement-expired', $mail->view);
    }
}
