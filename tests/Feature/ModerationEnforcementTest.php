<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\TeacherProfileVisibilityStatus;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Family;
use App\Models\Message;
use App\Models\User;
use App\Services\TurnstileService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModerationEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function block(User $user): void
    {
        app(UserBlockService::class)->block(User::factory()->create(['user_type' => UserType::ADMIN]), $user,
            ['reason_code' => 'spam', 'reason' => 'Private reason for this account.']);
    }

    private function unblock(User $user): void
    {
        app(UserBlockService::class)->unblock(User::factory()->create(['user_type' => UserType::ADMIN]), $user, ['reason' => 'Review']);
    }

    public static function forbiddenWrites(): array
    {
        return array_map(fn ($route) => [$route], ['family', 'teacher-profile', 'create', 'update', 'publish', 'close', 'respond', 'accept', 'reject', 'message']);
    }

    #[DataProvider('forbiddenWrites')]
    public function test_old_authenticated_user_cannot_write_even_with_stale_auth_model_and_unverified_email(string $route): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $owner->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($owner);
        $this->block($owner); // The guard still has the pre-block model.
        $url = match ($route) {
            'family', 'teacher-profile' => '/api/'.$route,
            'create' => '/api/advertisements',
            'update' => '/api/advertisements/'.$response->advertisement_id,
            'publish', 'close' => '/api/advertisements/'.$response->advertisement_id.'/'.$route,
            'respond' => '/api/advertisements/'.$response->advertisement_id.'/responses',
            'accept', 'reject' => '/api/advertisement-responses/'.$response->id.'/'.$route,
            'message' => '/api/conversations/'.$conversation->id.'/messages',
        };
        $this->json(in_array($route, ['family', 'teacher-profile', 'update']) ? 'PUT' : 'POST', $url, ['message' => 'Should never be written'])
            ->assertForbidden()->assertJsonPath('code', 'user_blocked');
        $this->assertDatabaseCount('messages', 0);
        $this->assertSame(AdvertisementResponseStatus::SENT, $response->fresh()->status);
        $this->assertSame(AdvertisementStatus::DRAFT, $response->advertisement->fresh()->status);
    }

    #[DataProvider('blockedSides')]
    public function test_both_participants_pause_history_and_read_receipts_survive_and_rights_return(bool $blockOwner): void
    {
        Notification::fake();
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $author = $response->user;
        $target = $blockOwner ? $owner : $author;
        $message = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $owner->id, 'body' => 'Saved history', 'read_at' => null]);
        $this->block($target);
        foreach ([$owner, $author] as $user) {
            $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Blocked'])
                ->assertForbidden()->assertJsonPath('code', $user->id === $target->id ? 'user_blocked' : 'conversation_paused');
            $history = $this->getJson("/api/conversations/{$conversation->id}")->assertOk()->assertJsonPath('conversation.communication_paused', true);
            $this->assertStringNotContainsString('Private reason', $history->getContent());
            $this->getJson("/api/conversations/{$conversation->id}/messages")->assertOk();
            $this->getJson('/api/conversations')->assertOk();
            $this->getJson('/api/conversations/unread-count')->assertOk();
        }
        $this->actingAs($author)->postJson("/api/conversations/{$conversation->id}/read", ['through_id' => $message->id])->assertOk();
        $this->assertNotNull($message->fresh()->read_at);
        $this->actingAs($owner)->postJson("/api/advertisement-responses/{$response->id}/accept")->assertForbidden();
        $this->assertSame(AdvertisementResponseStatus::SENT, $response->fresh()->status);
        $this->unblock($target);
        $this->postJson("/api/advertisement-responses/{$response->id}/accept")->assertOk();
        foreach ([$owner, $author] as $user) {
            $this->actingAs($user)->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Allowed again'])->assertCreated();
        }
    }

    public static function blockedSides(): array
    {
        return [[true], [false]];
    }

    public function test_active_owner_can_reject_blocked_author_and_unblock_does_not_reopen_rejected_response(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $this->block($response->user);
        $this->actingAs($response->advertisement->user)->postJson("/api/advertisement-responses/{$response->id}/reject")->assertOk();
        $this->unblock($response->user);
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'No reopening'])->assertUnprocessable();
        $this->assertSame(AdvertisementResponseStatus::REJECTED, $response->fresh()->status);
        $other = Conversation::factory()->create();
        $other->advertisementResponse->advertisement->update(['user_id' => $response->advertisement->user_id]);
        $this->postJson("/api/conversations/{$other->id}/messages", ['message' => 'Other dialog works'])->assertCreated();
    }

    public function test_visibility_owner_content_and_dialog_placeholder_restore_without_state_changes(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $ad = $response->advertisement;
        $ad->update(['status' => AdvertisementStatus::PUBLISHED, 'published_at' => now(), 'description' => 'Hidden description']);
        $owner = $ad->user;
        $this->getJson("/api/advertisements/{$ad->id}")->assertOk();
        $this->block($owner);
        $this->getJson('/api/advertisements/feed')->assertJsonCount(0, 'advertisements.data');
        $this->getJson("/api/advertisements/{$ad->id}")->assertNotFound();
        $this->actingAs($response->user)->getJson("/api/advertisements/{$ad->id}")->assertNotFound();
        $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'New response'])->assertNotFound();
        $history = $this->getJson("/api/conversations/{$conversation->id}")->assertOk()
            ->assertJsonPath('conversation.advertisement.unavailable', true)->assertJsonMissingPath('conversation.advertisement.description');
        $this->assertSame(['id', 'type', 'unavailable'], array_keys($history->json('conversation.advertisement')));
        $this->getJson('/api/conversations')->assertJsonMissingPath('conversations.data.0.advertisement.description');
        $this->actingAs($owner)->getJson("/api/advertisements/{$ad->id}")->assertOk()->assertJsonPath('advertisement.description', 'Hidden description');
        $this->getJson("/api/advertisements/{$ad->id}/edit")->assertOk();
        $this->getJson('/api/advertisements')->assertOk();
        $this->getJson("/api/conversations/{$conversation->id}")->assertJsonPath('conversation.advertisement.description', 'Hidden description');
        $this->unblock($owner);
        $this->actingAs($response->user)->getJson("/api/advertisements/{$ad->id}")->assertOk();
        $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
    }

    public function test_teacher_public_profile_services_and_nested_teacher_are_hidden_and_pending_cannot_be_approved(): void
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $profile = $teacher->teacherProfile()->create(['surname' => 'Teacher', 'city_id' => City::factory()->create()->id,
            'public_profile_requested' => true, 'public_profile_status' => TeacherProfileVisibilityStatus::APPROVED]);
        $ad = Advertisement::factory()->create(['user_id' => $teacher->id, 'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'status' => AdvertisementStatus::PUBLISHED, 'published_at' => now()]);
        $this->getJson("/api/teachers/{$teacher->id}")->assertOk()->assertJsonCount(1, 'advertisements');
        $this->getJson("/api/advertisements/{$ad->id}")->assertJsonPath('advertisement.public_teacher.id', $teacher->id);
        $this->block($teacher);
        $this->getJson("/api/teachers/{$teacher->id}")->assertNotFound();
        $this->getJson('/api/advertisements/feed?type=teacher_to_service')->assertJsonCount(0, 'advertisements.data');
        $this->actingAs($teacher)->getJson('/api/teacher-profile')->assertOk();
        $this->getJson("/api/advertisements/{$ad->id}")->assertOk()->assertJsonPath('advertisement.public_teacher', null);
        $this->unblock($teacher);
        $this->getJson("/api/advertisements/{$ad->id}")->assertJsonPath('advertisement.public_teacher.id', $teacher->id);
        $profile->update(['public_profile_status' => TeacherProfileVisibilityStatus::PENDING]);
        $this->block($teacher);
        $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN]))
            ->postJson("/api/admin/teacher-profiles/{$teacher->id}/approve")->assertForbidden()->assertJsonPath('code', 'profile_owner_blocked');
        $this->assertSame(TeacherProfileVisibilityStatus::PENDING, $profile->fresh()->public_profile_status);
        $this->postJson("/api/admin/teacher-profiles/{$teacher->id}/reject")->assertOk();
    }

    public function test_limiter_stays_before_block_denial_and_outsider_cannot_discover_peer_sanction(): void
    {
        $conversation = Conversation::factory()->create();
        $owner = $conversation->advertisementResponse->advertisement->user;
        $this->block($owner);
        $this->actingAs($owner);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/advertisements', [])->assertForbidden()->assertJsonPath('code', 'user_blocked');
        }
        $this->postJson('/api/advertisements', [])->assertStatus(429)->assertHeader('Retry-After');
        $this->actingAs(User::factory()->create())->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'No access'])
            ->assertForbidden()->assertJsonMissingPath('code');
    }

    public function test_pre_block_remember_cookie_restores_only_restricted_access(): void
    {
        $this->mock(TurnstileService::class)->shouldReceive('verify')->andReturnTrue();
        $user = User::factory()->create(['password' => Hash::make('Password123!')]);
        $login = $this->postJson('/login', ['email' => $user->email, 'password' => 'Password123!', 'remember' => true, 'cf-turnstile-response' => 'test'])->assertOk();
        $cookieName = Auth::guard('web')->getRecallerName();
        $cookie = $login->getCookie($cookieName);
        $this->assertNotNull($cookie);
        $this->block($user);
        $this->app['session']->flush();
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($cookieName, $cookie->getValue())->getJson('/api/user')->assertOk()->assertJsonPath('user.is_blocked', true);
        $this->assertTrue(Auth::guard('web')->viaRemember());
        $this->postJson('/api/advertisements', [])->assertForbidden()->assertJsonPath('code', 'user_blocked');
    }

    public function test_real_login_old_session_recovery_settings_and_logout_preserve_block(): void
    {
        $this->mock(TurnstileService::class)->shouldReceive('verify')->andReturnTrue();
        $user = User::factory()->unverified()->create(['password' => Hash::make('Password123!')]);
        Family::factory()->create(['user_id' => $user->id]);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Password123!', 'cf-turnstile-response' => 'test'])->assertOk();
        $this->block($user);
        config(['moderation.appeal_email' => 'appeals@example.test']);
        $this->getJson('/api/user')->assertJsonPath('user.is_blocked', true)->assertJsonPath('appeal_email', 'appeals@example.test')
            ->assertJsonPath('user.block_reason', 'Private reason for this account.')->assertJsonMissingPath('user.blocked_by');
        $this->putJson('/api/family', [])->assertForbidden()->assertJsonPath('code', 'user_blocked');
        $this->getJson('/api/family')->assertOk();
        $this->putJson('/api/user/language', ['language' => 'es'])->assertOk();
        $this->putJson('/api/notification-settings', ['email_enabled' => false, 'telegram_enabled' => false])->assertOk();
        $this->getJson('/api/csrf')->assertNoContent();
        Event::fake([MessageSent::class]);
        $this->postJson('/email/verification-notification')->assertStatus(202);
        Event::assertDispatched(MessageSent::class);
        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(30), ['id' => $user->id, 'hash' => sha1($user->email)], absolute: false);
        $this->getJson($url)->assertOk();
        $this->assertTrue($user->fresh()->isBlocked());
        $this->postJson('/logout')->assertNoContent();
        Auth::forgetGuards();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $token = Password::createToken($user);
        $this->postJson('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertOk();
        $this->assertTrue($user->fresh()->isBlocked());
        $this->postJson('/login', ['email' => $user->email, 'password' => 'NewPassword123!', 'cf-turnstile-response' => 'test'])->assertOk();
        $this->putJson('/api/family', [])->assertForbidden()->assertJsonPath('code', 'user_blocked');
        $this->unblock($user);
        $this->getJson('/api/user')->assertJsonPath('user.is_blocked', false)->assertJsonPath('user.block_reason', null);
        $this->putJson('/api/family', [])->assertUnprocessable();
    }
}
