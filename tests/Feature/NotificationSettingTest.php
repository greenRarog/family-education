<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_get_notification_settings(): void
    {
        $response = $this->getJson('/api/notification-settings');

        $response->assertUnauthorized();
    }

    public function test_unauthenticated_user_cannot_update_notification_settings(): void
    {
        $response = $this->putJson('/api/notification-settings', [
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);

        $response->assertUnauthorized();
    }

    public function test_user_can_get_notification_settings(): void
    {
        $user = User::factory()->create();

        NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson('/api/notification-settings');

        $response
            ->assertOk()
            ->assertJson([
                'email_enabled' => true,
                'telegram_enabled' => false,
            ]);
    }

    public function test_user_can_update_notification_settings(): void
    {
        $user = User::factory()->create();

        NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);

        $response = $this
            ->actingAs($user)
            ->putJson('/api/notification-settings', [
                'email_enabled' => false,
                'telegram_enabled' => true,
            ]);

        $response
            ->assertOk()
            ->assertJson([
                'email_enabled' => false,
                'telegram_enabled' => true,
            ]);

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'email_enabled' => false,
            'telegram_enabled' => true,
        ]);
    }

    public function test_user_can_update_only_their_own_notification_settings(): void
    {
        $user = User::factory()->create();

        $otherUser = User::factory()->create();

        NotificationSetting::factory()->create([
            'user_id' => $user->id,
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);

        NotificationSetting::factory()->create([
            'user_id' => $otherUser->id,
            'email_enabled' => false,
            'telegram_enabled' => true,
        ]);

        $this
            ->actingAs($user)
            ->putJson('/api/notification-settings', [
                'email_enabled' => false,
                'telegram_enabled' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'email_enabled' => false,
            'telegram_enabled' => true,
        ]);

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $otherUser->id,
            'email_enabled' => false,
            'telegram_enabled' => true,
        ]);
    }

    public function test_user_can_create_notification_settings_when_settings_do_not_exist(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->putJson('/api/notification-settings', [
                'email_enabled' => true,
                'telegram_enabled' => false,
            ])
            ->assertOk()
            ->assertJson([
                'email_enabled' => true,
                'telegram_enabled' => false,
            ]);

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);
    }

    public function test_notification_settings_require_boolean_values(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->putJson('/api/notification-settings', [
                'email_enabled' => 'foo',
                'telegram_enabled' => 'bar',
            ]);

        $response->assertUnprocessable();

        $response->assertJsonValidationErrors([
            'email_enabled',
            'telegram_enabled',
        ]);
    }

    public function test_notification_settings_require_both_fields(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->putJson('/api/notification-settings', [
                'email_enabled' => true,
            ]);

        $response->assertUnprocessable();

        $response->assertJsonValidationErrors([
            'telegram_enabled',
        ]);
    }

    public function test_user_gets_default_notification_settings_when_settings_do_not_exist(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->getJson('/api/notification-settings');

        $response
            ->assertOk()
            ->assertJson([
                'email_enabled' => true,
                'telegram_enabled' => false,
            ]);

        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $user->id,
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);
    }
}
