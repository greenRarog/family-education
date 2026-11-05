<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Report;
use App\Models\Subject;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Services\AccountDeletionService;
use App\Services\AccountQueueCleaner;
use App\Services\AccountRetentionService;
use App\Services\ReportResolutionService;
use App\Services\UserBlockService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    public static function participants(): array
    {
        return [['owner', 'sent'], ['owner', 'accepted'], ['owner', 'rejected'],
            ['respondent', 'sent'], ['respondent', 'accepted'], ['respondent', 'rejected']];
    }

    #[DataProvider('participants')]
    public function test_deletion_preserves_both_authors_and_other_participants_history(string $side, string $status): void
    {
        $this->travelTo(now()->setDate(2024, 2, 29)->startOfDay());
        $chat = Conversation::factory()->create();
        $response = $chat->advertisementResponse;
        $response->update(['status' => $status]);
        $ad = $response->advertisement;
        $ad->update(['status' => 'published', 'published_at' => now(), 'language' => 'es']);
        $owner = $ad->user;
        $respondent = $response->user;
        $deleted = $side === 'owner' ? $owner : $respondent;
        $survivor = $side === 'owner' ? $respondent : $owner;
        $other = Conversation::factory()->create();
        $other->advertisementResponse->update(['user_id' => $survivor->id]);
        $m1 = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $owner->id, 'body' => 'Owner contact and quotation.']);
        $m2 = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $respondent->id, 'body' => 'Respondent text.', 'read_at' => now()]);
        $old = $deleted->getAttributes();
        $deletion = app(AccountDeletionService::class)->delete($deleted);

        $this->assertSame('2025-02-28 00:00:00', $chat->fresh()->expires_at->toDateTimeString());
        $this->assertSame($status, $response->fresh()->status->value);
        $this->assertSame($m1->body, $m1->fresh()->body);
        $this->assertSame($m2->body, $m2->fresh()->body);
        $this->assertNotNull($m2->fresh()->read_at);
        $this->assertNull($other->fresh()->expires_at);
        $this->assertSame('Удалённый аккаунт', $deleted->fresh()->name);
        $this->assertNotSame($old['email'], $deleted->fresh()->email);
        $this->assertFalse(Hash::check('Password123!', $deleted->fresh()->password));
        $this->assertNull($deletion->registry_confirmed_at);
        $this->assertNull($deleted->fresh()->remember_token);
        $this->assertFalse($deleted->fresh()->emailNotificationsEnabled());
        $this->assertFalse($deleted->fresh()->telegramNotificationsEnabled());
        $this->assertNull(Auth::createUserProvider('users')->retrieveById($deleted->id));

        $data = $this->actingAs($survivor)->getJson('/api/conversations/'.$chat->id)->assertOk()
            ->assertJsonPath('conversation.account_deleted', true)
            ->assertJsonPath('conversation.viewer.can_accept', false)
            ->assertJsonPath('conversation.viewer.can_reject', false)
            ->assertJsonPath('conversation.unread_count', 0)->json('conversation');
        $this->assertCount(2, $data['messages']);
        if ($side === 'owner') {
            $this->assertTrue($data['advertisement']['unavailable']);
            $this->assertArrayNotHasKey('description', $data['advertisement']);
            $this->getJson('/api/advertisements/'.$ad->id)->assertNotFound();
            $this->assertNull($ad->fresh()->city_id);
            $this->assertSame('', $ad->fresh()->description);
        } else {
            $this->assertSame($ad->description, $ad->fresh()->description);
            $this->assertSame('published', $ad->fresh()->status->value);
        }
        $this->postJson('/api/conversations/'.$chat->id.'/messages', ['message' => 'Must not be sent'])->assertForbidden();
        $this->postJson('/api/conversations/'.$chat->id.'/read')->assertForbidden();
        $this->assertNull($m1->fresh()->read_at);
        $this->getJson('/api/conversations/unread-count')->assertJsonPath('count', 0);

        $before = $deleted->fresh()->getAttributes();
        $this->travel(5)->days();
        $again = app(AccountDeletionService::class)->delete($deleted);
        $this->assertSame($deletion->deleted_at->toDateTimeString(), $again->deleted_at->toDateTimeString());
        $this->assertSame($before, $deleted->fresh()->getAttributes());
        app(AccountDeletionService::class)->delete($survivor);
        $this->assertSame('2025-02-28 00:00:00', $chat->fresh()->expires_at->toDateTimeString());
    }

    public function test_profiles_children_settings_and_credentials_are_removed_atomically(): void
    {
        $user = User::factory()->create(['locale' => 'es', 'telegram_chat_id' => 'fixture-chat']);
        $ad = Advertisement::factory()->create(['user_id' => $user->id]);
        $family = $user->family()->create(['surname' => 'Private', 'city_id' => $ad->city_id]);
        $child = $family->children()->create(['name' => 'Child', 'birth_date' => '2015-01-01', 'sex' => 'male']);
        $ad->children()->attach($child);
        $subject = Subject::factory()->create();
        $ad->subjects()->attach($subject);
        $user->notificationSetting()->create(['email_enabled' => true, 'telegram_enabled' => true]);
        DB::table('sessions')->insert(['id' => 'fixture-session', 'user_id' => $user->id, 'payload' => 'fixture', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $user->email, 'token' => 'fixture', 'created_at' => now()]);
        $email = $user->email;
        app(AccountDeletionService::class)->delete($user);
        foreach (['families', 'children', 'advertisement_child', 'advertisement_subject', 'notification_settings', 'sessions', 'password_reset_tokens'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $new = User::factory()->create(['email' => $email]);
        $this->assertNotSame($user->id, $new->id);
        $this->assertFalse($new->isBlocked());
        $this->assertSame(0, $new->advertisementResponses()->count());
    }

    public function test_teacher_profile_is_deleted_without_deleting_shared_subjects(): void
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $ad = Advertisement::factory()->create(['user_id' => $teacher->id, 'type' => 'teacher_to_service']);
        $profile = $teacher->teacherProfile()->create(['surname' => 'Private', 'city_id' => $ad->city_id, 'education' => 'Diploma text', 'about' => 'Biography']);
        $subject = Subject::factory()->create();
        $profile->subjects()->attach($subject);
        app(AccountDeletionService::class)->delete($teacher);
        $this->assertDatabaseCount('teacher_profiles', 0);
        $this->assertDatabaseCount('teacher_profile_subject', 0);
        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
        $this->getJson('/api/teachers/'.$teacher->id)->assertNotFound();
    }

    public function test_failure_rolls_back_profiles_advertisements_sessions_and_outbox(): void
    {
        $user = User::factory()->create();
        $ad = Advertisement::factory()->create(['user_id' => $user->id]);
        $family = $user->family()->create(['surname' => 'Private', 'city_id' => $ad->city_id]);
        $before = $user->fresh()->getAttributes();
        $this->instance(AccountQueueCleaner::class, Mockery::mock(AccountQueueCleaner::class)->shouldReceive('clean')
            ->once()->andThrow(new RuntimeException('Fixture failure'))->getMock());
        try {
            app(AccountDeletionService::class)->delete($user);
            $this->fail('Expected cleanup failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Fixture failure', $exception->getMessage());
        }
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertNotNull($family->fresh());
        $this->assertSame($ad->description, $ad->fresh()->description);
        $this->assertNull($ad->fresh()->account_deleted_at);
        $this->assertDatabaseCount('account_deletions', 0);
    }

    public function test_admin_cannot_be_deleted_by_the_service(): void
    {
        $this->expectException(AuthorizationException::class);
        app(AccountDeletionService::class)->delete(User::factory()->create(['user_type' => UserType::ADMIN]));
    }

    public function test_expiration_is_enforced_before_physical_cleanup_and_requires_recovery_confirmation(): void
    {
        $this->travelTo(now()->setDate(2024, 2, 29)->startOfDay());
        $chat = Conversation::factory()->create();
        $owner = $chat->advertisementResponse->advertisement->user;
        $survivor = $chat->advertisementResponse->user;
        $message = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $owner->id]);
        $deletion = app(AccountDeletionService::class)->delete($owner);
        $this->travelTo($chat->fresh()->expires_at->copy()->subSecond());
        $this->assertSame(0, app(AccountRetentionService::class)->purge()['conversations']);
        $this->actingAs($survivor)->getJson('/api/conversations/'.$chat->id)->assertOk();
        $this->travel(1)->seconds();
        $this->getJson('/api/conversations/'.$chat->id)->assertNotFound();
        $this->getJson('/api/conversations')->assertJsonPath('conversations.meta.total', 0);
        $this->assertSame(1, app(AccountRetentionService::class)->purge()['conversations']);
        $this->assertNull($message->fresh());
        $this->assertNotNull($owner->fresh());
        $deletion->forceFill(['registry_confirmed_at' => now(), 'backups_cleared_at' => now()])->save();
        $this->assertSame(1, app(AccountRetentionService::class)->purge()['users']);
        $this->assertNull($owner->fresh());
        $this->assertSame(['conversations' => 0, 'moderation_records' => 0, 'advertisements' => 0, 'users' => 0], app(AccountRetentionService::class)->purge());
    }

    public function test_open_report_and_immutable_history_survive_deletion_and_can_expire_after_closure(): void
    {
        $this->travelTo(now()->setDate(2024, 1, 1)->startOfDay());
        $report = Report::factory()->create();
        $owner = $report->target->user;
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        app(UserBlockService::class)->block($admin, $owner, ['reason_code' => 'spam', 'reason' => 'Fixture sanction.'], $report);
        $original = DB::table('admin_actions')->first();
        $snapshot = $report->snapshot;
        app(AccountDeletionService::class)->delete($owner);
        $this->assertSame($snapshot, $report->fresh()->snapshot);
        $this->assertEquals($original, DB::table('admin_actions')->first());
        $this->travel(13)->months();
        $this->assertSame(0, app(AccountRetentionService::class)->purge()['moderation_records']);
        $closed = app(ReportResolutionService::class)->closeDeletedTarget($admin, $report, 'Аккаунт удалён; рассмотрение завершено.');
        $this->assertSame('account_deleted', $closed->resolution_outcome);
        $this->assertSame('resolved', $closed->status->value);
        $this->assertSame($snapshot, $closed->snapshot);
        $this->travel(12)->months();
        $this->assertSame(3, app(AccountRetentionService::class)->purge()['moderation_records']);
        $this->assertNull($report->fresh());
        $this->assertDatabaseCount('moderation_purge_actions', 0);
        // The permission to purge one case must not weaken unrelated history.
        $other = User::factory()->create();
        app(UserBlockService::class)->block($admin, $other, ['reason_code' => 'spam', 'reason' => 'Unrelated active sanction.']);
        $this->expectException(QueryException::class);
        DB::table('admin_actions')->delete();
    }

    public function test_deleting_reporter_does_not_remove_report_or_active_measure_on_someone_else(): void
    {
        $report = Report::factory()->create();
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        app(ReportResolutionService::class)->resolve($admin, $report, [
            'status' => 'resolved', 'resolution_note' => 'Violation confirmed.', 'actions' => ['block_user'],
            'sanction_reason_code' => 'spam', 'sanction_reason' => 'Fixture sanction.',
        ]);
        $deletion = app(AccountDeletionService::class)->delete($report->reporter);
        $this->travel(2)->years();
        $deletion->forceFill(['registry_confirmed_at' => now(), 'backups_cleared_at' => now()])->save();
        $this->assertSame(0, app(AccountRetentionService::class)->purge()['moderation_records']);
        $this->assertNotNull($report->fresh());
        $this->assertNotNull($report->reporter->fresh());
        $this->assertTrue($report->target->user->fresh()->isBlocked());
    }

    public function test_database_queue_and_failed_payloads_are_removed_without_touching_unrelated_jobs(): void
    {
        $chat = Conversation::factory()->create();
        $response = $chat->advertisementResponse;
        $message = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $response->user_id]);
        $job = new SendQueuedNotifications($response->advertisement->user, new NewMessageNotification($message), ['mail']);
        $job->beforeCommit();
        Queue::connection('database')->push($job);
        $payload = DB::table('jobs')->value('payload');
        DB::table('failed_jobs')->insert(['uuid' => 'fixture-failed', 'connection' => 'database', 'queue' => 'default', 'payload' => $payload, 'exception' => 'Fixture error']);
        DB::table('jobs')->insert(['queue' => 'unrelated', 'payload' => json_encode(['data' => ['command' => serialize((object) ['value' => 'unrelated'])]]), 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        app(AccountDeletionService::class)->delete($response->user);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'unrelated']);
        $this->assertDatabaseCount('failed_jobs', 0);
        Event::fake([MessageSent::class]);
        // A worker that already fetched the payload still checks both participants.
        unserialize(json_decode($payload, true)['data']['command'])->handle(app(ChannelManager::class));
        Event::assertNotDispatched(MessageSent::class);
    }

    public function test_survivor_can_report_deleted_user_but_not_after_dialog_expiry(): void
    {
        $chat = Conversation::factory()->create();
        $owner = $chat->advertisementResponse->advertisement->user;
        $survivor = $chat->advertisementResponse->user;
        app(AccountDeletionService::class)->delete($owner);
        $body = ['target_type' => 'user', 'context_type' => 'conversation', 'context_id' => $chat->id, 'reason' => 'spam'];
        $this->actingAs($survivor)->postJson('/api/reports', $body)->assertCreated();
        $this->travelTo($chat->fresh()->expires_at);
        $this->postJson('/api/reports', $body)->assertNotFound();
    }

    public function test_migration_preserves_populated_cascades_and_moderation_constraints(): void
    {
        $migration = require database_path('migrations/2026_11_02_120000_add_account_lifecycle.php');
        $migration->down();
        $chat = Conversation::factory()->create();
        $ad = $chat->advertisementResponse->advertisement;
        $message = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $ad->user_id]);
        $report = Report::factory()->create(['target_id' => $ad->id]);
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        app(UserBlockService::class)->block($admin, $ad->user, ['reason_code' => 'spam', 'reason' => 'Migration fixture.'], $report);
        $actionId = $ad->user->fresh()->block_action_id;
        $migration->up();
        $this->assertNotNull($chat->fresh());
        $this->assertSame($message->body, $message->fresh()->body);
        $this->assertSame($ad->city_id, $ad->fresh()->city_id);
        $this->assertSame($report->snapshot, $report->fresh()->snapshot);
        $this->assertSame($actionId, $ad->user->fresh()->block_action_id);
        $this->assertDatabaseCount('moderation_purge_actions', 0);
        $this->expectException(QueryException::class);
        DB::table('admin_actions')->where('id', $actionId)->delete();
    }

    public function test_rollback_refuses_to_remove_deletion_markers(): void
    {
        $user = User::factory()->create();
        app(AccountDeletionService::class)->delete($user);
        $migration = require database_path('migrations/2026_11_02_120000_add_account_lifecycle.php');
        try {
            $migration->down();
            $this->fail('Tombstone marker was removed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('data-preserving', $exception->getMessage());
        }
        $this->assertTrue($user->fresh()->isAccountDeleted());
        $this->assertDatabaseCount('account_deletions', 1);
    }

    public function test_database_refuses_a_purge_permit_for_an_active_linked_case(): void
    {
        $report = Report::factory()->create();
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        app(UserBlockService::class)->block($admin, $report->target->user, ['reason_code' => 'spam', 'reason' => 'Active measure.'], $report);
        $this->expectException(QueryException::class);
        DB::table('moderation_purge_actions')->insert(['admin_action_id' => DB::table('admin_actions')->value('id')]);
    }

    public function test_retention_purges_only_completed_expired_decision_chain_not_same_targets_new_case(): void
    {
        $this->travelTo(now()->subYears(3));
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $target = User::factory()->create();
        $service = app(UserBlockService::class);
        $service->block($admin, $target, ['reason_code' => 'spam', 'reason' => 'Old case measure.']);
        $service->unblock($admin, $target, ['reason' => 'Old case closed.']);
        $this->assertSame(0, app(AccountRetentionService::class)->purge()['moderation_records']);
        $this->travel(13)->months();
        $service->block($admin, $target, ['reason_code' => 'spam', 'reason' => 'Separate new case.']);
        $currentId = $target->fresh()->block_action_id;
        $this->assertSame(2, app(AccountRetentionService::class)->purge()['moderation_records']);
        $this->assertDatabaseCount('admin_actions', 1);
        $this->assertDatabaseHas('admin_actions', ['id' => $currentId]);
        $this->assertTrue($target->fresh()->isBlocked());
        $this->assertDatabaseCount('moderation_purge_actions', 0);
    }

    public function test_unreferenced_tombstone_waits_35_days_and_both_recovery_confirmations(): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = User::factory()->create();
        $record = app(AccountDeletionService::class)->delete($user);
        $record->forceFill(['registry_confirmed_at' => now(), 'backups_cleared_at' => now()])->save();
        $this->travelTo($record->deleted_at->copy()->addDays(35)->subSecond());
        $this->assertSame(0, app(AccountRetentionService::class)->purge()['users']);
        $this->travel(1)->seconds();
        $this->assertSame(1, app(AccountRetentionService::class)->purge()['users']);
        $this->assertNull($user->fresh());
        $this->assertNull(app(AccountDeletionService::class)->delete($user));
        $this->assertDatabaseCount('account_deletions', 0);
    }

    public function test_moderation_access_stops_at_expiry_before_physical_cleanup(): void
    {
        $this->travelTo(now()->setDate(2024, 2, 29)->startOfDay());
        $report = Report::factory()->create();
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $target = $report->target->user;
        app(UserBlockService::class)->block($admin, $target, ['reason_code' => 'spam', 'reason' => 'Fixture measure.'], $report);
        app(AccountDeletionService::class)->delete($target);
        app(ReportResolutionService::class)->closeDeletedTarget($admin, $report, 'Аккаунт удалён.');
        $this->assertFalse($target->fresh()->isBlocked());
        $this->travelTo(now()->addMonthsNoOverflow(12)->subSecond());
        $this->actingAs($admin)->getJson('/api/admin/reports/'.$report->id)->assertOk();
        $this->travel(1)->seconds();
        $this->getJson('/api/admin/reports/'.$report->id)->assertNotFound();
        $this->getJson('/api/admin/reports/'.$report->id.'/history')->assertNotFound();
        $this->getJson('/api/admin/reports?status=all')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/admin/users/'.$target->id.'/history')->assertOk()->assertJsonPath('total', 0);
        $this->assertNotNull($report->fresh());
        $this->assertDatabaseCount('admin_actions', 2);
    }

    public function test_stale_user_cannot_send_verification_or_password_reset_mail_after_deletion(): void
    {
        $user = User::factory()->unverified()->create();
        app(AccountDeletionService::class)->delete($user);
        Notification::fake();
        $user->sendEmailVerificationNotification();
        $user->sendPasswordResetNotification('old-token');
        Notification::assertNothingSent();
    }

    public function test_expiring_moderation_history_does_not_revive_old_notifications(): void
    {
        $chat = Conversation::factory()->create();
        $response = $chat->advertisementResponse;
        $recipient = $response->advertisement->user;
        $message = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $response->user_id]);
        $old = serialize(new NewMessageNotification($message));
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        app(UserBlockService::class)->block($admin, $recipient, ['reason_code' => 'spam', 'reason' => 'Fixture block cycle.']);
        app(UserBlockService::class)->unblock($admin, $recipient, ['reason' => 'Review completed.']);
        $allowed = serialize(new NewMessageNotification($message));
        $this->travel(13)->months();
        $this->assertSame(2, app(AccountRetentionService::class)->purge()['moderation_records']);
        $this->assertFalse(unserialize($old)->shouldSend($recipient, 'mail'));
        $this->assertTrue(unserialize($allowed)->shouldSend($recipient, 'mail'));
        $this->assertTrue((new NewMessageNotification($message))->shouldSend($recipient, 'mail'));
        $legacy = unserialize($old);
        (function (): void {
            unset($this->moderationVersion, $this->moderationUserIds);
        })->call($legacy);
        $this->assertFalse($legacy->shouldSend($recipient, 'mail'));
        $this->assertFalse($legacy->shouldSend($recipient, 'mail'));
        $this->assertSame(1, (int) $recipient->fresh()->purged_block_count);
        $this->assertArrayNotHasKey('purged_block_count', $recipient->fresh()->toArray());
    }
}
