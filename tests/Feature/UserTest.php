<?php

declare(strict_types=1);

namespace Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_notifications_are_enabled_by_default_when_settings_do_not_exist(): void
    {
        $user = User::factory()->create();

        $this->assertTrue($user->emailNotificationsEnabled());
    }

    public function test_email_notification_setting_can_disable_notifications(): void
    {
        $user = User::factory()->create();

        $user->notificationSetting()->create([
            'email_enabled' => false,
            'telegram_enabled' => false,
        ]);

        $this->assertFalse($user->emailNotificationsEnabled());
    }

    public function test_email_notifications_are_enabled_when_setting_is_enabled(): void
    {
        $user = User::factory()->create();

        $user->notificationSetting()->create([
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);

        $this->assertTrue($user->emailNotificationsEnabled());
    }
}
