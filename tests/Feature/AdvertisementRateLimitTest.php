<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Models\Advertisement;
use App\Models\Child;
use App\Models\Family;
use App\Models\User;
use Illuminate\Cache\CacheManager;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertisementRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public static function actions(): array
    {
        return ['create' => ['create', 201], 'publish' => ['publish', 200]];
    }

    public static function actionNames(): array
    {
        return [['create'], ['publish']];
    }

    #[DataProvider('actions')]
    public function test_minute_boundary_and_rejected_retries_do_not_extend_it(string $action, int $success): void
    {
        $this->freezeTime();
        $user = $this->familyUser();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($this->target($action, $user))->assertStatus($success);
        }
        $target = $this->target($action, $user);
        $snapshot = $this->businessState();
        $this->assertThrottled($this->postTarget($target), 60);
        $this->assertSame($snapshot, $this->businessState());
        $this->travel(59)->seconds();
        $this->assertThrottled($this->postTarget($target), 1);
        $this->assertSame($snapshot, $this->businessState());
        $this->travel(1)->seconds();
        $this->postTarget($target)->assertStatus($success);
    }

    #[DataProvider('actions')]
    public function test_daily_boundary_uses_the_longest_retry_and_recovers_after_24_hours(string $action, int $success): void
    {
        $this->freezeTime();
        $start = now()->toImmutable();
        $user = $this->familyUser();
        $this->actingAs($user);
        for ($batch = 0; $batch < 4; $batch++) {
            $this->travelTo($start->addSeconds($batch * 60));
            for ($i = 0; $i < 5; $i++) {
                $this->postTarget($this->target($action, $user))->assertStatus($success);
            }
        }
        $target = $this->target($action, $user);
        $snapshot = $this->businessState();
        $this->assertThrottled($this->postTarget($target), 86400 - 180);
        $this->travelTo($start->addSeconds(86399));
        $this->assertThrottled($this->postTarget($target), 1);
        $this->assertSame($snapshot, $this->businessState());
        $this->travelTo($start->addSeconds(86400));
        $this->postTarget($target)->assertStatus($success);
    }

    #[DataProvider('actions')]
    public function test_a_new_minute_window_can_outlive_the_exhausted_daily_window(string $action, int $success): void
    {
        $this->freezeTime();
        $start = now()->toImmutable();
        $user = $this->familyUser();
        $this->actingAs($user);
        foreach ([0, 60, 120, 86390] as $seconds) {
            $this->travelTo($start->addSeconds($seconds));
            for ($i = 0; $i < 5; $i++) {
                $this->postTarget($this->target($action, $user))->assertStatus($success);
            }
        }
        $target = $this->target($action, $user);
        $this->assertThrottled($this->postTarget($target), 60);
        $this->travel(10)->seconds();
        $this->assertThrottled($this->postTarget($target), 50);
        $this->travel(50)->seconds();
        $this->postTarget($target)->assertStatus($success);
    }

    #[DataProvider('actions')]
    public function test_users_on_one_ip_have_separate_budgets_but_new_ip_and_session_do_not_reset_them(string $action, int $success): void
    {
        $this->freezeTime();
        $first = $this->familyUser();
        $second = $this->familyUser();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->actingAs($first);
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($this->target($action, $first))->assertStatus($success);
        }
        $this->actingAs($second);
        $this->postTarget($this->target($action, $second))->assertStatus($success);
        $this->actingAs($first);
        $this->app['session']->regenerate();
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.2']);
        $this->assertThrottled($this->postTarget($this->target($action, $first)), 60);
    }

    public function test_creation_and_publication_have_independent_budgets(): void
    {
        $this->freezeTime();
        $user = $this->familyUser();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($this->target('create', $user))->assertCreated();
        }
        $this->assertThrottled($this->postTarget($this->target('create', $user)), 60);
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($this->target('publish', $user))->assertOk();
        }
        $this->assertThrottled($this->postTarget($this->target('publish', $user)), 60);
        $this->getJson('/api/advertisements')->assertOk();
        $this->getJson('/api/conversations')->assertOk();
    }

    #[DataProvider('actionNames')]
    public function test_forbidden_attempts_consume_the_budget_without_mutating_business_data(string $action): void
    {
        $this->freezeTime();
        $user = $this->familyUser();
        $this->actingAs($user);
        $target = $this->target($action, $action === 'publish' ? $this->familyUser() : $user);
        if ($action === 'create') {
            $target[1]['type'] = AdvertisementType::TEACHER_TO_SERVICE->value;
        }
        $snapshot = $this->businessState();
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($target)->assertForbidden();
        }
        $this->assertThrottled($this->postTarget($target), 60);
        $this->assertSame($snapshot, $this->businessState());
    }

    #[DataProvider('actionNames')]
    public function test_invalid_attempts_count_across_types_and_objects(string $action): void
    {
        $this->freezeTime();
        $user = $this->familyUser();
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $target = $this->target($action, $user);
            if ($action === 'create') {
                $target[1]['type'] = $i % 2 === 0 ? 'family_to_family' : 'family_to_teacher';
                $target[1]['description'] = '';
            } else {
                Advertisement::query()->where('user_id', $user->id)->update(['status' => AdvertisementStatus::CLOSED]);
            }
            $this->postTarget($target)->assertUnprocessable();
        }
        $target = $this->target($action, $user);
        $snapshot = $this->businessState();
        $this->assertThrottled($this->postTarget($target), 60);
        $this->assertSame($snapshot, $this->businessState());
    }

    #[DataProvider('actions')]
    public function test_guest_attempts_do_not_charge_an_authenticated_user(string $action, int $success): void
    {
        $this->freezeTime();
        $user = $this->familyUser();
        $target = $this->target($action, $user);
        for ($i = 0; $i < 6; $i++) {
            $this->postTarget($target)->assertUnauthorized();
        }
        $this->actingAs($user);
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($this->target($action, $user))->assertStatus($success);
        }
    }

    #[DataProvider('actions')]
    public function test_csrf_failures_are_rejected_before_the_limiter(string $action, int $success): void
    {
        $this->freezeTime();
        $user = $this->familyUser();
        $this->actingAs($user);
        $target = $this->target($action, $user);
        // Enable Laravel's real CSRF check, normally skipped in feature tests.
        $this->app['env'] = 'local';
        try {
            for ($i = 0; $i < 6; $i++) {
                $this->postTarget($target)->assertStatus(419);
            }
        } finally {
            $this->app['env'] = 'testing';
        }
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($this->target($action, $user))->assertStatus($success);
        }
    }

    #[DataProvider('actions')]
    public function test_database_cache_shares_the_budget_between_fresh_limiter_instances(string $action, int $success): void
    {
        $this->freezeTime();
        $user = $this->familyUser();
        $this->actingAs($user);
        $this->useFreshDatabaseLimiter();
        for ($i = 0; $i < 5; $i++) {
            $this->postTarget($this->target($action, $user))->assertStatus($success);
        }
        $this->useFreshDatabaseLimiter();
        $this->assertThrottled($this->postTarget($this->target($action, $user)), 60);
    }

    private function useFreshDatabaseLimiter(): void
    {
        $limiter = new CacheRateLimiter((new CacheManager($this->app))->store('database'));
        foreach (['ads-create', 'ads-publish'] as $name) {
            $limiter->for($name, RateLimiter::limiter($name));
        }
        RateLimiter::swap($limiter);
    }

    private function familyUser(): User
    {
        $family = Family::factory()->create();
        Child::factory()->create(['family_id' => $family->id]);

        return $family->user;
    }

    private function target(string $action, User $user): array
    {
        if ($action === 'publish') {
            $ad = Advertisement::factory()->create(['user_id' => $user->id]);

            return ["/api/advertisements/{$ad->id}/publish", []];
        }

        return ['/api/advertisements', [
            'type' => 'family_to_family', 'city_id' => $user->family->city_id,
            'child_ids' => $user->family->children()->pluck('id')->all(),
            'participant_age_from' => 5, 'participant_age_to' => 12,
            'description' => 'Group for families',
        ]];
    }

    private function postTarget(array $target): TestResponse
    {
        return $this->postJson($target[0], $target[1]);
    }

    private function assertThrottled(TestResponse $response, int $seconds): void
    {
        $response->assertStatus(429)->assertHeader('Retry-After', (string) $seconds)
            ->assertJsonPath('retry_after', $seconds)
            ->assertJsonPath('message', 'Слишком много попыток. Повторите запрос позже.');
    }

    private function businessState(): array
    {
        $state = [];
        foreach (['advertisements', 'advertisement_child', 'advertisement_subject', 'advertisement_responses', 'conversations', 'messages'] as $table) {
            $state[$table] = DB::table($table)->get()->toJson();
        }

        return $state;
    }
}
