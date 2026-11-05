<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\Telegram\BotApi;
use App\Services\Telegram\TelegramLinkService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    private TelegramLinkService $links;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.enabled' => true, 'services.telegram.token' => '123456:synthetic', 'services.telegram.username' => 'education_test_bot']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $this->links = app(TelegramLinkService::class);
    }

    private function create(User $user, string $session = 'session-a'): array
    {
        return $this->links->browser($user, $session, 'create');
    }

    private function claim(array $attempt, int $chat = 777, int $update = 1, array $override = []): string
    {
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        $this->links->update(array_replace_recursive(['update_id' => $update, 'message' => [
            'text' => '/start '.$query['start'], 'chat' => ['id' => $chat, 'type' => 'private'],
            'from' => ['id' => $chat, 'is_bot' => false],
        ]], $override), app(BotApi::class));
        $sent = Http::recorded()->last();
        preg_match('/[0-9]{8}/', $sent[0]['text'] ?? '', $matches);

        return $matches[0] ?? '00000000';
    }

    private function confirm(User $user, array $attempt, string $code, string $session = 'session-a'): array
    {
        return $this->links->browser($user, $session, 'confirm', ['attempt_id' => $attempt['attempt_id'], 'code' => $code]);
    }

    public function test_link_is_not_confirmation_and_success_is_idempotent_with_separate_opt_in(): void
    {
        $user = User::factory()->unverified()->family()->create();
        $user->notificationSetting()->create(['email_enabled' => false, 'telegram_enabled' => true]);
        $attempt = $this->create($user);
        $row = DB::table('telegram_link_attempts')->first();
        $this->assertStringNotContainsString($row->nonce_hash, $attempt['url']);
        $code = $this->claim($attempt);
        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertNotSame($code, DB::table('telegram_link_attempts')->value('code_hash'));
        $this->assertTrue($this->confirm($user, $attempt, $code)['connected']);
        $this->assertSame('777', $user->fresh()->telegram_chat_id);
        $this->assertFalse($user->fresh()->notificationSetting->email_enabled);
        $this->assertFalse($user->fresh()->notificationSetting->telegram_enabled);
        $this->assertTrue($this->confirm($user, $attempt, $code)['connected']);
        $this->assertSame(1, $user->fresh()->telegram_binding_version);
        $this->actingAs($user->fresh())->putJson('/api/notification-settings', ['email_enabled' => false, 'telegram_enabled' => true])->assertOk();
    }

    public function test_attempt_belongs_to_account_and_original_session(): void
    {
        $user = User::factory()->family()->create();
        $other = User::factory()->family()->create();
        $attempt = $this->create($user);
        $code = $this->claim($attempt);
        $this->assertSame('telegram_invalid', $this->confirm($other, $attempt, $code)['code']);
        $this->assertSame('telegram_invalid', $this->confirm($user, $attempt, $code, 'other-session')['code']);
        $this->assertTrue($this->confirm($user, $attempt, $code)['connected']);
    }

    public function test_five_bad_codes_commit_counter_and_cancel_attempt(): void
    {
        $user = User::factory()->family()->create();
        $attempt = $this->create($user);
        $code = $this->claim($attempt);
        $bad = $code === '11111111' ? '22222222' : '11111111';
        for ($i = 0; $i < 5; $i++) {
            $result = $this->confirm($user, $attempt, $bad);
            $this->assertSame(422, $result['_status']);
        }
        $this->assertSame('telegram_attempt_cancelled', $result['code']);
        $this->assertDatabaseCount('telegram_link_attempts', 0);
        $this->assertSame(429, $this->confirm($user, $attempt, $code)['_status']);
    }

    public function test_creation_limits_are_shared_between_sessions_and_include_the_day_limit(): void
    {
        $user = User::factory()->family()->create();
        for ($window = 0; $window < 4; $window++) {
            for ($i = 0; $i < 5; $i++) {
                $this->assertArrayHasKey('url', $this->create($user, 'session-'.$i));
            }
            $this->assertSame(429, $this->create($user, 'other-session')['_status']);
            $this->travel(61)->seconds();
        }
        $result = $this->create($user);
        $this->assertSame(429, $result['_status']);
        $this->assertGreaterThan(80000, $result['retry_after']);
        $this->travel(1)->days();
        $this->assertArrayHasKey('url', $this->create($user));
    }

    public function test_exact_expiry_and_replacement_reject_old_updates_and_codes(): void
    {
        $user = User::factory()->family()->create();
        $first = $this->create($user);
        $next = $this->create($user);
        $this->claim($first);
        Http::assertNothingSent();
        $code = $this->claim($next, update: 2);
        $this->travelTo(Carbon::parse($next['expires_at']));
        $this->assertSame('telegram_expired', $this->confirm($user, $next, $code)['code']);
        $this->assertDatabaseCount('telegram_link_attempts', 0);
    }

    public function test_spoofed_group_edited_and_bot_updates_are_ignored_and_replays_do_not_replace_candidate(): void
    {
        $user = User::factory()->family()->create();
        $attempt = $this->create($user);
        $this->claim($attempt, update: 1, override: ['message' => ['chat' => ['type' => 'group']]]);
        $this->claim($attempt, update: 2, override: ['message' => ['from' => ['id' => 888]]]);
        $this->claim($attempt, update: 3, override: ['message' => ['from' => ['is_bot' => true]]]);
        $this->links->update(['update_id' => 4, 'edited_message' => ['text' => '/start invalid']], app(BotApi::class));
        Http::assertNothingSent();
        $code = $this->claim($attempt, update: 5);
        $this->claim($attempt, chat: 888, update: 5);
        $this->claim($attempt, chat: 888, update: 6);
        Http::assertSentCount(1);
        $this->assertTrue($this->confirm($user, $attempt, $code)['connected']);
        $this->assertSame('777', $user->fresh()->telegram_chat_id);
    }

    public function test_unique_chat_cannot_be_taken_and_unlink_allows_new_owner(): void
    {
        $one = User::factory()->family()->create();
        $two = User::factory()->family()->create();
        $a = $this->create($one);
        $b = $this->create($two);
        $codeA = $this->claim($a);
        $codeB = $this->claim($b, update: 2);
        $this->assertTrue($this->confirm($one, $a, $codeA)['connected']);
        $this->assertSame('telegram_conflict', $this->confirm($two, $b, $codeB)['code']);
        $this->links->browser($one, 'session-a', 'unlink');
        $this->assertTrue($this->confirm($two, $b, $codeB)['connected']);
        $this->assertNull($one->fresh()->telegram_chat_id);
    }

    public function test_password_change_and_deletion_revoke_pending_secrets(): void
    {
        $user = User::factory()->family()->create();
        $attempt = $this->create($user);
        $code = $this->claim($attempt);
        $user->forceFill(['session_version' => 1])->save();
        $this->assertSame('telegram_invalid', $this->confirm($user, $attempt, $code)['code']);
        app(AccountDeletionService::class)->delete($user);
        $this->assertDatabaseCount('telegram_link_attempts', 0);
        $this->assertSame(401, $this->links->browser($user, 'session-a', 'unlink')['_status']);
    }

    public function test_legacy_binding_cannot_deliver_or_enable_and_unlink_works_without_provider(): void
    {
        $user = User::factory()->family()->create(['telegram_chat_id' => '777']);
        $user->notificationSetting()->create(['email_enabled' => true, 'telegram_enabled' => true]);
        $this->assertFalse($user->telegramNotificationsEnabled());
        $this->actingAs($user)->getJson('/api/notification-settings')->assertJsonPath('telegram_enabled', false);
        $this->putJson('/api/notification-settings', ['email_enabled' => true, 'telegram_enabled' => true])->assertStatus(422)->assertJsonPath('code', 'telegram_not_connected');
        config(['services.telegram.enabled' => false, 'services.telegram.token' => null]);
        $this->deleteJson('/api/telegram-link', [], ['X-Expected-Account-Id' => (string) $user->id])->assertOk()->assertJsonPath('connected', false);
        $this->assertNull($user->fresh()->telegram_chat_id);
        Http::assertNothingSent();
    }

    public function test_browser_identity_precedes_limiter_and_get_does_not_expose_secrets(): void
    {
        $this->postJson('/api/telegram-link/attempts')->assertUnauthorized();
        $user = User::factory()->family()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/telegram-link/attempts')->assertStatus(409);
        }
        $this->withCredentials()->withCookie(config('session.cookie'), session()->getId());
        $headers = ['X-Expected-Account-Id' => (string) $user->id];
        $attempt = $this->postJson('/api/telegram-link/attempts', [], $headers)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
        $this->getJson('/api/telegram-link')->assertExactJson(['available' => true, 'connected' => false, 'needs_reconnect' => false, 'binding_version' => 0]);
        $code = $this->claim($attempt);
        $this->postJson('/api/telegram-link/confirm', ['attempt_id' => $attempt['attempt_id'], 'code' => $code], $headers)->assertOk()->assertJsonPath('connected', true);
        $this->assertArrayNotHasKey('telegram_chat_id', $user->fresh()->toArray());
        $user->fill(['telegram_chat_id' => '888']);
        $this->assertNotSame('888', $user->telegram_chat_id);
    }

    public function test_cleanup_retention_and_closed_restore_revocation(): void
    {
        $user = User::factory()->family()->create();
        $attempt = $this->create($user);
        $code = $this->claim($attempt);
        $this->confirm($user, $attempt, $code);
        $this->artisan('telegram:invalidate-restored-links', ['--force' => true])->assertFailed();
        config(['services.telegram.enabled' => false]);
        $this->artisan('telegram:invalidate-restored-links', ['--force' => true])->assertSuccessful();
        $this->assertNull($user->fresh()->telegram_chat_id);
        $this->assertSame(2, $user->fresh()->telegram_binding_version);
        $this->assertDatabaseCount('telegram_link_attempts', 0);
        $this->travel(30)->days();
        $this->artisan('telegram:prune')->assertSuccessful();
        $this->assertDatabaseCount('telegram_processed_updates', 0);
    }

    public function test_poller_refuses_webhook_and_does_not_delete_it(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'username' => 'education_test_bot']]),
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://existing.invalid/hook']])]);
        $this->artisan('telegram:poll', ['--once' => true])->assertFailed();
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'deleteWebhook') || str_contains($request->url(), 'getUpdates'));
    }

    public function test_plain_start_has_bounded_help_without_creating_a_link(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->links->update(['update_id' => $i, 'message' => ['text' => '/start',
                'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'is_bot' => false],
            ]], app(BotApi::class));
        }
        Http::assertSentCount(1);
        $this->assertDatabaseCount('telegram_link_attempts', 0);
    }

    public function test_poller_advances_cursor_only_after_handling_and_deduplicates(): void
    {
        $user = User::factory()->family()->create();
        $attempt = $this->create($user);
        parse_str(parse_url($attempt['url'], PHP_URL_QUERY), $query);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            '*/getMe' => Http::response(['ok' => true, 'result' => ['id' => 123456, 'username' => 'education_test_bot']]),
            '*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            '*/getUpdates' => Http::response(['ok' => true, 'result' => [['update_id' => 42, 'message' => [
                'text' => '/start '.$query['start'], 'chat' => ['id' => 777, 'type' => 'private'], 'from' => ['id' => 777, 'is_bot' => false],
            ]]]]),
            '*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);
        DB::table('telegram_polling_cursors')->insert(['bot_id' => '123456', 'next_update_id' => 999999]);
        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();
        $this->assertDatabaseHas('telegram_polling_cursors', ['bot_id' => '123456', 'next_update_id' => 43]);
        $this->artisan('telegram:poll', ['--once' => true])->assertSuccessful();
        $this->assertSame(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/sendMessage'))->count());
        $offsets = Http::recorded(fn ($request) => str_ends_with($request->url(), '/getUpdates'))->map(fn ($pair) => $pair[0]['offset'])->values()->all();
        $this->assertSame([0, 43], $offsets);
        $this->assertDatabaseCount('telegram_processed_updates', 1);
        $this->assertNull($user->fresh()->telegram_chat_id);
    }

    public function test_csrf_precedes_attempt_creation_and_disabled_admin_and_blocked_policy(): void
    {
        $user = User::factory()->family()->create();
        $this->actingAs($user);
        // Enable the real CSRF middleware path normally bypassed by Laravel unit tests.
        $this->app->instance('env', 'local');
        $this->postJson('/api/telegram-link/attempts', [], ['X-Expected-Account-Id' => (string) $user->id])->assertStatus(419);
        $this->assertDatabaseCount('telegram_link_attempts', 0);
        $this->app->instance('env', 'testing');
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->assertSame(403, $this->create($admin)['_status']);
        config(['services.telegram.enabled' => false]);
        $this->assertSame('telegram_unavailable', $this->create($user)['code']);
        config(['services.telegram.enabled' => true]);
        app(UserBlockService::class)->block($admin, $user, ['reason_code' => 'spam', 'reason' => 'Synthetic linking policy.']);
        $attempt = $this->create($user);
        $code = $this->claim($attempt);
        $this->assertTrue($this->confirm($user, $attempt, $code)['connected']);
    }

    public function test_provider_failure_never_exposes_token_and_replay_does_not_regenerate_code(): void
    {
        $user = User::factory()->family()->create();
        $attempt = $this->create($user);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'sensitive'], 500)]);
        try {
            $this->claim($attempt);
            $this->fail('Provider failure must surface.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Telegram request failed.', $error->getMessage());
            $this->assertNull($error->getPrevious());
        }
        $this->claim($attempt);
        Http::assertSentCount(1);
        $this->assertNull($user->fresh()->telegram_chat_id);
    }
}
