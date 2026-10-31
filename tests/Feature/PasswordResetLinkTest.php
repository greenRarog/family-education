<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PasswordResetLinkTest extends TestCase
{
    use RefreshDatabase;

    private array $letters = [];

    protected function setUp(): void
    {
        parent::setUp();
        // Keep the actual notification, mail renderer and array transport running.
        Event::listen(MessageSent::class, function (MessageSent $event): void {
            $this->letters[] = $event->message;
        });
    }

    public static function recipients(): array
    {
        return [
            'unverified, https, trailing slash' => [false, 'https://families.example.test/'],
            'verified, https, custom port' => [true, 'https://families.example.test:8443'],
            'unverified, local' => [false, 'http://localhost:8081'],
        ];
    }

    #[DataProvider('recipients')]
    public function test_real_letter_points_to_existing_frontend_and_resets_password_without_changing_email_status(bool $verified, string $base): void
    {
        config(['app.url' => $base]);
        $this->assertFalse(config('fortify.views'));
        $this->assertFalse(Route::has('password.reset'));
        $user = User::factory()->create([
            'email' => 'parent+reset@example.test', 'email_verified_at' => $verified ? now() : null,
        ]);
        $user->notificationSetting()->create(['email_enabled' => false, 'telegram_enabled' => false]);
        $verifiedAt = $user->email_verified_at?->toISOString();
        Event::fake([PasswordReset::class, Verified::class]);
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertCount(1, $this->letters);
        $this->assertSame($user->email, $this->letters[0]->getTo()[0]->getAddress());
        $link = $this->link();
        $this->assertStringStartsWith(rtrim($base, '/').'/reset-password/', $link);
        $this->assertStringContainsString('email=parent%2Breset%40example.test', $link);
        $credentials = $this->credentials($link);
        $this->assertSame($user->email, $credentials['email']);
        $this->assertSame('/reset-password/'.$credentials['token'], parse_url($link, PHP_URL_PATH));
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotSame($credentials['token'], $stored->token);
        $this->assertTrue(Hash::check($credentials['token'], $stored->token));
        $this->postJson('/reset-password', $this->payload($credentials))->assertOk();
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertFalse(Hash::check('Password123!', $user->fresh()->password));
        $this->assertSame($verifiedAt, $user->fresh()->email_verified_at?->toISOString());
        $this->assertGuest();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        Event::assertDispatchedTimes(PasswordReset::class, 1);
        Event::assertNotDispatched(Verified::class);
        $this->postJson('/reset-password', $this->payload($credentials))->assertUnprocessable();
        Event::assertDispatchedTimes(PasswordReset::class, 1);
    }

    public function test_link_origin_comes_from_configuration_not_request_host_or_input(): void
    {
        config(['app.url' => 'https://families.example.test']);
        $user = User::factory()->unverified()->create();
        $this->withHeaders(['Host' => 'untrusted.example.test', 'X-Forwarded-Host' => 'untrusted.example.test'])
            ->postJson('/forgot-password', ['email' => $user->email, 'url' => 'https://untrusted.example.test'])->assertOk();
        $this->assertStringStartsWith('https://families.example.test/reset-password/', $this->link());
        $this->assertStringNotContainsString('untrusted.example.test', $this->link());
    }

    public function test_resend_keeps_broker_60_second_cooldown_and_replaces_old_token(): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $old = $this->credentials($this->link());
        $this->travel(59)->seconds();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertCount(1, $this->letters);
        $this->travel(1)->seconds();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $this->assertCount(2, $this->letters);
        $new = $this->credentials($this->link(1));
        $this->assertNotSame($old['token'], $new['token']);
        $this->postJson('/reset-password', $this->payload($old))->assertUnprocessable();
        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
        $this->postJson('/reset-password', $this->payload($new))->assertOk();
    }

    public static function linkAges(): array
    {
        return [['before expiry', 3599, 200], ['after expiry', 3601, 422]];
    }

    #[DataProvider('linkAges')]
    public function test_link_keeps_60_minute_expiry(string $case, int $age, int $status): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $before = $user->password;
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $credentials = $this->credentials($this->link());
        $this->travel($age)->seconds();
        $this->postJson('/reset-password', $this->payload($credentials))->assertStatus($status);
        if ($status === 422) {
            $this->assertSame($before, $user->fresh()->password);
        } else {
            $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        }
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public static function invalidCredentials(): array
    {
        return [['changed-token'], ['other-user'], ['mismatched-password']];
    }

    #[DataProvider('invalidCredentials')]
    public function test_invalid_reset_preserves_users_and_original_token(string $case): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->create();
        $before = [$user->password, $other->password];
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $credentials = $this->credentials($this->link());
        $invalid = $this->payload($credentials);
        if ($case === 'changed-token') {
            $invalid['token'] = 'incorrect-token';
        } elseif ($case === 'other-user') {
            $invalid['email'] = $other->email;
        } else {
            $invalid['password_confirmation'] = 'DifferentPassword123!';
        }
        Event::fake([PasswordReset::class, Verified::class]);
        $this->postJson('/reset-password', $invalid)->assertUnprocessable();
        $this->assertSame($before, [$user->fresh()->password, $other->fresh()->password]);
        Event::assertNotDispatched(PasswordReset::class);
        Event::assertNotDispatched(Verified::class);
        $this->postJson('/reset-password', $this->payload($credentials))->assertOk();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_csrf_rejection_does_not_send_mail_or_consume_valid_token(): void
    {
        $user = User::factory()->unverified()->create();
        $this->app['env'] = 'local';
        try {
            $this->postJson('/forgot-password', ['email' => $user->email])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertCount(0, $this->letters);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $credentials = $this->credentials($this->link());
        $this->app['env'] = 'local';
        try {
            $this->postJson('/reset-password', $this->payload($credentials))->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertTrue(Hash::check('Password123!', $user->fresh()->password));
        $this->postJson('/reset-password', $this->payload($credentials))->assertOk();
    }

    public function test_new_password_allows_login_while_old_password_does_not(): void
    {
        $user = User::factory()->unverified()->create();
        $this->postJson('/forgot-password', ['email' => $user->email])->assertOk();
        $this->postJson('/reset-password', $this->payload($this->credentials($this->link())))->assertOk();
        $this->mock(TurnstileService::class, fn ($mock) => $mock->shouldReceive('verify')->twice()->andReturn(true));
        $this->postJson('/login', ['email' => $user->email, 'password' => 'Password123!', 'cf-turnstile-response' => 'test'])->assertUnprocessable();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'NewPassword123!', 'cf-turnstile-response' => 'test'])->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertCount(1, $this->letters); // No automatic verification email on login/reset.
    }

    private function link(int $index = 0): string
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($this->letters[$index]->getHtmlBody());
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $href = $anchor->getAttribute('href');
            if (str_starts_with(parse_url($href, PHP_URL_PATH) ?? '', '/reset-password/')) {
                $links[] = $href;
            }
        }
        $links = array_values(array_unique($links));
        $this->assertCount(1, $links, 'The rendered message must contain one distinct frontend reset URL.');

        return $links[0];
    }

    private function credentials(string $link): array
    {
        parse_str(parse_url($link, PHP_URL_QUERY), $query);

        return ['token' => rawurldecode(basename(parse_url($link, PHP_URL_PATH))), 'email' => $query['email']];
    }

    private function payload(array $credentials): array
    {
        return [...$credentials, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
    }
}
