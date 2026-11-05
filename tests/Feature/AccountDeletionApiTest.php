<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AccountDeletionRegistry;
use App\Enums\UserType;
use App\Models\AccountDeletion;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountQueueCleaner;
use App\Services\TurnstileService;
use App\Services\UnavailableAccountDeletionRegistry;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\FakeAccountDeletionRegistry;
use Tests\TestCase;

class AccountDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    private FakeAccountDeletionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        config(['account_deletion.enabled' => true]);
        $this->registry = new FakeAccountDeletionRegistry;
        $this->app->instance(AccountDeletionRegistry::class, $this->registry);
    }

    private function body(): array
    {
        return ['current_password' => 'Password123!', 'request_token' => bin2hex(random_bytes(32))];
    }

    public static function accounts(): array
    {
        return [['family', false], ['teacher', false], ['family', true], ['teacher', true]];
    }

    #[DataProvider('accounts')]
    public function test_self_deletion_revokes_access_even_when_blocked_or_unverified(string $type, bool $blocked): void
    {
        $user = User::factory()->unverified()->create(['user_type' => $type]);
        if ($blocked) {
            app(UserBlockService::class)->block(User::factory()->create(['user_type' => UserType::ADMIN]), $user, ['reason_code' => 'spam', 'reason' => 'Deletion fixture.']);
        }
        $oldEmail = $user->email;
        $reset = Password::broker()->createToken($user);
        $body = $this->body();
        $other = User::factory()->create();
        foreach (['first', 'second'] as $id) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        }
        DB::table('sessions')->insert(['id' => 'other', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);
        $this->actingAs($user)->deleteJson('/api/account', $body)->assertOk()
            ->assertJsonPath('status', 'deleted')->assertJsonPath('access_revoked', true)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertGuest();
        $this->assertTrue($user->fresh()->isAccountDeleted());
        $this->assertNull($user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseHas('sessions', ['id' => 'other']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->assertSame([$user->id], $this->registry->recorded);
        $this->assertSame(hash('sha256', $body['request_token']), AccountDeletion::find($user->id)->request_token_hash);
        $this->getJson('/api/user')->assertJsonPath('authenticated', false);
        $this->getJson('/api/family')->assertUnauthorized();
        $this->postJson('/reset-password', ['email' => $oldEmail, 'token' => $reset, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertUnprocessable();
        $this->postJson('/api/account/deletion-status', ['request_token' => $body['request_token']])->assertOk()->assertJsonMissing(['email' => $oldEmail]);
        $this->assertSame([$user->id], $this->registry->recorded);
    }

    public function test_wrong_password_or_target_fields_never_mutate_any_account(): void
    {
        $chat = Conversation::factory()->create();
        $user = $chat->advertisementResponse->user;
        $original = $user->getAttributes();
        $this->actingAs($user)->deleteJson('/api/account', array_replace($this->body(), ['current_password' => 'wrong']))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->deleteJson('/api/account', $this->body() + ['user_id' => $chat->advertisementResponse->advertisement->user_id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->deleteJson('/api/account', ['current_password' => 'Password123!'])->assertUnprocessable()->assertJsonValidationErrors('request_token');
        $this->assertSame($original, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('account_deletions', 0);
        $this->assertNotNull($chat->fresh());
        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_and_admin_cannot_delete(): void
    {
        $this->deleteJson('/api/account', $this->body())->assertUnauthorized();
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->actingAs($admin)->deleteJson('/api/account', $this->body())->assertForbidden();
        $this->assertFalse($admin->fresh()->isAccountDeleted());
    }

    public function test_expected_account_header_only_rejects_a_changed_session_and_never_selects_a_target(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($second)->withHeader('X-Expected-Account-Id', (string) $first->id)
            ->deleteJson('/api/account', $this->body())->assertConflict()->assertJsonPath('code', 'account_changed');
        $this->assertDatabaseCount('account_deletions', 0);
        $this->assertFalse($first->fresh()->isAccountDeleted());
        $this->assertFalse($second->fresh()->isAccountDeleted());
        $this->withHeader('X-Expected-Account-Id', (string) $second->id)->deleteJson('/api/account', $this->body())->assertOk();
        $this->assertFalse($first->fresh()->isAccountDeleted());
        $this->assertTrue($second->fresh()->isAccountDeleted());
    }

    public function test_api_is_closed_without_release_enablement_and_independent_registry(): void
    {
        $user = User::factory()->create();
        config(['account_deletion.enabled' => false]);
        $this->actingAs($user)->getJson('/api/user')->assertJsonPath('account_deletion_available', false);
        $this->actingAs($user)->deleteJson('/api/account', $this->body())->assertStatus(503);
        config(['account_deletion.enabled' => true]);
        $this->getJson('/api/user')->assertJsonPath('account_deletion_available', true);
        $this->app->bind(AccountDeletionRegistry::class, UnavailableAccountDeletionRegistry::class);
        $this->getJson('/api/user')->assertJsonPath('account_deletion_available', false);
        $this->deleteJson('/api/account', $this->body())->assertStatus(503);
        $this->assertFalse($user->fresh()->isAccountDeleted());
        $this->assertAuthenticatedAs($user);
    }

    public function test_failure_before_commit_rolls_back_all_dependencies_and_keeps_session(): void
    {
        $chat = Conversation::factory()->create();
        $owner = $chat->advertisementResponse->advertisement->user;
        $original = $owner->getAttributes();
        $this->mock(AccountQueueCleaner::class)->shouldReceive('clean')->once()->andThrow(new RuntimeException('Fixture failure.'));
        $this->actingAs($owner)->deleteJson('/api/account', $this->body())->assertStatus(500);
        $this->assertSame($original, $owner->fresh()->getAttributes());
        $this->assertNull($chat->fresh()->expires_at);
        $this->assertNull($chat->advertisementResponse->advertisement->fresh()->account_deleted_at);
        $this->assertDatabaseCount('account_deletions', 0);
        $this->assertAuthenticatedAs($owner);
        $this->assertSame([], $this->registry->recorded);
    }

    public function test_registry_failure_is_pending_and_lost_response_can_be_resolved_without_session(): void
    {
        $user = User::factory()->create();
        $body = $this->body();
        $this->registry->fail = true;
        $this->actingAs($user)->deleteJson('/api/account', $body)->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->assertGuest();
        $at = AccountDeletion::find($user->id)->deleted_at;
        $this->deleteJson('/api/account', $body)->assertUnauthorized();
        $this->postJson('/api/account/deletion-status', ['request_token' => $body['request_token']])->assertStatus(202);
        $this->postJson('/api/account/deletion-status', ['request_token' => bin2hex(random_bytes(32))])->assertNotFound();
        $this->registry->fail = false;
        config(['account_deletion.enabled' => false]); // Shutdown of new requests must not hide committed results.
        $this->postJson('/api/account/deletion-status', ['request_token' => $body['request_token']])->assertOk()->assertJsonPath('status', 'deleted');
        $this->assertTrue($at->eq(AccountDeletion::find($user->id)->deleted_at));
        $this->assertGuest();
    }

    public function test_request_token_cannot_be_reused_for_another_user(): void
    {
        $body = $this->body();
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->actingAs($first)->deleteJson('/api/account', $body)->assertOk();
        $this->actingAs($second)->deleteJson('/api/account', $body)->assertConflict();
        $this->assertFalse($second->fresh()->isAccountDeleted());
    }

    public function test_real_csrf_check_precedes_deletion(): void
    {
        $user = User::factory()->create();
        $this->app['env'] = 'local';
        $this->actingAs($user)->deleteJson('/api/account', $this->body())->assertStatus(419);
        $this->assertFalse($user->fresh()->isAccountDeleted());
        $this->postJson('/api/account/deletion-status', ['request_token' => $this->body()['request_token']])->assertStatus(419);
    }

    public function test_deleted_identity_cannot_be_restored_by_remember_login_or_verification(): void
    {
        $user = User::factory()->unverified()->create();
        $oldEmail = $user->email;
        $remember = $user->remember_token;
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($oldEmail)], absolute: false);
        app(AccountDeletionService::class)->delete($user);
        $provider = Auth::createUserProvider('users');
        $this->assertNull($provider->retrieveByToken($user->id, $remember));
        $provider->updateRememberToken($user, 'stale-logout-token');
        $this->assertNull($user->fresh()->remember_token);
        $this->actingAs($user)->getJson($url)->assertUnauthorized();
        $this->getJson('/api/user')->assertJsonPath('authenticated', false);
        Auth::forgetGuards();
        $this->mock(TurnstileService::class)->shouldReceive('verify')->andReturn(true);
        $this->postJson('/login', ['email' => $oldEmail, 'password' => 'Password123!', 'cf-turnstile-response' => 'fixture'])->assertUnprocessable();
        $this->assertFalse(Hash::check('Password123!', $user->fresh()->password));
    }

    public function test_password_attempt_budget_is_shared_by_devices_and_does_not_extend_on_rejection(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $body = array_replace($this->body(), ['current_password' => 'wrong']);
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])->deleteJson('/api/account', $body)->assertUnprocessable();
        }
        $this->deleteJson('/api/account', $this->body())->assertStatus(429)->assertHeader('Retry-After', '60')->assertJsonPath('retry_after', 60);
        $this->assertFalse($user->fresh()->isAccountDeleted());
        $this->travel(59)->seconds();
        $this->deleteJson('/api/account', $body)->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->actingAs(User::factory()->create())->deleteJson('/api/account', $this->body())->assertOk();
        $this->travel(1)->seconds();
        $this->actingAs($user)->deleteJson('/api/account', $this->body())->assertOk();
    }

    public function test_scheduler_retries_pending_registry_acknowledgement_idempotently(): void
    {
        $user = User::factory()->create();
        $this->registry->acknowledge = false;
        $body = $this->body();
        $this->actingAs($user)->deleteJson('/api/account', $body)->assertStatus(202);
        $this->artisan('accounts:confirm-deletions')->expectsOutput('{"confirmed":0,"pending":1}')->assertFailed();
        $this->registry->acknowledge = true;
        $this->artisan('accounts:confirm-deletions')->expectsOutput('{"confirmed":1,"pending":0}')->assertSuccessful();
        $this->artisan('accounts:confirm-deletions')->expectsOutput('{"confirmed":0,"pending":0}')->assertSuccessful();
        $this->postJson('/api/account/deletion-status', ['request_token' => $body['request_token']])->assertOk();
        $this->assertNull(AccountDeletion::find($user->id)->backups_cleared_at);
    }

    public function test_real_session_and_remember_cookies_cannot_restore_deleted_identity(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->mock(TurnstileService::class)->shouldReceive('verify')->andReturn(true);
        $user = User::factory()->create();
        $login = $this->postJson('/login', ['email' => $user->email, 'password' => 'Password123!', 'remember' => true, 'cf-turnstile-response' => 'fixture'])->assertOk();
        $sessionName = config('session.cookie');
        $rememberName = Auth::guard('web')->getRecallerName();
        $session = $login->getCookie($sessionName);
        $remember = $login->getCookie($rememberName);
        $this->assertNotNull($session);
        $this->assertNotNull($remember);
        $this->deleteJson('/api/account', $this->body())->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->app['session']->flush();
        Auth::forgetGuards();
        $this->withCredentials()->withCookie($sessionName, $session->getValue())->withCookie($rememberName, $remember->getValue())
            ->getJson('/api/user')->assertOk()->assertJsonPath('authenticated', false);
        $this->getJson('/api/notification-settings')->assertUnauthorized();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertNull($user->fresh()->remember_token);
    }
}
