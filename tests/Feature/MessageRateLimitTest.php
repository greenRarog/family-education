<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Events\NewMessage;
use App\Events\NewResponse;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Child;
use App\Models\Conversation;
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

class MessageRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Event::fake([NewMessage::class, NewResponse::class]);
    }

    public static function allowedSenders(): array
    {
        return [
            'owner before acceptance' => [true, AdvertisementResponseStatus::SENT],
            'owner after acceptance' => [true, AdvertisementResponseStatus::ACCEPTED],
            'respondent after acceptance' => [false, AdvertisementResponseStatus::ACCEPTED],
        ];
    }

    #[DataProvider('allowedSenders')]
    public function test_minute_boundary_and_retry_after_recovery_create_exactly_one_message(bool $asOwner, AdvertisementResponseStatus $status): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user, $asOwner, $status);
        $this->actingAs($user);
        $this->sendBatch($conversation);
        $this->assertThrottled($conversation, 60);
        $this->travel(59)->seconds();
        $this->assertThrottled($conversation, 1);
        $this->travel(1)->seconds();
        $this->send($conversation, 'Retry after recovery')->assertCreated()
            ->assertJsonPath('message.message', 'Retry after recovery');
        $this->assertDatabaseCount('messages', 31);
        $this->assertSame(1, DB::table('messages')->where('body', 'Retry after recovery')->count());
        Event::assertDispatchedTimes(NewMessage::class, 31);
    }

    public static function cacheStores(): array
    {
        return ['array' => [false], 'shared database' => [true]];
    }

    #[DataProvider('cacheStores')]
    public function test_hour_boundary_recovers_without_charging_rejected_retries(bool $sharedCache): void
    {
        $start = now()->toImmutable();
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $this->actingAs($user);
        for ($batch = 0; $batch < 10; $batch++) {
            $this->travelTo($start->addSeconds($batch * 60));
            if ($sharedCache) {
                $this->useFreshDatabaseLimiter();
            }
            $this->sendBatch($conversation);
            if ($sharedCache) {
                $this->useFreshDatabaseLimiter();
            }
            $this->assertThrottled($conversation, $batch === 9 ? 3060 : 60);
        }
        $this->travelTo($start->addSeconds(600));
        $this->assertThrottled($conversation, 3000);
        // The other participant on the same IP retains an independent hour budget.
        $this->actingAs($conversation->advertisementResponse->advertisement->user);
        $this->send($conversation)->assertCreated();
        $this->actingAs($user);
        $this->travelTo($start->addSeconds(3599));
        $this->assertThrottled($conversation, 1);
        $this->travelTo($start->addSeconds(3600));
        $this->sendBatch($conversation);
        $this->assertThrottled($conversation, 60);
        $this->assertDatabaseCount('messages', 331);
        Event::assertDispatchedTimes(NewMessage::class, 331);
    }

    public function test_retry_after_uses_the_minute_window_when_it_outlives_the_hour(): void
    {
        $start = now()->toImmutable();
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $this->actingAs($user);
        foreach ([0, 60, 120, 180, 240, 300, 360, 420, 480, 3590] as $seconds) {
            $this->travelTo($start->addSeconds($seconds));
            $this->sendBatch($conversation);
        }
        $this->assertThrottled($conversation, 60);
        $this->travel(10)->seconds();
        $this->assertThrottled($conversation, 50);
        $this->travel(49)->seconds();
        $this->assertThrottled($conversation, 1);
        $this->travel(1)->seconds();
        $this->send($conversation)->assertCreated();
        $this->assertDatabaseCount('messages', 301);
    }

    public function test_budget_is_shared_across_conversations_but_not_users_and_survives_ip_and_session_changes(): void
    {
        $user = User::factory()->create();
        $conversations = [];
        foreach (AdvertisementType::cases() as $type) {
            $conversations[] = $this->conversation($user, type: $type);
        }
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->actingAs($user);
        for ($i = 0; $i < 30; $i++) {
            $this->send($conversations[$i % count($conversations)])->assertCreated();
        }
        foreach ($conversations as $conversation) {
            $this->assertThrottled($conversation, 60);
        }
        $this->actingAs($conversations[0]->advertisementResponse->advertisement->user);
        $this->sendBatch($conversations[0]);
        $this->actingAs($user);
        $this->app['session']->regenerate();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);
        $this->assertThrottled($conversations[0], 60);
    }

    public static function deniedAttempts(): array
    {
        return [
            'nonparticipant' => ['forbidden', 403],
            'invalid body' => ['invalid', 422],
            'before acceptance' => ['pending', 422],
            'rejected conversation' => ['rejected', 422],
        ];
    }

    #[DataProvider('deniedAttempts')]
    public function test_forbidden_and_invalid_attempts_count_without_writing_data(string $reason, int $status): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user, status: match ($reason) {
            'pending' => AdvertisementResponseStatus::SENT,
            'rejected' => AdvertisementResponseStatus::REJECTED,
            default => AdvertisementResponseStatus::ACCEPTED,
        });
        $this->actingAs($reason === 'forbidden' ? User::factory()->create() : $user);
        $snapshot = $this->businessState();
        for ($i = 0; $i < 30; $i++) {
            $this->send($conversation, $reason === 'invalid' ? '' : 'Attempt')->assertStatus($status);
        }
        $this->assertThrottled($conversation, 60);
        $this->assertSame($snapshot, $this->businessState());
    }

    public function test_invalid_attempts_also_consume_the_hour_budget(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $this->actingAs($user);
        $snapshot = $this->businessState();
        for ($batch = 0; $batch < 10; $batch++) {
            for ($i = 0; $i < 30; $i++) {
                $this->send($conversation, '')->assertUnprocessable();
            }
            $this->travel(60)->seconds();
        }
        $this->assertThrottled($conversation, 3000);
        $this->assertSame($snapshot, $this->businessState());
    }

    public function test_authentication_precedes_the_limiter_and_guests_do_not_charge_it(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $snapshot = $this->businessState();
        for ($i = 0; $i < 31; $i++) {
            $this->send($conversation)->assertUnauthorized();
        }
        $this->assertSame($snapshot, $this->businessState());
        $this->actingAs($user);
        $this->sendBatch($conversation);
        $this->assertThrottled($conversation, 60);
        auth()->forgetUser();
        $this->send($conversation)->assertUnauthorized();
    }

    public function test_csrf_precedes_the_limiter_and_failures_do_not_charge_it(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $this->actingAs($user);
        $snapshot = $this->businessState();
        // Exercise the real CSRF check, normally skipped by Laravel feature tests.
        $this->app['env'] = 'local';
        try {
            for ($i = 0; $i < 31; $i++) {
                $this->send($conversation)->assertStatus(419);
            }
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame($snapshot, $this->businessState());
        $this->sendBatch($conversation);
        $this->assertThrottled($conversation, 60);
        $this->app['env'] = 'local';
        try {
            $this->send($conversation)->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
    }

    public function test_reading_polling_and_read_receipts_do_not_charge_the_budget_and_work_when_exhausted(): void
    {
        $user = User::factory()->create();
        $conversation = $this->conversation($user);
        $this->actingAs($conversation->advertisementResponse->advertisement->user);
        $incoming = $this->send($conversation)->assertCreated()->json('message.id');
        $this->actingAs($user);
        for ($i = 0; $i < 31; $i++) {
            $this->readConversation($conversation, $incoming);
        }
        $this->assertNotNull(DB::table('messages')->where('id', $incoming)->value('read_at'));
        $this->sendBatch($conversation);
        $this->assertThrottled($conversation, 60);
        $this->readConversation($conversation, $incoming);
    }

    public static function actionOrders(): array
    {
        return ['messages first' => [true], 'other actions first' => [false]];
    }

    #[DataProvider('actionOrders')]
    public function test_messages_have_a_separate_budget_from_ads_and_initial_response_text(bool $messagesFirst): void
    {
        $family = Family::factory()->create();
        Child::factory()->create(['family_id' => $family->id]);
        $user = $family->user;
        $conversation = $this->conversation($user);
        $this->actingAs($user);
        $actions = $messagesFirst ? ['messages', 'create', 'publish', 'responses'] : ['create', 'publish', 'responses', 'messages'];
        foreach ($actions as $action) {
            if ($action === 'messages') {
                $this->sendBatch($conversation);
                $this->assertThrottled($conversation, 60);

                continue;
            }
            for ($i = 0; $i < 6; $i++) {
                $response = match ($action) {
                    'create' => $this->postJson('/api/advertisements', [
                        'type' => 'family_to_family', 'city_id' => $family->city_id,
                        'child_ids' => $family->children()->pluck('id')->all(),
                        'participant_age_from' => 5, 'participant_age_to' => 12,
                        'description' => 'Group for families',
                    ]),
                    'publish' => $this->postJson('/api/advertisements/'.Advertisement::factory()->create(['user_id' => $user->id])->id.'/publish'),
                    'responses' => $this->postJson('/api/advertisements/'.Advertisement::factory()->create([
                        'status' => AdvertisementStatus::PUBLISHED,
                    ])->id.'/responses', ['message' => 'Initial response']),
                };
                $response->assertStatus($i === 5 ? 429 : ($action === 'publish' ? 200 : 201));
            }
        }
        $this->assertDatabaseCount('messages', 35);
        Event::assertDispatchedTimes(NewMessage::class, 30);
        Event::assertDispatchedTimes(NewResponse::class, 5);
    }

    private function conversation(
        User $user,
        bool $asOwner = false,
        AdvertisementResponseStatus $status = AdvertisementResponseStatus::ACCEPTED,
        AdvertisementType $type = AdvertisementType::FAMILY_TO_FAMILY,
    ): Conversation {
        $other = User::factory()->create();
        $ad = Advertisement::factory()->create([
            'user_id' => $asOwner ? $user->id : $other->id,
            'status' => AdvertisementStatus::PUBLISHED, 'type' => $type,
        ]);
        $response = AdvertisementResponse::query()->create([
            'advertisement_id' => $ad->id, 'user_id' => $asOwner ? $other->id : $user->id,
            'status' => $status,
        ]);

        return Conversation::query()->create(['advertisement_response_id' => $response->id]);
    }

    private function send(Conversation $conversation, string $message = 'Follow-up'): TestResponse
    {
        return $this->postJson("/api/conversations/{$conversation->id}/messages", ['message' => $message]);
    }

    private function sendBatch(Conversation $conversation): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->send($conversation)->assertCreated();
        }
    }

    private function readConversation(Conversation $conversation, int $throughId): void
    {
        $this->getJson('/api/conversations')->assertOk();
        $this->getJson('/api/conversations/unread-count')->assertOk();
        $this->getJson("/api/conversations/{$conversation->id}")->assertOk();
        $this->getJson("/api/conversations/{$conversation->id}/messages?after_id=0")->assertOk();
        $this->postJson("/api/conversations/{$conversation->id}/read", ['through_id' => $throughId])->assertOk();
    }

    private function assertThrottled(Conversation $conversation, int $seconds): void
    {
        $snapshot = $this->businessState();
        // The API must return JSON even without an Accept header.
        $this->post("/api/conversations/{$conversation->id}/messages", ['message' => 'Retry after recovery'])
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
        foreach ([NewMessage::class, NewResponse::class] as $event) {
            $state[$event] = Event::dispatched($event)->count();
        }

        return $state;
    }

    private function useFreshDatabaseLimiter(): void
    {
        $callback = RateLimiter::limiter('messages-create');
        $limiter = new CacheRateLimiter((new CacheManager($this->app))->store('database'));
        $limiter->for('messages-create', $callback);
        RateLimiter::swap($limiter);
    }
}
