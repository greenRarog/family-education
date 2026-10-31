<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminActionType;
use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class UserBlockTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::ADMIN]);
    }

    private function reason(): array
    {
        return ['reason_code' => 'spam', 'reason' => 'Повторная навязчивая реклама.'];
    }

    public function test_block_unblock_repeats_and_new_sanction_preserve_original_decisions(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = $this->admin();
        $user = User::factory()->create(['user_type' => UserType::TEACHER]);
        $original = $user->getAttributes();
        $this->actingAs($admin);
        $blocked = $this->postJson("/api/admin/users/{$user->id}/block", $this->reason() + [
            'actor_id' => $user->id, 'block_action_id' => -1, 'is_blocked' => false,
        ])->assertOk()->assertJsonPath('changed', true)->assertJsonPath('user.is_blocked', true)
            ->assertJsonPath('user.blocked_by', $admin->id)->assertJsonPath('user.block_reason_code', 'spam');
        $blockId = $blocked->json('action_id');
        $this->assertSame($blockId, $blocked->json('user.block_action_id'));
        $this->assertSame($blockId, $user->fresh()->block_action_id);
        $entry = AdminAction::query()->findOrFail($blockId);
        $this->assertSame(AdminActionType::USER_BLOCKED, $entry->action);
        $this->assertSame(['blocked' => false], $entry->before_state);
        $this->assertSame(['blocked' => true], $entry->after_state);
        $this->assertSame($entry->occurred_at->toISOString(), $blocked->json('user.blocked_at'));
        $this->assertSame($user->id, $entry->target_id);
        $unchanged = $user->fresh()->getAttributes();

        $this->travel(1)->minutes();
        $this->postJson("/api/admin/users/{$user->id}/block", ['reason_code' => 'fraud', 'reason' => 'Another reason that must not replace the first.'])
            ->assertOk()->assertJsonPath('changed', false)->assertJsonPath('action_id', null)
            ->assertJsonPath('user.block_reason', $this->reason()['reason'])
            ->assertJsonPath('user.blocked_at', $blocked->json('user.blocked_at'));
        $this->assertSame($unchanged, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('admin_actions', 1);

        $unblocked = $this->postJson("/api/admin/users/{$user->id}/unblock", ['reason_code' => 'review', 'reason' => 'Решение пересмотрено.'])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('user.is_blocked', false)
            ->assertJsonPath('user.block_action_id', null)->assertJsonPath('user.blocked_at', null)
            ->assertJsonPath('user.block_reason', null)->assertJsonPath('user.blocked_by', null);
        $undo = AdminAction::query()->findOrFail($unblocked->json('action_id'));
        $this->assertSame(AdminActionType::USER_UNBLOCKED, $undo->action);
        $this->assertSame($blockId, $undo->related_action_id);
        $this->assertSame('review', $undo->reason_code);
        $this->postJson("/api/admin/users/{$user->id}/unblock", ['reason' => 'Повтор.'])
            ->assertOk()->assertJsonPath('changed', false)->assertJsonPath('action_id', null);
        $this->assertDatabaseCount('admin_actions', 2);

        $again = $this->postJson("/api/admin/users/{$user->id}/block", $this->reason())->assertOk()->assertJsonPath('changed', true);
        $this->assertNotSame($blockId, $again->json('action_id'));
        $this->assertDatabaseCount('admin_actions', 3);
        foreach (['name', 'email', 'email_verified_at', 'password', 'user_type', 'remember_token'] as $field) {
            $this->assertSame($original[$field], $user->fresh()->getAttributes()[$field]);
        }
        $this->assertSame($entry->getAttributes(), $entry->fresh()->getAttributes());
    }

    #[DataProvider('accessCases')]
    public function test_only_verified_admin_can_call_the_endpoints(string $operation, string $role, int $expected): void
    {
        $user = User::factory()->create();
        if ($role !== 'guest') {
            $actor = User::factory()->create([
                'user_type' => $role === 'unverified admin' ? UserType::ADMIN : UserType::from($role),
                'email_verified_at' => $role === 'unverified admin' ? null : now(),
            ]);
            $this->actingAs($actor);
        }
        $response = $this->postJson("/api/admin/users/{$user->id}/{$operation}", $operation === 'block' ? $this->reason() : ['reason' => 'Пересмотр.']);
        $response->assertStatus($expected);
        if ($role === 'unverified admin') {
            $response->assertJsonPath('code', 'email_verification_required');
        }
        $this->assertFalse($user->fresh()->isBlocked());
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public static function accessCases(): array
    {
        $cases = [];
        foreach (['block', 'unblock'] as $operation) {
            foreach (['guest' => 401, 'family' => 403, 'teacher' => 403, 'unverified admin' => 403] as $role => $status) {
                $cases[$operation.' '.$role] = [$operation, $role, $status];
            }
        }

        return $cases;
    }

    #[DataProvider('protectedTargets')]
    public function test_self_and_other_admin_are_protected_even_on_no_op(string $operation, bool $self): void
    {
        $admin = $this->admin();
        $target = $self ? $admin : $this->admin();
        $this->actingAs($admin)->postJson("/api/admin/users/{$target->id}/{$operation}",
            $operation === 'block' ? $this->reason() : ['reason' => 'Пересмотр.'])
            ->assertUnprocessable()->assertJsonValidationErrors('user');
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public static function protectedTargets(): array
    {
        return [['block', true], ['block', false], ['unblock', true], ['unblock', false]];
    }

    #[DataProvider('invalidReasons')]
    public function test_invalid_reasons_have_no_side_effects(string $operation, array $body, string $field): void
    {
        $user = User::factory()->create();
        $this->actingAs($this->admin())->postJson("/api/admin/users/{$user->id}/{$operation}", $body)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertFalse($user->fresh()->isBlocked());
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public static function invalidReasons(): array
    {
        return [
            ['block', ['reason' => 'Достаточное пояснение.'], 'reason_code'],
            ['block', ['reason_code' => 'off_topic', 'reason' => 'Достаточное пояснение.'], 'reason_code'],
            ['block', ['reason_code' => 'unknown', 'reason' => 'Достаточное пояснение.'], 'reason_code'],
            ['block', ['reason_code' => 'spam'], 'reason'],
            ['block', ['reason_code' => 'spam', 'reason' => '123456789'], 'reason'],
            ['block', ['reason_code' => 'spam', 'reason' => str_repeat('я', 1001)], 'reason'],
            ['block', ['reason_code' => 'spam', 'reason' => '          '], 'reason'],
            ['block', ['reason_code' => 'spam', 'reason' => ['invalid']], 'reason'],
            ['unblock', [], 'reason'],
            ['unblock', ['reason' => '  '], 'reason'],
            ['unblock', ['reason' => str_repeat('я', 1001)], 'reason'],
            ['unblock', ['reason_code' => 'fraud', 'reason' => 'Пересмотр.'], 'reason_code'],
        ];
    }

    public function test_verified_admin_gets_not_found_for_missing_target(): void
    {
        $this->actingAs($this->admin());
        foreach (['block', 'unblock'] as $operation) {
            $this->postJson("/api/admin/users/999999/{$operation}", $this->reason())->assertNotFound();
        }
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_unblock_closes_only_expired_published_ads_and_audits_each_transition(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = $this->admin();
        $user = User::factory()->create();
        $yearAgo = now()->subYear();
        $expired = Advertisement::factory()->create(['user_id' => $user->id, 'status' => AdvertisementStatus::PUBLISHED, 'published_at' => $yearAgo]);
        $preserveDate = Advertisement::factory()->create(['user_id' => $user->id, 'status' => AdvertisementStatus::PUBLISHED, 'published_at' => $yearAgo->copy()->subDay(), 'closed_at' => $yearAgo]);
        $untouched = [
            Advertisement::factory()->create(['user_id' => $user->id, 'status' => AdvertisementStatus::PUBLISHED, 'published_at' => $yearAgo->copy()->addSecond()]),
            Advertisement::factory()->create(['user_id' => $user->id, 'status' => AdvertisementStatus::DRAFT, 'published_at' => $yearAgo]),
            Advertisement::factory()->create(['user_id' => $user->id, 'status' => AdvertisementStatus::CLOSED, 'published_at' => $yearAgo]),
            Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED, 'published_at' => $yearAgo]),
        ];
        $snapshots = array_map(fn ($ad) => $ad->fresh()->getAttributes(), $untouched);
        $this->actingAs($admin)->postJson("/api/admin/users/{$user->id}/block", $this->reason())->assertOk();
        $this->assertSame(AdvertisementStatus::PUBLISHED, $expired->fresh()->status);
        $this->postJson("/api/admin/users/{$user->id}/unblock", ['reason' => 'Проверка завершена.'])
            ->assertOk()->assertJsonPath('expired_advertisements_count', 2);
        $this->assertSame(AdvertisementStatus::CLOSED, $expired->fresh()->status);
        $this->assertTrue($expired->fresh()->closed_at->equalTo(now()));
        $this->assertTrue($expired->fresh()->published_at->equalTo($yearAgo));
        $this->assertTrue($preserveDate->fresh()->closed_at->equalTo($yearAgo));
        foreach ($untouched as $index => $ad) {
            $this->assertSame($snapshots[$index], $ad->fresh()->getAttributes());
        }
        $actions = AdminAction::query()->where('action', AdminActionType::ADVERTISEMENT_EXPIRED)->orderBy('target_id')->get();
        $this->assertSame([$expired->id, $preserveDate->id], $actions->pluck('target_id')->all());
        $this->assertSame([$admin->id], $actions->pluck('actor_id')->unique()->values()->all());
        $this->assertDatabaseCount('admin_actions', 4);
        $this->postJson("/api/admin/users/{$user->id}/unblock", ['reason' => 'Повтор.'])->assertOk()->assertJsonPath('expired_advertisements_count', 0);
        $this->assertDatabaseCount('admin_actions', 4);
    }

    #[DataProvider('failedWrites')]
    public function test_storage_failure_rolls_back_state_expiry_and_journal(string $failure): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $ad = Advertisement::factory()->create(['user_id' => $user->id, 'status' => AdvertisementStatus::PUBLISHED, 'published_at' => now()->subYears(2)]);
        $this->actingAs($admin);
        if ($failure !== 'block audit') {
            $this->postJson("/api/admin/users/{$user->id}/block", $this->reason())->assertOk();
        }
        $before = $user->fresh()->block_action_id;
        $actionCount = AdminAction::query()->count();
        AdminAction::creating(static function (AdminAction $entry) use ($failure): void {
            if (($failure === 'expiry audit' && $entry->action === AdminActionType::ADVERTISEMENT_EXPIRED)
                || ($failure === 'unblock audit' && $entry->action === AdminActionType::USER_UNBLOCKED)
                || $failure === 'block audit') {
                $entry->actor_id = -1;
            }
        });
        try {
            $this->withoutExceptionHandling()->postJson("/api/admin/users/{$user->id}/".($failure === 'block audit' ? 'block' : 'unblock'),
                $failure === 'block audit' ? $this->reason() : ['reason' => 'Проверка завершена.']);
            $this->fail('Expected an audit insertion failure.');
        } catch (QueryException) {
            $this->assertSame($before, $user->fresh()->block_action_id);
            $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
            $this->assertNull($ad->fresh()->closed_at);
            $this->assertDatabaseCount('admin_actions', $actionCount);
        } finally {
            AdminAction::flushEventListeners();
            AdminAction::clearBootedModels();
        }
    }

    public static function failedWrites(): array
    {
        return [['block audit'], ['expiry audit'], ['unblock audit']];
    }

    public function test_user_update_failure_rolls_back_the_preceding_audit_insert(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        User::updating(static function (User $user): void {
            if ($user->isDirty('block_action_id')) {
                throw new RuntimeException('User write failed.');
            }
        });
        try {
            $this->withoutExceptionHandling()->actingAs($admin)->postJson("/api/admin/users/{$user->id}/block", $this->reason());
            $this->fail('Expected a user update failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('User write failed.', $exception->getMessage());
            $this->assertFalse($user->fresh()->isBlocked());
            $this->assertDatabaseCount('admin_actions', 0);
        } finally {
            User::flushEventListeners();
            User::clearBootedModels();
        }
    }

    public function test_service_rechecks_actor_on_a_no_op_without_trusting_a_forged_role(): void
    {
        $actor = User::factory()->create();
        $actor->user_type = UserType::ADMIN;
        $this->expectException(AuthorizationException::class);
        app(UserBlockService::class)->unblock($actor, User::factory()->create(), ['reason' => 'Повтор.']);
    }

    public function test_status_pointer_is_not_mass_assignable_or_exposed_by_user_serialization(): void
    {
        $user = User::factory()->create();
        $user->fill(['block_action_id' => -1])->save();
        $this->assertFalse($user->fresh()->isBlocked());
        app(UserBlockService::class)->block($this->admin(), $user, $this->reason());
        $json = $user->fresh()->load('blockingAction')->toArray();
        $this->assertArrayNotHasKey('block_action_id', $json);
        $this->assertArrayNotHasKey('blocking_action', $json);
        $this->assertArrayNotHasKey('blockingAction', $json);
    }

    public function test_migration_upgrade_and_rollback_preserve_existing_users_reports_and_audit(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();
        $report = Report::factory()->create(['reporter_id' => $user->id]);
        app(UserBlockService::class)->block($admin, $user, $this->reason());
        app(UserBlockService::class)->unblock($admin, $user, ['reason' => 'Пересмотр.']);
        $migration = require database_path('migrations/2026_10_30_140000_add_user_block_action.php');
        $migration->down();
        $before = DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $migration->up();
        $after = DB::table('users')->orderBy('id')->get()->map(function ($row) {
            $data = (array) $row;
            $this->assertNull($data['block_action_id']);
            unset($data['block_action_id']);

            return $data;
        })->all();
        $this->assertSame($before, $after);
        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $this->assertDatabaseCount('admin_actions', 2);
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $user->id)->update(['block_action_id' => -1]);
    }
}
