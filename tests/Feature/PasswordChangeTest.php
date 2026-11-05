<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\TurnstileService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private function body(array $replace = []): array
    {
        return array_replace(['current_password' => 'Password123!', 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'], $replace);
    }

    public static function accounts(): array
    {
        return [['family', false], ['teacher', false], ['admin', false], ['family', true], ['teacher', true]];
    }

    #[DataProvider('accounts')]
    public function test_change_keeps_current_session_and_identity_even_when_blocked_or_unverified(string $type, bool $blocked): void
    {
        $user = User::factory()->unverified()->create(['user_type' => $type]);
        if ($blocked) {
            app(UserBlockService::class)->block(User::factory()->create(['user_type' => UserType::ADMIN]), $user, ['reason_code' => 'spam', 'reason' => 'Password fixture.']);
            $user->refresh();
        }
        $before = $user->only(['email', 'email_verified_at', 'user_type', 'block_action_id']);
        $remember = $user->remember_token;
        Password::broker()->createToken($user);
        $this->actingAs($user)->getJson('/api/user')->assertOk();
        $oldSession = session()->getId();
        $oldCsrf = session()->token();
        $this->putJson('/api/account/password', $this->body())->assertOk()
            ->assertExactJson(['status' => 'password_changed', 'other_sessions_revoked' => true])
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($oldSession, session()->getId());
        $this->assertNotSame($oldCsrf, session()->token());
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('NewPassword123!', $fresh->password));
        $this->assertFalse(Hash::check('Password123!', $fresh->password));
        $this->assertSame($before, $fresh->only(array_keys($before)));
        $this->assertSame(1, $fresh->session_version);
        $this->assertNotSame($remember, $fresh->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('authenticated', true)->assertJsonMissingPath('user.session_version');
        $this->putJson('/api/account/password', $this->body())->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->putJson('/api/account/password', $this->body(['current_password' => 'NewPassword123!', 'password' => 'ThirdPassword123!', 'password_confirmation' => 'ThirdPassword123!']))->assertOk();
    }

    public static function invalid(): array
    {
        return [
            [['current_password' => 'wrong'], 'current_password'],
            [['current_password' => null], 'current_password'],
            [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
            [['password_confirmation' => 'different'], 'password'],
            [['password' => null], 'password'],
            [['user_id' => 999], 'user_id'],
            [['id' => 999], 'id'],
            [['email' => 'other@example.test'], 'email'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_validation_preserves_password_remember_reset_and_session(array $input, string $field): void
    {
        $user = User::factory()->create();
        Password::broker()->createToken($user);
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->putJson('/api/account/password', $this->body($input))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
        $this->assertAuthenticatedAs($user);
    }

    public function test_guest_csrf_and_expected_account_are_enforced(): void
    {
        $this->putJson('/api/account/password', $this->body())->assertUnauthorized();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($user)->withHeader('X-Expected-Account-Id', (string) $other->id)
            ->putJson('/api/account/password', $this->body())->assertConflict()->assertJsonPath('code', 'account_changed');
        $this->app['env'] = 'local';
        $this->withHeader('X-Expected-Account-Id', (string) $user->id)->putJson('/api/account/password', $this->body())->assertStatus(419);
        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
        $this->assertTrue(Hash::check('Password123!', $other->fresh()->password));
        $this->withHeader('X-CSRF-TOKEN', session()->token())->putJson('/api/account/password', $this->body())->assertOk();
        $this->app['env'] = 'testing';
    }

    public function test_failure_rolls_back_password_version_and_tokens(): void
    {
        $user = User::factory()->create();
        $before = $user->fresh()->getAttributes();
        Password::shouldReceive('broker')->once()->andReturnSelf();
        Password::shouldReceive('deleteToken')->once()->andThrow(new RuntimeException('Fixture failure'));
        $this->actingAs($user)->putJson('/api/account/password', $this->body())->assertStatus(500);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertAuthenticatedAs($user);
    }

    public function test_five_attempts_are_shared_by_ips_and_independent_from_deletion(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.($i + 1)])
                ->putJson('/api/account/password', $this->body(['current_password' => 'wrong']))->assertUnprocessable();
        }
        $this->putJson('/api/account/password', $this->body())->assertStatus(429)->assertHeader('Retry-After', '60')->assertJsonPath('retry_after', 60);
        $this->deleteJson('/api/account', ['current_password' => 'Password123!', 'request_token' => str_repeat('a', 64)])->assertStatus(503);
        $this->travel(59)->seconds();
        $this->putJson('/api/account/password', $this->body())->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->travel(1)->seconds();
        $this->putJson('/api/account/password', $this->body())->assertOk();
    }

    public function test_deleted_account_cannot_change_password(): void
    {
        $user = User::factory()->create();
        app(AccountDeletionService::class)->delete($user);
        $before = $user->fresh()->password;
        $this->actingAs($user)->putJson('/api/account/password', $this->body())->assertUnauthorized();
        $this->assertSame($before, $user->fresh()->password);
    }

    public function test_migration_preserves_existing_accounts_and_prevents_unsafe_rollback_after_revocation(): void
    {
        $user = User::factory()->create();
        $before = $user->password;
        $migration = require database_path('migrations/2026_11_02_140000_add_session_version_to_users.php');
        $migration->down();
        $migration->up();
        $this->assertSame(0, $user->fresh()->session_version);
        $this->assertSame($before, $user->fresh()->password);
        $this->actingAs($user)->putJson('/api/account/password', $this->body())->assertOk();
        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function test_old_reset_link_is_revoked_but_fresh_recovery_and_login_still_work(): void
    {
        $this->mock(TurnstileService::class)->shouldReceive('verify')->andReturn(true);
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $this->actingAs($user)->putJson('/api/account/password', $this->body())->assertOk();
        $this->postJson('/logout')->assertNoContent();
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'RecoveredPassword123!', 'password_confirmation' => 'RecoveredPassword123!'];
        $this->postJson('/reset-password', $payload)->assertUnprocessable();
        $payload['token'] = Password::broker()->createToken($user->fresh());
        $this->postJson('/reset-password', $payload)->assertOk();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'NewPassword123!', 'cf-turnstile-response' => 'fixture'])->assertUnprocessable();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'RecoveredPassword123!', 'cf-turnstile-response' => 'fixture'])->assertOk();
        $this->getJson('/api/user')->assertJsonPath('authenticated', true);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_database_sessions_and_remember_cookies_are_revoked_including_late_legacy_write(): void
    {
        config(['session.driver' => 'database']);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
        $this->mock(TurnstileService::class)->shouldReceive('verify')->andReturn(true);
        $user = User::factory()->create();
        $login = $this->postJson('/login', ['email' => $user->email, 'password' => 'Password123!', 'remember' => true, 'cf-turnstile-response' => 'fixture'])->assertOk();
        $cookieName = config('session.cookie');
        $rememberName = Auth::guard('web')->getRecallerName();
        $remember = $login->getCookie($rememberName)->getValue();
        $oldId = session()->getId();
        $payload = DB::table('sessions')->where('id', $oldId)->value('payload');
        DB::table('sessions')->insert(['id' => 'other-device', 'user_id' => $user->id, 'payload' => $payload, 'last_activity' => time()]);
        $unrelated = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'unrelated', 'user_id' => $unrelated->id, 'payload' => '', 'last_activity' => time()]);
        $changed = $this->putJson('/api/account/password', $this->body())->assertOk();
        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseMissing('sessions', ['id' => $oldId]);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated']);
        $current = $changed->getCookie($cookieName)->getValue();
        // Simulate a request loaded before revocation persisting an old/legacy session later.
        foreach ([false, true] as $legacy) {
            $data = json_decode(base64_decode($payload), true, flags: JSON_THROW_ON_ERROR);
            if ($legacy) {
                unset($data['account_session_version']);
            }
            $id = str_repeat($legacy ? 'b' : 'a', 40);
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'payload' => base64_encode(json_encode($data, JSON_THROW_ON_ERROR)), 'last_activity' => time()]);
            $this->app['session']->flush();
            Auth::forgetGuards();
            $this->withCredentials()->withCookie($cookieName, $id)->withCookie($rememberName, $remember)
                ->getJson('/api/user')->assertOk()->assertJsonPath('authenticated', false);
            $this->getJson('/api/notification-settings')->assertUnauthorized();
        }
        $this->app['session']->flush();
        Auth::forgetGuards();
        $this->withCookie($cookieName, $current)->withCookie($rememberName, '')->getJson('/api/user')->assertJsonPath('authenticated', true);
        $this->assertNull(Auth::createUserProvider('users')->retrieveByToken($user->id, explode('|', $remember)[1]));
    }
}
