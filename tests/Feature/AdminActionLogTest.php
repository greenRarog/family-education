<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminActionType;
use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Exceptions\EmailVerificationRequired;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use App\Services\AdminActionLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminActionLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_records_actor_target_states_reason_time_and_report_relation(): void
    {
        $this->travelTo(now()->startOfSecond());
        $report = Report::factory()->create();
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $entry = app(AdminActionLogger::class)->record(
            $actor, $report->target, AdminActionType::ADVERTISEMENT_HIDDEN,
            'spam', 'Repeated advertising.', ['hidden' => false], ['hidden' => true], $report,
        );
        $saved = AdminAction::query()->with(['actor', 'target', 'report'])->findOrFail($entry->id);

        $this->assertTrue($saved->actor->is($actor));
        $this->assertTrue($saved->target->is($report->target));
        $this->assertTrue($saved->report->is($report));
        $this->assertSame(AdminActionType::ADVERTISEMENT_HIDDEN, $saved->action);
        $this->assertSame('spam', $saved->reason_code);
        $this->assertSame('Repeated advertising.', $saved->reason);
        $this->assertSame(['hidden' => false], $saved->before_state);
        $this->assertSame(['hidden' => true], $saved->after_state);
        $this->assertTrue($saved->occurred_at->equalTo(now()));
        $this->assertSame([$entry->id], $report->adminActions()->pluck('id')->all());
    }

    public function test_supports_user_report_and_related_appeal_targets(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $target = User::factory()->create();
        $logger = app(AdminActionLogger::class);
        $block = $logger->record($actor, $target, AdminActionType::USER_BLOCKED,
            'abuse', 'Threatening behaviour.', ['blocked' => false], ['blocked' => true]);
        $appeal = $logger->record($actor, $target, AdminActionType::APPEAL_REJECTED,
            'review', 'Decision remains in force.', ['blocked' => true], ['blocked' => true], relatedAction: $block);
        $report = Report::factory()->create();
        $decision = $logger->record($actor, $report, AdminActionType::REPORT_DISMISSED,
            'no_violation', 'No violation confirmed.', ['status' => 'open'], ['status' => 'dismissed']);

        $this->assertTrue($block->fresh()->target->is($target));
        $this->assertTrue($appeal->fresh()->relatedAction->is($block));
        $this->assertTrue($decision->fresh()->target->is($report));
        $this->assertSame($report->id, $decision->report_id);
    }

    #[DataProvider('unauthorizedActors')]
    public function test_ordinary_or_stale_actor_cannot_write_or_change_business_data(string $kind): void
    {
        $actor = User::factory()->create(['user_type' => $kind === 'stale admin' ? UserType::ADMIN : UserType::FAMILY]);
        if ($kind === 'forged admin') {
            $actor->user_type = UserType::ADMIN;
        } elseif ($kind === 'stale admin') {
            User::query()->whereKey($actor->id)->update(['user_type' => UserType::TEACHER]);
        }
        $ad = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED]);
        try {
            DB::transaction(function () use ($actor, $ad): void {
                $ad->update(['status' => AdvertisementStatus::CLOSED]);
                $this->recordExpiry($actor, $ad);
            });
            $this->fail('A non-admin actor was accepted.');
        } catch (AuthorizationException) {
            $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
            $this->assertDatabaseCount('admin_actions', 0);
        }
    }

    public static function unauthorizedActors(): array
    {
        return [['family'], ['forged admin'], ['stale admin']];
    }

    public function test_unverified_admin_cannot_write_even_with_forged_verification(): void
    {
        $actor = User::factory()->unverified()->create(['user_type' => UserType::ADMIN]);
        $actor->email_verified_at = now();
        $this->expectException(EmailVerificationRequired::class);
        $this->recordExpiry($actor, Advertisement::factory()->create());
    }

    public function test_database_failure_while_logging_rolls_back_the_business_change(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED]);
        // Force an INSERT failure after the service has validated the input.
        AdminAction::creating(static function (AdminAction $entry): void {
            $entry->actor_id = -1;
        });
        try {
            DB::transaction(function () use ($actor, $ad): void {
                $ad->update(['status' => AdvertisementStatus::CLOSED]);
                $this->recordExpiry($actor, $ad);
            });
            $this->fail('Expected an audit foreign-key failure.');
        } catch (QueryException) {
            $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
            $this->assertDatabaseCount('admin_actions', 0);
        } finally {
            AdminAction::flushEventListeners();
            AdminAction::clearBootedModels();
        }
    }

    public function test_failure_after_logging_rolls_back_both_log_and_business_change(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED]);
        try {
            DB::transaction(function () use ($actor, $ad): void {
                $ad->update(['status' => AdvertisementStatus::CLOSED]);
                $this->recordExpiry($actor, $ad);
                throw new RuntimeException('Later operation failed.');
            });
            $this->fail('Expected the later failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Later operation failed.', $exception->getMessage());
            $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
            $this->assertDatabaseCount('admin_actions', 0);
        }
    }

    public function test_no_op_repeats_do_not_append_or_overwrite_an_entry(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create();
        $original = $this->recordExpiry($actor, $ad)->fresh()->getAttributes();
        $entry = app(AdminActionLogger::class)->record($actor, $ad, AdminActionType::ADVERTISEMENT_HIDDEN,
            'spam', 'Repeated request.', ['hidden' => true, 'status' => 'closed'], ['status' => 'closed', 'hidden' => true]);

        $this->assertNull($entry);
        $this->assertDatabaseCount('admin_actions', 1);
        $this->assertSame($original, AdminAction::query()->firstOrFail()->getAttributes());
    }

    #[DataProvider('mutations')]
    public function test_existing_entries_cannot_be_modified_or_deleted(string $method): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $entry = $this->recordExpiry($actor, Advertisement::factory()->create())->fresh();
        $original = $entry->getAttributes();
        try {
            DB::transaction(function () use ($method, $entry): void {
                match ($method) {
                    'model update' => $entry->forceFill(['reason' => 'Rewritten'])->save(),
                    'model delete' => $entry->delete(),
                    'quiet update' => $entry->forceFill(['reason' => 'Rewritten'])->saveQuietly(),
                    'query update' => AdminAction::query()->whereKey($entry->id)->update(['reason' => 'Rewritten']),
                    'query delete' => AdminAction::query()->whereKey($entry->id)->delete(),
                    'raw update' => DB::table('admin_actions')->where('id', $entry->id)->update(['reason' => 'Rewritten']),
                    'raw delete' => DB::table('admin_actions')->where('id', $entry->id)->delete(),
                };
            });
            $this->fail('An immutable entry was changed.');
        } catch (LogicException|QueryException) {
            $this->assertSame($original, $entry->fresh()->getAttributes());
            $this->assertDatabaseCount('admin_actions', 1);
        }
    }

    public static function mutations(): array
    {
        return array_map(fn ($method) => [$method], [
            'model update', 'model delete', 'quiet update', 'query update', 'query delete', 'raw update', 'raw delete',
        ]);
    }

    public function test_entry_creation_is_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);
        AdminAction::query()->create(['actor_id' => 1, 'reason' => 'Untrusted request']);
    }

    public function test_target_deletion_preserves_the_entry_and_its_states(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create();
        $entry = $this->recordExpiry($actor, $ad)->fresh();
        $original = $entry->getAttributes();
        $ad->delete();
        $this->assertNull($entry->fresh()->target);
        $this->assertSame($original, $entry->fresh()->getAttributes());
    }

    #[DataProvider('badStates')]
    public function test_private_or_malformed_states_are_rejected(array $before, array $after): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->expectException(InvalidArgumentException::class);
        app(AdminActionLogger::class)->record($actor, Advertisement::factory()->create(),
            AdminActionType::ADVERTISEMENT_HIDDEN, 'spam', 'Reason.', $before, $after);
    }

    public static function badStates(): array
    {
        return [
            'private fields' => [['description' => 'Private text'], ['description' => 'Other text']],
            'nested data' => [['hidden' => ['password' => 'secret']], ['hidden' => true]],
            'string flag' => [['hidden' => 'false'], ['hidden' => true]],
            'unknown status' => [['status' => 'unknown'], ['status' => 'closed']],
            'mismatched keys' => [['hidden' => false], ['status' => 'closed']],
            'empty state' => [[], []],
        ];
    }

    public function test_action_type_reason_and_original_decision_are_validated(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create();
        $original = $this->recordExpiry($actor, Advertisement::factory()->create());
        $logger = app(AdminActionLogger::class);
        $invalid = [
            fn () => $logger->record($actor, $ad, AdminActionType::USER_BLOCKED, 'spam', 'Reason.', ['hidden' => false], ['hidden' => true]),
            fn () => $logger->record($actor, $ad, AdminActionType::ADVERTISEMENT_HIDDEN, '', 'Reason.', ['hidden' => false], ['hidden' => true]),
            fn () => $logger->record($actor, $ad, AdminActionType::ADVERTISEMENT_HIDDEN, 'spam', '  ', ['hidden' => false], ['hidden' => true]),
            fn () => $logger->record($actor, $ad, AdminActionType::APPEAL_REJECTED, 'review', 'Reason.', ['hidden' => true], ['hidden' => true]),
            fn () => $logger->record($actor, $ad, AdminActionType::APPEAL_REJECTED, 'review', 'Reason.', ['hidden' => true], ['hidden' => true], relatedAction: $original),
        ];
        foreach ($invalid as $call) {
            try {
                $call();
                $this->fail('Invalid audit input was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertDatabaseCount('admin_actions', 1);
            }
        }
    }

    public function test_references_from_another_connection_are_rejected(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create();
        config(['database.connections.audit_separate' => config('database.connections.'.config('database.default'))]);
        $actor->setConnection('audit_separate');
        $this->expectException(InvalidArgumentException::class);
        $this->recordExpiry($actor, $ad);
    }

    #[DataProvider('invalidStoredEntries')]
    public function test_database_rejects_invalid_entries_without_the_logger(array $overrides): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $entry = $this->recordExpiry($actor, Advertisement::factory()->create());
        $values = (array) DB::table('admin_actions')->where('id', $entry->id)->first();
        unset($values['id']);
        $values = array_replace($values, $overrides);
        try {
            DB::transaction(fn () => DB::table('admin_actions')->insert($values));
            $this->fail('Invalid audit data was accepted.');
        } catch (QueryException) {
            $this->assertDatabaseCount('admin_actions', 1);
        }
    }

    public static function invalidStoredEntries(): array
    {
        return [
            'missing actor' => [['actor_id' => -1]],
            'unknown target' => [['target_type' => 'message']],
            'nonpositive target' => [['target_id' => 0]],
            'unknown action' => [['action' => 'made_up']],
            'wrong action target' => [['action' => 'user_blocked']],
            'blank reason' => [['reason' => '   ']],
            'blank code' => [['reason_code' => '   ']],
            'long code' => [['reason_code' => str_repeat('a', 65)]],
            'null before state' => [['before_state' => null]],
            'array before state' => [['before_state' => '[]']],
            'scalar after state' => [['after_state' => '"secret"']],
            'malformed state' => [['after_state' => '{invalid']],
            'missing time' => [['occurred_at' => null]],
            'missing report' => [['report_id' => -1]],
            'missing decision' => [['related_action_id' => -1]],
            'appeal without decision' => [['action' => 'appeal_rejected']],
        ];
    }

    public function test_actor_and_linked_report_deletion_do_not_erase_history(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $report = Report::factory()->create();
        app(AdminActionLogger::class)->record($actor, $report, AdminActionType::REPORT_DISMISSED,
            'no_violation', 'No violation confirmed.', ['status' => 'open'], ['status' => 'dismissed']);
        foreach ([$actor, $report] as $referenced) {
            try {
                DB::transaction(fn () => $referenced->delete());
                $this->fail('An audit reference was deleted.');
            } catch (QueryException) {
                $this->assertDatabaseCount('admin_actions', 1);
                $this->assertNotNull($referenced->fresh());
            }
        }
    }

    public function test_rollback_and_reapply_restore_immutability_without_changing_reports(): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $report = Report::factory()->create();
        $this->recordExpiry($actor, $report->target);
        $resolutionMigration = require database_path('migrations/2026_10_31_120000_create_report_resolution_actions_table.php');
        $resolutionMigration->down();
        $hideMigration = require database_path('migrations/2026_10_30_150000_add_advertisement_hide_action.php');
        $hideMigration->down();
        $blockMigration = require database_path('migrations/2026_10_30_140000_add_user_block_action.php');
        $blockMigration->down();
        $migration = require database_path('migrations/2026_10_30_130000_create_admin_actions_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('admin_actions'));
        $this->assertDatabaseHas('reports', ['id' => $report->id]);
        $migration->up();
        $blockMigration->up();
        $hideMigration->up();
        $resolutionMigration->up();
        $entry = $this->recordExpiry($actor, $report->target);
        $this->expectException(QueryException::class);
        DB::table('admin_actions')->where('id', $entry->id)->delete();
    }

    public function test_postgresql_truncate_cannot_erase_history(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SQLite has no TRUNCATE; DELETE is tested separately.');
        }
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->recordExpiry($actor, Advertisement::factory()->create());
        try {
            DB::transaction(fn () => DB::statement('TRUNCATE TABLE admin_actions CASCADE'));
            $this->fail('Audit history was truncated.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Administrative actions are immutable', $exception->getMessage());
            $this->assertDatabaseCount('admin_actions', 1);
        }
    }

    private function recordExpiry(User $actor, Advertisement $ad): AdminAction
    {
        return app(AdminActionLogger::class)->record($actor, $ad, AdminActionType::ADVERTISEMENT_EXPIRED,
            'expired', 'The publication has expired.', ['status' => 'published'], ['status' => 'closed']);
    }
}
