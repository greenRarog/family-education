<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReportContextType;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_target_types_support_eager_loading_and_inverse_relations(): void
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $ad = Advertisement::factory()->create(['id' => $teacher->id]);
        $reporter = User::factory()->create();
        $adReport = Report::factory()->create(['reporter_id' => $reporter->id, 'target_id' => $ad->id]);
        $userReport = Report::factory()->create([
            'reporter_id' => $reporter->id, 'target_type' => 'user', 'target_id' => $teacher->id,
            'context_type' => ReportContextType::TEACHER_PROFILE,
            'snapshot' => ['name' => $teacher->name], 'reason' => ReportReason::FRAUD,
        ]);
        $reports = Report::query()->with(['reporter', 'target'])->orderBy('id')->get();

        $this->assertTrue($reports[0]->target->is($ad));
        $this->assertTrue($reports[1]->target->is($teacher));
        $this->assertTrue($reports[0]->reporter->is($reporter));
        $this->assertSame(ReportStatus::OPEN, $reports[0]->status);
        $this->assertSame(ReportReason::FRAUD, $reports[1]->reason);
        $this->assertSame(ReportContextType::TEACHER_PROFILE, $reports[1]->context_type);
        $this->assertSame([$adReport->id], $ad->reports()->pluck('id')->all());
        $this->assertSame([$userReport->id], $teacher->reportsReceived()->pluck('id')->all());
        $this->assertSame(2, $reporter->reportsSubmitted()->count());
    }

    public function test_an_open_duplicate_is_rejected_even_with_another_reason(): void
    {
        $report = Report::factory()->create();
        $row = DB::table('reports')->where('id', $report->id)->first();
        $duplicate = (array) $row;
        unset($duplicate['id']);
        $duplicate['reason'] = 'fraud';

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('reports')->insert($duplicate);
    }

    public function test_different_reporters_can_report_the_same_target(): void
    {
        $first = Report::factory()->create();
        Report::factory()->create(['target_id' => $first->target_id]);
        $this->assertDatabaseCount('reports', 2);
    }

    #[DataProvider('closedStatuses')]
    public function test_closing_releases_the_open_slot_without_overwriting_history(string $status): void
    {
        $report = Report::factory()->create();
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        DB::table('reports')->where('id', $report->id)->update([
            'status' => $status, 'resolved_by' => $admin->id,
            'resolution_note' => 'Decision on the original report.', 'resolved_at' => now(),
        ]);
        $next = Report::factory()->create([
            'reporter_id' => $report->reporter_id, 'target_id' => $report->target_id,
        ]);
        $closed = $report->fresh();
        $this->assertSame(ReportStatus::from($status), $closed->status);
        $this->assertTrue($closed->resolver->is($admin));
        $this->assertNotNull($closed->resolved_at);
        $this->assertSame($report->snapshot, $closed->snapshot);
        $this->assertNotSame($report->id, $next->id);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('reports')->where('id', $report->id)->update([
            'status' => 'open', 'resolved_by' => null, 'resolution_note' => null, 'resolved_at' => null,
        ]);
    }

    public static function closedStatuses(): array
    {
        return [['resolved'], ['dismissed']];
    }

    public function test_snapshot_and_context_survive_advertisement_and_conversation_deletion(): void
    {
        $conversation = Conversation::factory()->create();
        $response = AdvertisementResponse::query()->findOrFail($conversation->advertisement_response_id);
        $ad = $response->advertisement;
        $report = Report::factory()->create([
            'target_id' => $ad->id, 'context_type' => ReportContextType::CONVERSATION,
            'context_id' => $conversation->id,
            'snapshot' => ['description' => 'Original public text', 'context' => ['conversation_id' => $conversation->id]],
        ]);
        $ad->update(['description' => 'Changed public text']);
        $this->assertSame('Original public text', $report->fresh()->snapshot['description']);
        $ad->delete();

        $saved = $report->fresh();
        $this->assertNull($saved->target);
        $this->assertSame($ad->id, $saved->target_id);
        $this->assertSame($conversation->id, $saved->context_id);
        $this->assertSame($report->snapshot, $saved->snapshot);
        $this->assertDatabaseMissing('conversations', ['id' => $conversation->id]);
    }

    public function test_user_target_deletion_does_not_delete_the_report(): void
    {
        $target = User::factory()->create();
        $report = Report::factory()->create([
            'target_type' => 'user', 'target_id' => $target->id,
            'context_type' => ReportContextType::TEACHER_PROFILE,
            'snapshot' => ['name' => $target->name],
        ]);
        $target->delete();

        $saved = $report->fresh();
        $this->assertNull($saved->target);
        $this->assertSame($target->id, $saved->target_id);
        $this->assertSame($report->snapshot, $saved->snapshot);
    }

    public function test_reporter_cannot_be_cascade_deleted_before_account_lifecycle_policy(): void
    {
        $report = Report::factory()->create();
        $this->expectException(QueryException::class);
        $report->reporter->delete();
    }

    public function test_other_accepts_unicode_comment_at_the_maximum_length(): void
    {
        $comment = str_repeat('я', 2000);
        $report = Report::factory()->create(['reason' => ReportReason::OTHER, 'comment' => $comment]);
        $this->assertSame($comment, $report->fresh()->comment);
    }

    #[DataProvider('invalidRows')]
    public function test_database_rejects_invalid_data_without_eloquent(array $overrides, bool $update): void
    {
        $report = Report::factory()->create()->refresh();
        $values = (array) DB::table('reports')->where('id', $report->id)->first();
        unset($values['id']);
        // Use a distinct reporter for INSERT so a CHECK failure cannot be
        // confused with the open-report unique index.
        $values['reporter_id'] = User::factory()->create()->id;
        $values = array_replace($values, $overrides);

        try {
            DB::transaction(function () use ($report, $values, $update): void {
                if ($update) {
                    DB::table('reports')->where('id', $report->id)->update($values);
                } else {
                    DB::table('reports')->insert($values);
                }
            });
            $this->fail('Invalid report data was accepted.');
        } catch (QueryException $exception) {
            $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $exception);
        }
        $this->assertDatabaseCount('reports', 1);
        $this->assertSame($report->getAttributes(), $report->fresh()->getAttributes());
    }

    public static function invalidRows(): array
    {
        $cases = [
            'unknown target' => ['target_type' => 'message'],
            'nonpositive target' => ['target_id' => 0],
            'unknown context' => ['context_type' => 'private_profile'],
            'nonpositive context' => ['context_id' => 0],
            'wrong context kind' => ['context_type' => 'teacher_profile'],
            'wrong context identity' => ['context_id' => 999999],
            'unknown reason' => ['reason' => 'unlisted'],
            'other without comment' => ['reason' => 'other', 'comment' => null],
            'other short comment' => ['reason' => 'other', 'comment' => '123456789'],
            'other blank comment' => ['reason' => 'other', 'comment' => '          '],
            'long comment' => ['comment' => str_repeat('я', 2001)],
            'unknown status' => ['status' => 'pending'],
            'closed without decision' => ['status' => 'dismissed'],
            'open with decision' => ['resolution_note' => 'Not an open report'],
            'missing reporter' => ['reporter_id' => 999999],
            'null snapshot' => ['snapshot' => null],
            'array snapshot' => ['snapshot' => '[]'],
            'scalar snapshot' => ['snapshot' => '"not an object"'],
            'malformed snapshot' => ['snapshot' => '{broken'],
            'null timestamp' => ['created_at' => null],
        ];
        $rows = [];
        foreach ($cases as $name => $values) {
            $rows[$name.' insert'] = [$values, false];
            $rows[$name.' update'] = [$values, true];
        }

        return $rows;
    }

    public function test_user_report_rejects_off_topic_reason_and_self_target(): void
    {
        $reporter = User::factory()->create();
        $target = User::factory()->create();
        foreach ([['reason' => 'off_topic'], ['target_id' => $reporter->id, 'context_id' => $reporter->id]] as $invalid) {
            try {
                DB::transaction(fn () => Report::factory()->create(array_replace([
                    'reporter_id' => $reporter->id, 'target_type' => 'user', 'target_id' => $target->id,
                    'context_type' => 'teacher_profile', 'context_id' => $target->id,
                    'snapshot' => ['name' => $target->name],
                ], $invalid)));
                $this->fail('Invalid user report was accepted.');
            } catch (QueryException $exception) {
                $this->assertNotInstanceOf(UniqueConstraintViolationException::class, $exception);
            }
        }
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_migration_rollback_and_reapply_leave_existing_business_data_intact(): void
    {
        $report = Report::factory()->create();
        $adId = $report->target_id;
        $reporterId = $report->reporter_id;
        // C03 references reports; roll back dependants in migration order.
        $resolutionMigration = require database_path('migrations/2026_10_31_120000_create_report_resolution_actions_table.php');
        $resolutionMigration->down();
        $hideMigration = require database_path('migrations/2026_10_30_150000_add_advertisement_hide_action.php');
        $hideMigration->down();
        $blockMigration = require database_path('migrations/2026_10_30_140000_add_user_block_action.php');
        $blockMigration->down();
        $auditMigration = require database_path('migrations/2026_10_30_130000_create_admin_actions_table.php');
        $auditMigration->down();
        $migration = require database_path('migrations/2026_10_30_120000_create_reports_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('reports'));
        $this->assertDatabaseHas('advertisements', ['id' => $adId]);
        $this->assertDatabaseHas('users', ['id' => $reporterId]);
        $migration->up();
        $auditMigration->up();
        $blockMigration->up();
        $hideMigration->up();
        $resolutionMigration->up();
        Report::factory()->create(['reporter_id' => $reporterId, 'target_id' => $adId]);
        $this->assertDatabaseCount('reports', 1);
        $this->expectException(UniqueConstraintViolationException::class);
        Report::factory()->create(['reporter_id' => $reporterId, 'target_id' => $adId]);
    }
}
