<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationSettingTest extends TestCase
{
    use RefreshDatabase, \Tests\Support\ConfirmedTelegram;

    public function test_blocked_unverified_teacher_can_change_own_settings_without_changing_family_settings(): void
    {
        $teacher = User::factory()->unverified()->create([
            'user_type' => UserType::TEACHER,
        ]);
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->actingAs($admin)->postJson("/api/admin/users/{$teacher->id}/block", [
            'reason_code' => 'spam',
            'reason' => 'Synthetic notification settings test.',
        ])->assertOk();
        $teacher->refresh();
        $this->confirmTelegramFixture($teacher);
        $family = User::factory()->family()->create();
        NotificationSetting::factory()->create([
            'user_id' => $family->id,
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);

        $this->actingAs($teacher)->getJson('/api/notification-settings')
            ->assertOk()->assertExactJson(['email_enabled' => true, 'telegram_enabled' => false, 'max_enabled' => false]);
        $this->putJson('/api/notification-settings', [
            'email_enabled' => false,
            'telegram_enabled' => true,
            'user_id' => $family->id,
        ])->assertOk()->assertExactJson(['email_enabled' => false, 'telegram_enabled' => true, 'max_enabled' => false]);
        $this->getJson('/api/notification-settings')
            ->assertOk()->assertExactJson(['email_enabled' => false, 'telegram_enabled' => true, 'max_enabled' => false]);
        $this->assertDatabaseHas('notification_settings', [
            'user_id' => $family->id,
            'email_enabled' => true,
            'telegram_enabled' => false,
        ]);
    }

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
        $this->confirmTelegramFixture($user);

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
        $this->confirmTelegramFixture($user);

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
        $this->confirmTelegramFixture($user);

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
        $this->confirmTelegramFixture($user);

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
        $this->confirmTelegramFixture($user);

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
        $this->confirmTelegramFixture($user);

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
        $this->confirmTelegramFixture($user);

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
