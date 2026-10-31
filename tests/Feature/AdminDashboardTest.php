<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use App\Services\AdvertisementModerationService;
use App\Services\ReportResolutionService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_read_counts_including_without_verified_email(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        foreach (['family', 'teacher'] as $role) {
            $this->actingAs(User::factory()->create(['user_type' => $role]))
                ->getJson('/api/admin/dashboard')->assertForbidden();
        }
        $admin = User::factory()->create(['user_type' => 'admin', 'email_verified_at' => null]);
        $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'blocked_users' => 0, 'hidden_advertisements' => 0, 'open_reports' => 0,
        ]);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_counts_current_restrictions_independently_of_owner_and_advertisement_status(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $owner = User::factory()->create();
        $other = User::factory()->create(['user_type' => 'teacher']);
        $blocks = app(UserBlockService::class);
        $ads = app(AdvertisementModerationService::class);
        $reason = ['reason_code' => 'spam', 'reason' => 'Repeated unwanted advertising.'];
        $blocks->block($admin, $owner, $reason);
        $blocks->block($admin, $owner, $reason);
        $blocks->block($admin, $other, $reason);
        $blocks->unblock($admin, $other, ['reason' => 'Restriction removed after review.']);
        Advertisement::factory()->count(4)->create(['user_id' => $owner->id, 'status' => 'published']);
        foreach (['draft', 'published', 'closed'] as $status) {
            $ad = Advertisement::factory()->create(['user_id' => $other->id, 'status' => $status]);
            $ads->change($admin, $ad, true, $reason);
        }
        $hidden = Advertisement::factory()->create(['user_id' => $owner->id]);
        $ads->change($admin, $hidden, true, $reason);
        $ads->change($admin, $hidden, true, $reason);
        $restored = Advertisement::factory()->create();
        $ads->change($admin, $restored, true, $reason);
        $ads->change($admin, $restored, false, ['reason' => 'Content corrected after review.']);
        $journalCount = AdminAction::query()->count();

        $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'blocked_users' => 1, 'hidden_advertisements' => 4, 'open_reports' => 0,
        ]);
        $this->assertDatabaseCount('admin_actions', $journalCount);
        $blocks->unblock($admin, $owner, ['reason' => 'Restriction removed after review.']);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'blocked_users' => 0, 'hidden_advertisements' => 4, 'open_reports' => 0,
        ]);
        $ads->change($admin, $hidden, false, ['reason' => 'Content corrected after review.']);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('hidden_advertisements', 3);
    }

    public function test_counts_open_reports_of_both_types_and_refreshes_after_decisions(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $report = Report::factory()->create();
        Report::factory()->create(['target_type' => 'user', 'target_id' => $report->target->user_id,
            'context_type' => 'teacher_profile', 'context_id' => $report->target->user_id, 'snapshot' => ['name' => 'Public name']]);
        $dismissed = Report::factory()->create();
        $resolved = Report::factory()->create();
        $service = app(ReportResolutionService::class);
        $service->resolve($admin, $dismissed, ['status' => 'dismissed', 'resolution_note' => 'Not confirmed.']);
        $service->resolve($admin, $resolved, ['status' => 'resolved', 'resolution_note' => 'Confirmed.',
            'actions' => ['hide_advertisement'], 'sanction_reason_code' => 'spam', 'sanction_reason' => 'Repeated unwanted advertising.']);
        $this->actingAs($admin)->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'blocked_users' => 0, 'hidden_advertisements' => 1, 'open_reports' => 2,
        ]);
        $service->resolve($admin, $report, ['status' => 'dismissed', 'resolution_note' => 'Not confirmed.']);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('open_reports', 1);
        Report::factory()->create(['target_id' => $report->target_id, 'reporter_id' => $report->reporter_id]);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('open_reports', 2);
    }
}
