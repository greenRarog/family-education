<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Conversation;
use App\Models\Report;
use App\Models\User;
use App\Services\AdvertisementModerationService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminReportModerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => 'admin']);
    }

    private function decision(array $overrides = []): array
    {
        return array_replace(['status' => 'resolved', 'resolution_note' => 'Internal finding with reporter quotation.', 'actions' => ['hide_advertisement', 'block_user'], 'sanction_reason_code' => 'spam', 'sanction_reason' => 'Repeated unsolicited advertising.'], $overrides);
    }

    public function test_access_and_verification_do_not_expose_report_to_reporter_or_target(): void
    {
        $report = Report::factory()->create(['comment' => 'Reporter private explanation']);
        $urls = ['/api/admin/reports', '/api/admin/reports/'.$report->id, '/api/admin/reports/'.$report->id.'/history'];
        foreach ($urls as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision())->assertUnauthorized();
        foreach ([$report->reporter, $report->target->user] as $user) {
            $this->actingAs($user);
            foreach ($urls as $url) {
                $this->getJson($url)->assertForbidden();
            }
            $this->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision())->assertForbidden();
        }
        $admin = $this->admin();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin);
        foreach ($urls as $url) {
            $this->getJson($url)->assertOk();
        }
        $this->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision())->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_filters_pagination_card_and_history_without_private_relationships(): void
    {
        Report::factory()->count(22)->create();
        $user = User::factory()->create();
        $report = Report::factory()->create(['target_type' => 'user', 'target_id' => $user->id, 'context_type' => 'teacher_profile', 'context_id' => $user->id, 'snapshot' => ['teacher' => ['name' => 'Original name']]]);
        $this->actingAs($this->admin());
        $this->getJson('/api/admin/reports')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 23);
        $this->getJson('/api/admin/reports?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/reports?target_type=user')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/reports/'.$report->id)->assertOk()->assertJsonPath('report.snapshot.teacher.name', 'Original name')
            ->assertJsonMissingPath('report.reporter.password')->assertJsonMissingPath('report.reporter.children')->assertJsonMissingPath('report.target.messages');
        $this->postJson('/api/admin/reports/'.$report->id.'/resolve', ['status' => 'dismissed', 'resolution_note' => 'Not confirmed'])->assertOk();
        $this->getJson('/api/admin/reports?status=dismissed')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/reports?status=resolved')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/reports?status=all')->assertOk()->assertJsonPath('total', 23);
        foreach (['status=bad', 'target_type=message', 'page=0'] as $query) {
            $this->getJson('/api/admin/reports?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/admin/reports/999999')->assertNotFound();
        $this->getJson('/api/admin/reports/'.$report->id.'/history?page=0')->assertUnprocessable();
        $this->getJson('/api/admin/reports/'.$report->id.'/history')->assertOk()->assertJsonPath('data.0.action', 'report_dismissed');
        $conversation = Conversation::factory()->create();
        $this->getJson('/api/conversations/'.$conversation->id)->assertForbidden();
    }

    public function test_resolution_and_two_sanctions_are_linked_and_keep_public_reasons_separate(): void
    {
        $report = Report::factory()->create(['comment' => 'Private reporter quotation']);
        $ad = $report->target;
        $this->actingAs($this->admin())->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision())
            ->assertOk()->assertJsonPath('report.status', 'resolved')->assertJsonCount(2, 'report.resolution_action_ids');
        $this->assertTrue($ad->fresh()->isHidden());
        $this->assertTrue($ad->user->fresh()->isBlocked());
        $this->assertDatabaseCount('admin_actions', 3);
        $this->assertDatabaseCount('report_resolution_actions', 2);
        $this->assertSame(3, AdminAction::query()->where('report_id', $report->id)->count());
        $this->getJson('/api/admin/reports/'.$report->id.'/history')->assertOk()->assertJsonCount(3, 'data');
        $this->actingAs($ad->user->fresh())->getJson('/api/user')->assertOk()->assertJsonPath('user.block_reason', 'Repeated unsolicited advertising.')->assertDontSee('Private reporter quotation')->assertDontSee('Internal finding');
        $this->getJson('/api/advertisements/'.$ad->id)->assertOk()->assertDontSee('Internal finding')->assertDontSee('Private reporter quotation');
    }

    public function test_existing_sanctions_are_referenced_without_duplicates_and_separate_sanction_keeps_report_open(): void
    {
        $report = Report::factory()->create();
        $admin = $this->admin();
        $action = app(AdvertisementModerationService::class)->change($admin, $report->target, true, ['reason_code' => 'spam', 'reason' => 'Previously reviewed content.']);
        $this->assertSame('open', $report->fresh()->status->value);
        $this->actingAs($admin)->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision(['actions' => [], 'existing_action_ids' => [$action['action_id']]]))->assertOk();
        $this->assertDatabaseCount('admin_actions', 2);
        $this->getJson('/api/admin/reports/'.$report->id.'/history')->assertOk()->assertJsonCount(2, 'data');
        $this->assertNull(AdminAction::query()->findOrFail($action['action_id'])->report_id);
        $this->postJson('/api/admin/reports/'.$report->id.'/resolve', ['status' => 'dismissed', 'resolution_note' => 'Overwrite attempt'])->assertConflict();
        $this->assertSame('Internal finding with reporter quotation.', $report->fresh()->resolution_note);
    }

    public function test_repeated_sanction_during_resolution_links_current_decision(): void
    {
        $report = Report::factory()->create();
        $admin = $this->admin();
        $first = app(UserBlockService::class)->block($admin, $report->target->user, ['reason_code' => 'fraud', 'reason' => 'Original public reason.']);
        $this->actingAs($admin)->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision(['actions' => ['block_user']]))->assertOk();
        $this->assertDatabaseCount('admin_actions', 2);
        $this->assertSame([$first['action_id']], $report->resolutionActions()->pluck('admin_actions.id')->all());
        $this->assertSame('Original public reason.', $report->target->user->fresh()->blockingAction->reason);
    }

    public function test_stale_and_unrelated_existing_decisions_are_rejected(): void
    {
        $report = Report::factory()->create();
        $admin = $this->admin();
        $hide = app(AdvertisementModerationService::class)->change($admin, $report->target, true, ['reason_code' => 'spam', 'reason' => 'Previous hidden content.']);
        app(AdvertisementModerationService::class)->change($admin, $report->target, false, ['reason' => 'Reviewed']);
        $this->actingAs($admin)->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision(['actions' => [], 'existing_action_ids' => [$hide['action_id']]]))->assertConflict();
        $other = app(UserBlockService::class)->block($admin, User::factory()->create(), ['reason_code' => 'spam', 'reason' => 'Another user restriction.']);
        $this->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision(['actions' => [], 'existing_action_ids' => [$other['action_id']]]))->assertConflict();
        $this->assertSame('open', $report->fresh()->status->value);
    }

    public function test_deleted_target_keeps_snapshot_and_can_be_dismissed(): void
    {
        $report = Report::factory()->create(['snapshot' => ['advertisement' => ['description' => 'Original public content']]]);
        $report->target->delete();
        $this->actingAs($this->admin())->getJson('/api/admin/reports/'.$report->id)->assertOk()->assertJsonPath('report.target_exists', false)->assertJsonPath('report.snapshot.advertisement.description', 'Original public content');
        $this->postJson('/api/admin/reports/'.$report->id.'/resolve', ['status' => 'dismissed', 'resolution_note' => 'Object removed, not confirmed'])->assertOk();
    }

    public static function invalidDecisions(): array
    {
        return [[['resolution_note' => '   ']], [['status' => 'open']], [['actions' => []]], [['actions' => ['restore']]], [['actions' => ['block_user', 'block_user']]], [['sanction_reason' => 'short']], [['sanction_reason_code' => 'invalid']], [['status' => 'dismissed']]];
    }

    #[DataProvider('invalidDecisions')]
    public function test_invalid_decisions_have_no_side_effects(array $override): void
    {
        $report = Report::factory()->create();
        $this->actingAs($this->admin())->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision($override))->assertUnprocessable();
        $this->assertSame('open', $report->fresh()->status->value);
        $this->assertFalse($report->target->fresh()->isHidden());
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_user_report_can_block_but_cannot_hide_an_unrelated_advertisement(): void
    {
        $user = User::factory()->create();
        $report = Report::factory()->create(['target_type' => 'user', 'target_id' => $user->id, 'context_type' => 'teacher_profile', 'context_id' => $user->id, 'snapshot' => ['teacher' => ['name' => 'Public']]]);
        $this->actingAs($this->admin())->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision())->assertUnprocessable();
        $this->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision(['actions' => ['block_user']]))->assertOk();
        $this->assertTrue($user->fresh()->isBlocked());
    }

    public function test_failed_second_sanction_rolls_back_first_and_report(): void
    {
        $admin = $this->admin();
        $ad = Advertisement::factory()->create(['user_id' => $admin->id]);
        $report = Report::factory()->create(['target_id' => $ad->id]);
        $this->actingAs($admin)->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision())->assertUnprocessable();
        $this->assertFalse($ad->fresh()->isHidden());
        $this->assertSame('open', $report->fresh()->status->value);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_journal_failure_rolls_back_report_both_sanctions_and_links(): void
    {
        $report = Report::factory()->create();
        AdminAction::creating(function (AdminAction $entry): void {
            if ($entry->target_type === 'report') {
                throw new RuntimeException('Simulated journal write failure');
            }
        });
        $this->actingAs($this->admin())->postJson('/api/admin/reports/'.$report->id.'/resolve', $this->decision())->assertStatus(500);
        $this->assertSame('open', $report->fresh()->status->value);
        $this->assertFalse($report->target->fresh()->isHidden());
        $this->assertFalse($report->target->user->fresh()->isBlocked());
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertDatabaseCount('report_resolution_actions', 0);
    }
}
