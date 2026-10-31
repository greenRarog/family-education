<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\UserType;
use App\Events\NewMessage;
use App\Events\NewResponse;
use App\Models\Advertisement;
use App\Models\Child;
use App\Models\Family;
use App\Models\User;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertisementResponseRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Event::fake([NewResponse::class, NewMessage::class]);
    }

    public function test_minute_boundary_and_rejected_retries_do_not_extend_it(): void
    {
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 5; $i++) {
            $this->respond($this->advertisement())->assertCreated();
        }
        $ad = $this->advertisement();
        $this->assertThrottledWithoutSideEffects($ad, 60);
        $this->travel(59)->seconds();
        $this->assertThrottledWithoutSideEffects($ad, 1);
        $this->travel(1)->seconds();
        $this->respond($ad)->assertCreated();
        $this->assertDatabaseCount('advertisement_responses', 6);
        $this->assertDatabaseCount('conversations', 6);
        $this->assertDatabaseCount('messages', 6);
        Event::assertDispatchedTimes(NewResponse::class, 6);
    }

    public function test_daily_boundary_and_recovery_at_exactly_86400_seconds(): void
    {
        $start = now()->toImmutable();
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($batch = 0; $batch < 10; $batch++) {
            $this->travelTo($start->addSeconds($batch * 60));
            for ($i = 0; $i < 5; $i++) {
                $this->respond($this->advertisement())->assertCreated();
            }
        }
        $ad = $this->advertisement();
        $this->assertThrottledWithoutSideEffects($ad, 86400 - 540);
        $this->travelTo($start->addSeconds(600));
        $this->assertThrottledWithoutSideEffects($ad, 85800);
        $this->actingAs(User::factory()->create());
        $this->respond($ad)->assertCreated();
        $this->actingAs($user);
        $this->travelTo($start->addSeconds(86399));
        $this->assertThrottledWithoutSideEffects($ad, 1);
        $this->travelTo($start->addSeconds(86400));
        // Rejected requests must not consume the next minute's budget either.
        for ($i = 0; $i < 5; $i++) {
            $this->respond($i === 0 ? $ad : $this->advertisement())->assertCreated();
        }
        $this->assertThrottledWithoutSideEffects($this->advertisement(), 60);
        $this->assertDatabaseCount('advertisement_responses', 56);
        $this->assertDatabaseCount('conversations', 56);
        $this->assertDatabaseCount('messages', 56);
        Event::assertDispatchedTimes(NewResponse::class, 56);
    }

    public function test_retry_after_uses_a_minute_window_that_outlives_the_daily_window(): void
    {
        $start = now()->toImmutable();
        $this->actingAs(User::factory()->create());
        foreach ([0, 60, 120, 180, 240, 300, 360, 420, 480, 86390] as $seconds) {
            $this->travelTo($start->addSeconds($seconds));
            for ($i = 0; $i < 5; $i++) {
                $this->respond($this->advertisement())->assertCreated();
            }
        }
        $ad = $this->advertisement();
        $this->assertThrottledWithoutSideEffects($ad, 60);
        $this->travel(10)->seconds();
        $this->assertThrottledWithoutSideEffects($ad, 50);
        $this->travel(49)->seconds();
        $this->assertThrottledWithoutSideEffects($ad, 1);
        $this->travel(1)->seconds();
        $this->respond($ad)->assertCreated();
    }

    public function test_users_on_one_ip_are_independent_and_ip_or_session_changes_do_not_reset_the_budget(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->actingAs($first);
        for ($i = 0; $i < 5; $i++) {
            $this->respond($this->advertisement())->assertCreated();
        }
        $ad = $this->advertisement();
        $this->assertThrottledWithoutSideEffects($ad, 60);
        $this->actingAs($second);
        for ($i = 0; $i < 5; $i++) {
            $this->respond($i === 0 ? $ad : $this->advertisement())->assertCreated();
        }
        $this->actingAs($first);
        $this->app['session']->regenerate();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);
        $this->assertThrottledWithoutSideEffects($ad, 60);
    }

    public static function respondentRoles(): array
    {
        return [[UserType::FAMILY], [UserType::TEACHER]];
    }

    #[DataProvider('respondentRoles')]
    public function test_attempts_share_a_budget_across_advertisements_and_all_types(UserType $role): void
    {
        $this->actingAs(User::factory()->create(['user_type' => $role]));
        $types = AdvertisementType::cases();
        for ($i = 0; $i < 5; $i++) {
            $type = $types[$i % count($types)];
            $this->respond($this->advertisement(['type' => $type]))
                ->assertStatus($type->allowsResponsesFrom($role) ? 201 : 403);
        }
        foreach ($types as $type) {
            $this->assertThrottledWithoutSideEffects($this->advertisement(['type' => $type]), 60);
        }
    }

    public static function deniedAttempts(): array
    {
        return [
            'role' => ['role', 403],
            'validation' => ['validation', 422],
            'own advertisement' => ['own', 422],
            'draft' => ['draft', 422],
            'duplicate' => ['duplicate', 422],
        ];
    }

    #[DataProvider('deniedAttempts')]
    public function test_forbidden_and_invalid_attempts_count_without_side_effects(string $reason, int $status): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $ad = $this->advertisement(match ($reason) {
            'role' => ['type' => AdvertisementType::FAMILY_TO_TEACHER],
            'own' => ['user_id' => $user->id],
            'draft' => ['status' => AdvertisementStatus::DRAFT],
            default => [],
        });
        if ($reason === 'duplicate') {
            $this->respond($ad)->assertCreated();
            $this->travel(60)->seconds();
        }
        $snapshot = $this->businessState();
        for ($i = 0; $i < 5; $i++) {
            $this->respond($ad, $reason === 'validation' ? '' : 'Attempt')->assertStatus($status);
        }
        $this->assertSame($snapshot, $this->businessState());
        $this->assertThrottledWithoutSideEffects($ad, 60);
    }

    public function test_failed_attempts_also_consume_the_daily_budget(): void
    {
        $this->actingAs(User::factory()->create());
        $ad = $this->advertisement();
        $snapshot = $this->businessState();
        for ($batch = 0; $batch < 10; $batch++) {
            for ($i = 0; $i < 5; $i++) {
                $this->respond($ad, '')->assertUnprocessable();
            }
            $this->travel(60)->seconds();
        }
        $this->assertThrottledWithoutSideEffects($ad, 85800);
        $this->assertSame($snapshot, $this->businessState());
    }

    public function test_guest_requests_are_rejected_before_the_limiter(): void
    {
        $ad = $this->advertisement();
        $snapshot = $this->businessState();
        for ($i = 0; $i < 6; $i++) {
            $this->respond($ad)->assertUnauthorized();
        }
        $this->assertSame($snapshot, $this->businessState());
        $this->actingAs(User::factory()->create());
        for ($i = 0; $i < 5; $i++) {
            $this->respond($i === 0 ? $ad : $this->advertisement())->assertCreated();
        }
        $this->assertThrottledWithoutSideEffects($ad, 60);
        auth()->forgetUser();
        $this->respond($ad)->assertUnauthorized();
    }

    public function test_csrf_failures_do_not_charge_the_budget_and_precede_429(): void
    {
        $this->actingAs(User::factory()->create());
        $ad = $this->advertisement();
        $snapshot = $this->businessState();
        // Enable Laravel's real CSRF check, normally skipped in feature tests.
        $this->app['env'] = 'local';
        try {
            for ($i = 0; $i < 6; $i++) {
                $this->respond($ad)->assertStatus(419);
            }
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($snapshot, $this->businessState());
        for ($i = 0; $i < 5; $i++) {
            $this->respond($i === 0 ? $ad : $this->advertisement())->assertCreated();
        }
        $this->assertThrottledWithoutSideEffects($ad, 60);
        $this->app['env'] = 'local';
        try {
            $this->respond($ad)->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public static function actionOrders(): array
    {
        return [['responses', 'create', 'publish'], ['create', 'publish', 'responses']];
    }

    #[DataProvider('actionOrders')]
    public function test_response_creation_and_advertisement_actions_have_independent_budgets(string ...$actions): void
    {
        $family = Family::factory()->create();
        Child::factory()->create(['family_id' => $family->id]);
        $this->actingAs($family->user);
        foreach ($actions as $action) {
            for ($i = 0; $i < 6; $i++) {
                $response = match ($action) {
                    'responses' => $this->respond($this->advertisement()),
                    'create' => $this->postJson('/api/advertisements', [
                        'type' => 'family_to_family', 'city_id' => $family->city_id,
                        'child_ids' => $family->children()->pluck('id')->all(),
                        'participant_age_from' => 5, 'participant_age_to' => 12,
                        'description' => 'Group for families',
                    ]),
                    'publish' => $this->postJson('/api/advertisements/'.Advertisement::factory()->create([
                        'user_id' => $family->user_id,
                    ])->id.'/publish'),
                };
                $response->assertStatus($i === 5 ? 429 : ($action === 'publish' ? 200 : 201));
            }
        }
    }

    public function test_existing_conversation_actions_and_reading_work_after_response_limit_is_exhausted(): void
    {
        $respondent = User::factory()->create();
        $owner = User::factory()->create();
        $ad = $this->advertisement(['user_id' => $owner->id]);
        $this->actingAs($respondent);
        $response = $this->respond($ad)->assertCreated();
        $responseId = $response->json('response.id');
        $conversationId = DB::table('conversations')->where('advertisement_response_id', $responseId)->value('id');
        $this->actingAs($owner)->postJson("/api/advertisement-responses/{$responseId}/accept")->assertOk();
        $this->actingAs($respondent);
        for ($i = 0; $i < 6; $i++) {
            $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Follow-up'])->assertCreated();
            $this->getJson('/api/conversations')->assertOk();
            $this->getJson("/api/conversations/{$conversationId}/messages?after_id=0")->assertOk();
            $this->postJson("/api/conversations/{$conversationId}/read")->assertOk();
        }
        for ($i = 0; $i < 4; $i++) {
            $this->respond($this->advertisement())->assertCreated();
        }
        $this->assertThrottledWithoutSideEffects($this->advertisement(), 60);
        $this->postJson("/api/conversations/{$conversationId}/messages", ['message' => 'Still allowed'])->assertCreated();
        $this->getJson("/api/advertisements/{$ad->id}")->assertOk();
        $this->getJson("/api/conversations/{$conversationId}")->assertOk();
        $this->postJson("/api/conversations/{$conversationId}/read")->assertOk();
        $this->actingAs($owner);
        for ($i = 0; $i < 5; $i++) {
            $this->respond($this->advertisement())->assertCreated();
        }
        $this->assertThrottledWithoutSideEffects($this->advertisement(), 60);
        $this->postJson("/api/advertisement-responses/{$responseId}/reject")->assertOk();
    }

    public function test_database_cache_shares_both_windows_between_fresh_limiter_instances(): void
    {
        $this->actingAs(User::factory()->create());
        for ($batch = 0; $batch < 10; $batch++) {
            $this->useFreshDatabaseLimiter();
            for ($i = 0; $i < 5; $i++) {
                $this->respond($this->advertisement())->assertCreated();
            }
            $this->useFreshDatabaseLimiter();
            $this->assertThrottledWithoutSideEffects($this->advertisement(), $batch === 9 ? 85860 : 60);
            $this->travel(60)->seconds();
        }
        $this->useFreshDatabaseLimiter();
        $this->assertThrottledWithoutSideEffects($this->advertisement(), 85800);
    }

    private function useFreshDatabaseLimiter(): void
    {
        $callback = RateLimiter::limiter('responses-create');
        $limiter = new CacheRateLimiter((new CacheManager($this->app))->store('database'));
        $limiter->for('responses-create', $callback);
        RateLimiter::swap($limiter);
    }

    private function advertisement(array $attributes = []): Advertisement
    {
        return Advertisement::factory()->create(array_merge(['status' => AdvertisementStatus::PUBLISHED], $attributes));
    }

    private function respond(Advertisement $ad, string $message = 'Initial response'): TestResponse
    {
        return $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => $message]);
    }

    private function assertThrottledWithoutSideEffects(Advertisement $ad, int $seconds): void
    {
        $snapshot = $this->businessState();
        // No Accept header: the API must still return JSON rather than HTML.
        $this->post("/api/advertisements/{$ad->id}/responses", ['message' => 'Rate-limited attempt'])
            ->assertStatus(429)->assertHeader('Content-Type', 'application/json')
            ->assertHeader('Retry-After', (string) $seconds)
            ->assertJsonPath('retry_after', $seconds)
            ->assertJsonPath('message', 'Слишком много попыток. Повторите запрос позже.');
        $this->assertSame($snapshot, $this->businessState());
    }

    private function businessState(): array
    {
        $state = [];
        foreach (['advertisements', 'advertisement_child', 'advertisement_subject', 'advertisement_responses', 'conversations', 'messages', 'jobs'] as $table) {
            $state[$table] = DB::table($table)->get()->toJson();
        }
        foreach ([NewResponse::class, NewMessage::class] as $event) {
            $state[$event] = Event::dispatched($event)->count();
        }

        return $state;
    }
}
