<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AdvertisementModerationService;
use App\Services\UserBlockService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdvertisementModerationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::ADMIN]);
    }

    private function reason(): array
    {
        return ['reason_code' => 'off_topic', 'reason' => 'Content requires correction.'];
    }

    public static function statuses(): array
    {
        return [['draft'], ['published'], ['closed']];
    }

    #[DataProvider('statuses')]
    public function test_hide_repeat_restore_preserve_status_and_dates_and_ignore_forged_fields(string $status): void
    {
        $ad = Advertisement::factory()->create(['status' => $status, 'published_at' => now()->subDay()]);
        $date = $ad->published_at->toISOString();
        $this->actingAs($this->admin());
        $id = $this->postJson("/api/admin/advertisements/{$ad->id}/hide", $this->reason() + ['status' => 'closed', 'actor_id' => $ad->user_id])
            ->assertOk()->assertJsonPath('changed', true)->assertJsonPath('advertisement.status', $status)->json('action_id');
        $before = AdminAction::findOrFail($id)->getAttributes();
        $this->postJson("/api/admin/advertisements/{$ad->id}/hide", ['reason_code' => 'spam', 'reason' => 'A different explanation.'])
            ->assertOk()->assertJsonPath('changed', false)->assertJsonPath('advertisement.moderation.reason_code', 'off_topic');
        $this->postJson("/api/admin/advertisements/{$ad->id}/restore", ['reason_code' => 'review', 'reason' => 'Correction reviewed.'])
            ->assertOk()->assertJsonPath('advertisement.status', $status)->assertJsonPath('advertisement.moderation.is_hidden', false);
        $this->postJson("/api/admin/advertisements/{$ad->id}/restore", ['reason' => 'Repeat'])->assertOk()->assertJsonPath('changed', false);
        $this->assertDatabaseCount('admin_actions', 2);
        $this->assertSame($id, AdminAction::query()->latest('id')->first()->related_action_id);
        $this->assertSame($date, $ad->fresh()->published_at->toISOString());
        $this->assertSame($before, AdminAction::findOrFail($id)->getAttributes());
        $this->postJson("/api/admin/advertisements/{$ad->id}/hide", $this->reason())->assertOk();
        $this->assertNotSame($id, $ad->fresh()->hide_action_id);
        $this->postJson("/api/admin/advertisements/{$ad->id}/restore", ['decision_id' => $id, 'reason' => 'Stale review'])->assertConflict();
    }

    public function test_access_validation_and_protected_state(): void
    {
        $ad = Advertisement::factory()->create();
        foreach (['hide', 'restore'] as $operation) {
            $url = "/api/admin/advertisements/{$ad->id}/{$operation}";
            $this->get('/logout');
            auth()->forgetGuards();
            $this->postJson($url, $this->reason())->assertUnauthorized();
            $this->actingAs($ad->user)->postJson($url, $this->reason())->assertForbidden();
            $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN, 'email_verified_at' => null]))->postJson($url, $this->reason())->assertForbidden();
        }
        $this->actingAs($this->admin());
        foreach ([[], ['reason_code' => 'bad', 'reason' => 'Enough explanation'], ['reason_code' => 'spam', 'reason' => 'short']] as $body) {
            $this->postJson("/api/admin/advertisements/{$ad->id}/hide", $body)->assertUnprocessable();
        }
        $this->postJson('/api/admin/advertisements/99999/hide', $this->reason())->assertNotFound();
        $ad->fill(['hide_action_id' => -1])->save();
        $this->assertFalse($ad->fresh()->isHidden());
        $this->postJson("/api/admin/advertisements/{$ad->id}/hide", $this->reason())->assertOk();
        $this->assertArrayNotHasKey('hide_action_id', $ad->fresh()->load('hidingAction')->toArray());
        $this->assertArrayNotHasKey('hiding_action', $ad->fresh()->load('hidingAction')->toArray());
    }

    public function test_visibility_owner_and_both_sides_of_existing_dialogue(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $ad = $response->advertisement;
        $ad->update(['status' => 'published', 'published_at' => now()]);
        $owner = $ad->user;
        $author = $response->user;
        $this->actingAs($this->admin())->postJson("/api/admin/advertisements/{$ad->id}/hide", $this->reason())->assertOk();
        $this->getJson("/api/conversations/{$conversation->id}")->assertForbidden();
        $this->getJson("/api/advertisements/{$ad->id}")->assertNotFound();
        $this->actingAs($author)->getJson('/api/advertisements/feed')->assertJsonPath('advertisements.total', 0);
        $this->getJson("/api/advertisements/{$ad->id}")->assertNotFound();
        $this->getJson("/api/advertisements/{$ad->id}/edit")->assertNotFound();
        $this->getJson("/api/conversations/{$conversation->id}")->assertOk()->assertJsonPath('conversation.advertisement.unavailable', true)
            ->assertJsonMissingPath('conversation.advertisement.description')->assertJsonMissingPath('conversation.advertisement.moderation')
            ->assertJsonPath('conversation.communication_paused', false);
        $this->getJson('/api/conversations')->assertJsonMissingPath('conversations.data.0.advertisement.description');
        $this->postJson("/api/advertisements/{$ad->id}/responses", ['message' => 'New contact'])->assertNotFound();
        $this->actingAs($owner)->getJson("/api/advertisements/{$ad->id}")->assertOk()->assertJsonPath('advertisement.description', $ad->description)
            ->assertJsonPath('advertisement.moderation.is_hidden', true);
        $this->getJson('/api/advertisements')->assertJsonPath('advertisements.data.0.moderation.is_hidden', true);
        $this->getJson("/api/conversations/{$conversation->id}")->assertJsonPath('conversation.advertisement.moderation.is_hidden', true);
        $this->postJson("/api/advertisement-responses/{$response->id}/accept")->assertOk();
        $this->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Owner can continue'])->assertCreated();
        $this->actingAs($author)->postJson("/api/conversations/{$conversation->id}/messages", ['message' => 'Author can continue'])->assertCreated();
        $this->actingAs($owner)->postJson("/api/advertisement-responses/{$response->id}/reject")->assertOk();
        $this->postJson("/api/advertisements/{$ad->id}/close")->assertOk();
        $this->assertTrue($ad->fresh()->isHidden());
    }

    public function test_hidden_draft_cannot_publish_and_owner_can_correct_draft_and_published_but_not_closed(): void
    {
        $ad = Advertisement::factory()->create();
        $owner = $ad->user;
        $family = $owner->family()->create(['surname' => 'Test', 'city_id' => $ad->city_id]);
        $child = $family->children()->create(['name' => 'Test', 'birth_date' => '2020-01-01', 'sex' => 'male']);
        app(AdvertisementModerationService::class)->change($this->admin(), $ad, true, $this->reason());
        $body = ['type' => 'family_to_family', 'city_id' => $ad->city_id, 'child_ids' => [$child->id], 'participant_age_from' => 5, 'participant_age_to' => 10, 'description' => 'Corrected content', 'hide_action_id' => null, 'status' => 'published'];
        $this->actingAs($owner)->postJson("/api/advertisements/{$ad->id}/publish")->assertForbidden()->assertJsonPath('code', 'advertisement_hidden');
        foreach (['draft', 'published'] as $status) {
            $ad->update(['status' => $status]);
            $this->putJson("/api/advertisements/{$ad->id}", $body)->assertOk();
            $this->assertTrue($ad->fresh()->isHidden());
            $this->assertSame($status, $ad->fresh()->status->value);
        }
        $ad->update(['status' => 'closed']);
        $this->putJson("/api/advertisements/{$ad->id}", $body)->assertUnprocessable();
    }

    public function test_independent_sanctions_and_expired_restore(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'published_at' => now()->subYears(2)]);
        $admin = $this->admin();
        $owner = $ad->user;
        $service = app(AdvertisementModerationService::class);
        $service->change($admin, $ad, true, $this->reason());
        app(UserBlockService::class)->block($admin, $owner, ['reason_code' => 'spam', 'reason' => 'Repeated spam content']);
        $service->change($admin, $ad, false, ['reason' => 'Correction reviewed']);
        $this->assertTrue($owner->fresh()->isBlocked());
        $this->assertSame(AdvertisementStatus::CLOSED, $ad->fresh()->status);
        $this->assertNotNull($ad->fresh()->closed_at);
        $this->assertTrue($ad->fresh()->published_at->equalTo($ad->published_at));
        $this->assertDatabaseHas('admin_actions', ['target_id' => $ad->id, 'action' => 'advertisement_expired']);
        $service->change($admin, $ad, true, $this->reason());
        app(UserBlockService::class)->unblock($admin, $owner, ['reason' => 'Review']);
        $this->assertTrue($ad->fresh()->isHidden());
    }

    public function test_audit_failure_rolls_back_restore_and_expiry(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'published_at' => now()->subYears(2)]);
        $admin = $this->admin();
        app(AdvertisementModerationService::class)->change($admin, $ad, true, $this->reason());
        AdminAction::creating(static function (AdminAction $entry): void {
            if ($entry->action->value === 'advertisement_restored') {
                $entry->actor_id = -1;
            }
        });
        try {
            app(AdvertisementModerationService::class)->change($admin, $ad, false, ['reason' => 'Review']);
            $this->fail('Expected failure');
        } catch (QueryException) {
            $this->assertTrue($ad->fresh()->isHidden());
            $this->assertSame(AdvertisementStatus::PUBLISHED, $ad->fresh()->status);
            $this->assertNull($ad->fresh()->closed_at);
            $this->assertDatabaseCount('admin_actions', 1);
        } finally {
            AdminAction::flushEventListeners();
            AdminAction::clearBootedModels();
        }
    }

    public function test_public_teacher_services_and_other_ads_are_independent(): void
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $ad = Advertisement::factory()->create(['user_id' => $teacher->id, 'type' => 'teacher_to_service', 'status' => 'published']);
        $teacher->teacherProfile()->create(['surname' => 'Teacher', 'city_id' => $ad->city_id, 'about' => 'Private biography', 'public_profile_requested' => true, 'public_profile_status' => 'approved']);
        $other = Advertisement::factory()->create(['user_id' => $teacher->id, 'type' => 'teacher_to_service', 'status' => 'published']);
        app(AdvertisementModerationService::class)->change($this->admin(), $ad, true, $this->reason());
        $this->getJson("/api/teachers/{$teacher->id}")->assertOk()->assertJsonCount(1, 'advertisements')->assertJsonPath('advertisements.0.id', $other->id);
        $this->getJson('/api/advertisements/feed')->assertJsonCount(1, 'advertisements.data')->assertJsonPath('advertisements.data.0.public_teacher.id', $teacher->id)
            ->assertJsonMissingPath('advertisements.data.0.moderation');
        $this->getJson("/api/advertisements/{$ad->id}")->assertNotFound();
        $this->actingAs($teacher)->getJson("/api/advertisements/{$ad->id}/edit")->assertOk()->assertJsonPath('advertisement.moderation.is_hidden', true);
    }

    public function test_advertisement_write_failure_rolls_back_hide_audit(): void
    {
        $ad = Advertisement::factory()->create();
        $admin = $this->admin();
        Advertisement::updating(static function (Advertisement $ad): void {
            if ($ad->isDirty('hide_action_id')) {
                throw new \RuntimeException('Advertisement write failed');
            }
        });
        try {
            app(AdvertisementModerationService::class)->change($admin, $ad, true, $this->reason());
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Advertisement write failed', $e->getMessage());
            $this->assertFalse($ad->fresh()->isHidden());
            $this->assertDatabaseCount('admin_actions', 0);
        } finally {
            Advertisement::flushEventListeners();
            Advertisement::clearBootedModels();
        }
    }

    public function test_unblock_does_not_restore_hidden_publication_and_restore_does_not_unblock_owner(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'published_at' => now()->subDay()]);
        $admin = $this->admin();
        $owner = $ad->user;
        $hide = app(AdvertisementModerationService::class);
        $block = app(UserBlockService::class);
        $hide->change($admin, $ad, true, $this->reason());
        $block->block($admin, $owner, ['reason_code' => 'spam', 'reason' => 'Repeated spam content']);
        $block->unblock($admin, $owner, ['reason' => 'Review']);
        $this->getJson("/api/advertisements/{$ad->id}")->assertNotFound();
        $block->block($admin, $owner, ['reason_code' => 'spam', 'reason' => 'Repeated spam content']);
        $hide->change($admin, $ad, false, ['reason' => 'Review']);
        $this->getJson("/api/advertisements/{$ad->id}")->assertNotFound();
        $block->unblock($admin, $owner, ['reason' => 'Review']);
        $this->getJson("/api/advertisements/{$ad->id}")->assertOk();
    }
}
