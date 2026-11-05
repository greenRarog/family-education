<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\City;
use App\Models\Conversation;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use App\Services\AdvertisementClosureService;
use App\Services\AdvertisementModerationService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schedule;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertisementExpiryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertSuccessful();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public static function dates(): array
    {
        return [
            ['2024-02-29 12:34:56', '2025-02-28 12:34:56'],
            ['2023-02-28 12:34:56', '2024-02-28 12:34:56'],
            ['2025-03-01 00:00:00', '2026-03-01 00:00:00'],
            ['2025-11-02 12:00:00', '2026-11-02 12:00:00'],
        ];
    }

    #[DataProvider('dates')]
    public function test_sql_and_model_share_exact_calendar_year_boundary(string $published, string $expires): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'published_at' => $published]);
        $this->assertSame($expires, $ad->publicationExpiresAt()->toDateTimeString());
        foreach ([-1, 0, 1] as $seconds) {
            $at = Carbon::parse($expires, 'UTC')->addSeconds($seconds);
            $this->assertSame($seconds >= 0, $ad->publicationExpired($at));
            $this->assertSame($seconds >= 0, Advertisement::query()->whereKey($ad->id)->expired($at)->exists());
            $this->assertSame($seconds < 0, Advertisement::query()->whereKey($ad->id)->unexpired($at)->exists());
        }
    }

    public function test_public_feed_show_and_new_responses_stop_at_deadline_before_scheduler_but_history_remains(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 12:00:00', 'UTC'));
        $ad = Advertisement::factory()->create(['status' => 'published', 'published_at' => now()->subYear()]);
        $live = Advertisement::factory()->create(['status' => 'published', 'published_at' => now()->subYear()->addSecond()]);
        $participant = User::factory()->create();
        $response = AdvertisementResponse::factory()->create(['advertisement_id' => $ad->id, 'user_id' => $participant->id, 'status' => 'accepted']);
        $chat = Conversation::factory()->create(['advertisement_response_id' => $response->id]);
        $this->getJson('/api/advertisements/feed')->assertOk()->assertJsonCount(1, 'advertisements.data')->assertJsonPath('advertisements.data.0.id', $live->id);
        $this->getJson('/api/advertisements/'.$ad->id)->assertNotFound();
        $this->actingAs($ad->user)->getJson('/api/advertisements/'.$ad->id)->assertOk()->assertJsonPath('viewer.can_respond', false);
        $this->actingAs($participant)->getJson('/api/advertisements/'.$ad->id)->assertOk()->assertJsonPath('viewer.can_respond', false);
        $this->getJson('/api/conversations/'.$chat->id)->assertOk();
        $this->actingAs(User::factory()->create())->postJson('/api/advertisements/'.$ad->id.'/responses', ['message' => 'Too late'])->assertUnprocessable();
        $report = ['target_type' => 'advertisement', 'context_type' => 'advertisement', 'context_id' => $ad->id, 'reason' => 'spam'];
        $this->postJson('/api/reports', $report)->assertNotFound();
        $this->actingAs($participant)->postJson('/api/reports', $report)->assertCreated();
        $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
        $this->artisan('advertisements:close-expired')->expectsOutput('Closed 1 expired advertisement(s).')->assertSuccessful();
        $this->assertTrue($ad->fresh()->closed_at->eq(now()));
        Notification::assertSentToTimes($ad->user, AdvertisementExpiredNotification::class, 1);
        $this->artisan('advertisements:close-expired')->expectsOutput('Closed 0 expired advertisement(s).')->assertSuccessful();
        Notification::assertSentToTimes($ad->user, AdvertisementExpiredNotification::class, 1);
    }

    public function test_public_teacher_list_omits_expired_ads(): void
    {
        $teacher = User::factory()->create(['user_type' => 'teacher']);
        TeacherProfile::query()->create(['name' => 'Fixture', 'surname' => 'Teacher', 'city_id' => City::factory()->create()->id, 'user_id' => $teacher->id, 'public_profile_requested' => true, 'public_profile_status' => 'approved']);
        Advertisement::factory()->create(['user_id' => $teacher->id, 'type' => 'teacher_to_service', 'status' => 'published', 'published_at' => now()->subYears(2)]);
        $this->getJson('/api/teachers/'.$teacher->id)->assertOk()->assertJsonCount(0, 'advertisements');
    }

    public function test_manual_close_only_notifies_for_expiry_and_preserves_existing_closed_at(): void
    {
        $this->travelTo(now()->startOfSecond());
        $owner = User::factory()->create();
        $early = Advertisement::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'published_at' => now()]);
        $expired = Advertisement::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'published_at' => now()->subYears(2), 'closed_at' => now()->subDay()]);
        $this->actingAs($owner)->postJson('/api/advertisements/'.$early->id.'/close')->assertOk();
        Notification::assertNothingSent();
        $this->postJson('/api/advertisements/'.$expired->id.'/close')->assertOk();
        $this->assertTrue($expired->fresh()->closed_at->eq(now()->subDay()));
        $this->postJson('/api/advertisements/'.$expired->id.'/close')->assertUnprocessable();
        $this->artisan('advertisements:close-expired')->assertSuccessful();
        Notification::assertSentToTimes($owner, AdvertisementExpiredNotification::class, 1);
    }

    public function test_expiry_notification_waits_for_commit_and_is_discarded_on_rollback(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'published_at' => now()->subYears(2)]);
        DB::beginTransaction();
        $this->assertTrue(app(AdvertisementClosureService::class)->close($ad));
        Notification::assertNothingSent();
        DB::rollBack();
        $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
        Notification::assertNothingSent();
        $this->assertTrue(app(AdvertisementClosureService::class)->close($ad));
        Notification::assertSentToTimes($ad->user, AdvertisementExpiredNotification::class, 1);
    }

    public function test_restore_and_unblock_use_same_transition_and_do_not_duplicate_notifications(): void
    {
        $admin = User::factory()->create(['user_type' => 'admin']);
        $owner = User::factory()->create();
        $ad = Advertisement::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'published_at' => now()->subYears(2)]);
        app(AdvertisementModerationService::class)->change($admin, $ad, true, ['reason_code' => 'spam', 'reason' => 'Fixture hidden content.']);
        app(AdvertisementModerationService::class)->change($admin, $ad, false, ['reason' => 'Reviewed.']);
        Notification::assertSentToTimes($owner, AdvertisementExpiredNotification::class, 1);
        $second = Advertisement::factory()->create(['user_id' => $owner->id, 'status' => 'published', 'published_at' => now()->subYears(2)]);
        app(UserBlockService::class)->block($admin, $owner, ['reason_code' => 'spam', 'reason' => 'Fixture user block.']);
        app(UserBlockService::class)->unblock($admin, $owner, ['reason' => 'Reviewed.']);
        $this->assertSame(AdvertisementStatus::CLOSED, $second->fresh()->status);
        Notification::assertSentToTimes($owner, AdvertisementExpiredNotification::class, 2);
        $this->artisan('advertisements:close-expired')->assertSuccessful();
        Notification::assertSentToTimes($owner, AdvertisementExpiredNotification::class, 2);
    }

    public function test_scheduler_runs_hourly_with_overlap_protection(): void
    {
        $events = collect(Schedule::events())->filter(fn ($event) => str_contains($event->command ?? '', 'advertisements:close-expired'));
        $this->assertCount(1, $events);
        $this->assertSame('0 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }
}
