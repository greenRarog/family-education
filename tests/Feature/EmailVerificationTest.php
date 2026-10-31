<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\City;
use App\Models\User;
use App\Notifications\VerifyEmail;
use App\Services\TurnstileService;
use Illuminate\Auth\Events\Verified;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_sends_real_mail_keeps_session_and_charges_first_letter(): void
    {
        $this->freezeTime();
        config(['app.url' => 'https://family-education.test']);
        Event::fake([MessageSent::class]);
        $this->postJson('/register', $this->registration())->assertCreated();
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertInstanceOf(MustVerifyEmail::class, $user);
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->getJson('/api/user')->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.email_verified_at', null);
        Event::assertDispatched(MessageSent::class, function (MessageSent $event): bool {
            return str_contains($event->message->getHtmlBody(), 'https://family-education.test/verify-email/')
                && str_contains($event->message->getHtmlBody(), 'signature=');
        });
        $this->postJson('/email/verification-notification')->assertStatus(429)
            ->assertHeader('Retry-After', '60')->assertJsonPath('retry_after', 60);
        Event::assertDispatchedTimes(MessageSent::class, 1);
    }

    public function test_mail_failure_preserves_registration_and_resend_reports_failure_without_claiming_success(): void
    {
        Notification::shouldReceive('send')->andThrow(new \RuntimeException('transport unavailable'));
        // User::notify resolves the dispatcher contract; facade mock is its binding.
        $this->postJson('/register', $this->registration())->assertCreated();
        $user = User::query()->where('email', 'new@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertFalse($user->hasVerifiedEmail());
        $this->travel(60)->seconds();
        $this->postJson('/email/verification-notification')->assertStatus(503)
            ->assertJsonPath('code', 'verification_delivery_failed');
        $this->assertAuthenticatedAs($user);
        $this->postJson('/email/verification-notification')->assertStatus(429);
    }

    public function test_resend_limits_both_windows_preserves_retries_and_isolates_users(): void
    {
        Notification::fake();
        $this->freezeTime();
        $start = now()->toImmutable();
        $user = User::factory()->unverified()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->travelTo($start->addSeconds($i * 60));
            $this->postJson('/email/verification-notification', ['email' => 'attacker@example.test'])->assertStatus(202);
        }
        $this->postJson('/email/verification-notification')->assertStatus(429)
            ->assertHeader('Retry-After', '3360')->assertJsonPath('retry_after', 3360);
        $other = User::factory()->unverified()->create();
        $this->actingAs($other)->postJson('/email/verification-notification')->assertStatus(202);
        $this->actingAs($user)->withServerVariables(['REMOTE_ADDR' => '192.0.2.10']);
        $this->travelTo($start->addSeconds(3599));
        $this->postJson('/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->travelTo($start->addSeconds(3600));
        $this->postJson('/email/verification-notification')->assertStatus(202);
        Notification::assertSentToTimes($user, VerifyEmail::class, 6);
        Notification::assertSentToTimes($other, VerifyEmail::class, 1);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    public function test_minute_window_can_outlive_hour_and_denied_requests_do_not_extend_windows(): void
    {
        Notification::fake();
        $this->freezeTime();
        $start = now()->toImmutable();
        $this->actingAs(User::factory()->unverified()->create());
        foreach ([0, 60, 120, 180, 3590] as $seconds) {
            $this->travelTo($start->addSeconds($seconds));
            $this->postJson('/email/verification-notification')->assertStatus(202);
        }
        $this->postJson('/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->travelTo($start->addSeconds(3600));
        $this->postJson('/email/verification-notification')->assertStatus(429)->assertHeader('Retry-After', '50');
        $this->travelTo($start->addSeconds(3650));
        $this->postJson('/email/verification-notification')->assertStatus(202);
    }

    public function test_database_cache_reservation_is_shared_between_service_instances(): void
    {
        Notification::fake();
        config(['cache.default' => 'database']);
        $this->app->forgetInstance(RateLimiter::class);
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->postJson('/email/verification-notification')->assertStatus(202);
        $manager = new CacheManager($this->app);
        $this->app->instance(RateLimiter::class, new RateLimiter($manager->store('database')));
        $this->postJson('/email/verification-notification')->assertStatus(429);
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
    }

    public function test_unverified_user_can_log_in_without_an_automatic_email(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->mock(TurnstileService::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn(true));
        $this->postJson('/login', [
            'email' => $user->email, 'password' => 'Password123!', 'cf-turnstile-response' => 'test-token',
        ])->assertOk();
        $this->assertAuthenticatedAs($user);
        Notification::assertNothingSent();
    }

    public function test_verification_endpoints_return_json_for_unauthenticated_browser_requests(): void
    {
        $user = User::factory()->unverified()->create();
        $this->get($this->link($user))->assertUnauthorized();
        $this->post('/email/verification-notification')->assertUnauthorized();
    }

    public function test_guest_and_csrf_failures_do_not_send_or_charge_resend(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->postJson('/email/verification-notification')->assertUnauthorized();
        $this->actingAs($user);
        $this->app['env'] = 'local';
        try {
            $this->postJson('/email/verification-notification')->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        Notification::assertNothingSent();
        $this->postJson('/email/verification-notification')->assertStatus(202);
    }

    public function test_verified_user_resend_is_successful_without_mail_or_budget(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 7; $i++) {
            $this->postJson('/email/verification-notification')->assertNoContent();
        }
        Notification::assertNothingSent();
        $this->assertSame(0, app(RateLimiter::class)->attempts('email:verification:user:'.$user->id.':60'));
    }

    public function test_mail_link_round_trip_through_frontend_and_auth_rewrite_verifies_once(): void
    {
        $user = User::factory()->unverified()->create();
        config(['app.url' => 'https://family-education.test']);
        $link = (new VerifyEmail)->toMail($user)->actionUrl;
        $this->assertStringStartsWith('https://family-education.test/verify-email/', $link);
        $externalRequest = str_replace('/verify-email/', '/auth/email/verify/', parse_url($link, PHP_URL_PATH))
            .'?'.parse_url($link, PHP_URL_QUERY);
        // Same rewrite as frontend nginx: /auth/* -> /*. Host/scheme are not signed.
        $backendRequest = substr($externalRequest, strlen('/auth'));
        $this->getJson($backendRequest)->assertUnauthorized();
        Event::fake([Verified::class]);
        $this->actingAs($user)->getJson($backendRequest)->assertOk()->assertJsonPath('email_verified', true);
        $verifiedAt = $user->fresh()->email_verified_at->toISOString();
        $this->getJson($backendRequest)->assertOk();
        $this->assertSame($verifiedAt, $user->fresh()->email_verified_at->toISOString());
        Event::assertDispatchedTimes(Verified::class, 1);
        $this->getJson('/api/user')->assertJsonPath('user.email_verified', true)
            ->assertJsonPath('user.email_verified_at', $verifiedAt);
    }

    public static function invalidLinks(): array
    {
        return [['expired'], ['signature'], ['other-user'], ['changed-email'], ['wrong-hash'], ['extra-query']];
    }

    #[DataProvider('invalidLinks')]
    public function test_invalid_links_do_not_confirm_or_emit_events(string $case): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $link = $this->link($user);
        if ($case === 'expired') {
            $this->travel(3601)->seconds();
        } elseif ($case === 'signature') {
            $link = preg_replace('/signature=[^&]+/', 'signature=invalid', $link);
        } elseif ($case === 'changed-email') {
            $user->update(['email' => 'changed@example.test']);
        } elseif ($case === 'wrong-hash') {
            $link = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
                'id' => $user->id, 'hash' => sha1('wrong@example.test'),
            ], absolute: false);
        } elseif ($case === 'extra-query') {
            $link .= '&unexpected=1';
        }
        Event::fake([Verified::class]);
        $actor = $case === 'other-user' ? User::factory()->unverified()->create() : $user;
        $this->actingAs($actor)->getJson($link)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        $this->assertFalse($actor->fresh()->hasVerifiedEmail());
        Event::assertNotDispatched(Verified::class);
    }

    public function test_resending_does_not_revoke_previous_link_and_check_budget_is_separate(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $old = $this->link($user);
        $this->actingAs($user)->postJson('/email/verification-notification')->assertStatus(202);
        $this->travel(60)->seconds();
        $this->postJson('/email/verification-notification')->assertStatus(202);
        for ($i = 0; $i < 6; $i++) {
            $this->getJson($old)->assertOk();
        }
        $this->getJson($old)->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->postJson('/email/verification-notification')->assertNoContent();
        $this->travel(60)->seconds();
        $this->getJson($old)->assertOk();
    }

    public static function verificationStates(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('verificationStates')]
    public function test_password_reset_preserves_verification_state(bool $verified): void
    {
        $user = User::factory()->create(['email_verified_at' => $verified ? now() : null]);
        $before = $user->email_verified_at?->toISOString();
        $token = Password::broker()->createToken($user);
        $this->postJson('/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!',
        ])->assertOk();
        $this->assertSame($before, $user->fresh()->email_verified_at?->toISOString());
    }

    private function link(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id, 'hash' => sha1($user->email),
        ], absolute: false);
    }

    private function registration(): array
    {
        $this->mock(TurnstileService::class, fn ($mock) => $mock->shouldReceive('verify')->once()->andReturn(true));

        return [
            'cf-turnstile-response' => 'test-token', 'name' => 'Новый пользователь', 'email' => 'new@example.test',
            'password' => 'Password123!', 'password_confirmation' => 'Password123!',
            'surname' => 'Семья', 'city_id' => City::factory()->create()->id,
            'children' => [['name' => 'Ребёнок', 'birth_date' => '2020-01-01', 'sex' => 'male']],
        ];
    }
}
