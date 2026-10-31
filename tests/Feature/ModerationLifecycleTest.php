<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ModerationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public static function restorationOrders(): array
    {
        return [
            'unblock then restore' => [false, false],
            'restore then unblock' => [true, false],
            'expiry on unblock' => [false, true],
            'expiry on restore' => [true, true],
        ];
    }

    #[DataProvider('restorationOrders')]
    public function test_report_decision_appeal_and_restoration_preserve_history_and_independent_restrictions(bool $restoreFirst, bool $expired): void
    {
        Notification::fake();
        $this->freezeTime();
        $admin = User::factory()->create(['user_type' => 'admin']);
        $outsider = User::factory()->create();
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $response->update(['status' => 'accepted']);
        $ad = $response->advertisement;
        $owner = $ad->user;
        $reporter = $response->user;
        $publishedAt = now()->subDay()->startOfSecond();
        $ad->update(['status' => 'published', 'published_at' => $publishedAt, 'description' => 'Public content at submission.']);
        $saved = Message::factory()->create(['conversation_id' => $conversation->id, 'user_id' => $owner->id, 'body' => 'Private conversation content.', 'read_at' => null]);
        $identity = $owner->only(['email', 'email_verified_at', 'user_type', 'password']);
        $reportId = $this->actingAs($reporter)->postJson('/api/reports', [
            'target_type' => 'advertisement', 'context_type' => 'advertisement', 'context_id' => $ad->id,
            'reason' => 'spam', 'comment' => 'Private reporter explanation.',
        ])->assertCreated()->json('report.id');
        $reportUrl = "/api/admin/reports/{$reportId}";
        $userUrl = "/api/admin/users/{$owner->id}";
        $adUrl = "/api/admin/advertisements/{$ad->id}";
        $conversationUrl = "/api/conversations/{$conversation->id}";
        $snapshot = Report::findOrFail($reportId)->snapshot;
        $this->assertDatabaseCount('admin_actions', 0);
        $this->assertFalse($owner->fresh()->isBlocked());
        $this->assertFalse($ad->fresh()->isHidden());

        $this->actingAs($admin)->postJson($reportUrl.'/resolve', [
            'status' => 'resolved', 'actions' => ['block_user', 'hide_advertisement'],
            'sanction_reason_code' => 'spam', 'sanction_reason' => 'Public reason for both restrictions.',
            'resolution_note' => 'Private moderator findings.',
        ])->assertOk()->assertJsonPath('report.status', 'resolved')->assertJsonCount(2, 'report.resolution_action_ids');
        $blockId = $owner->fresh()->block_action_id;
        $hideId = $ad->fresh()->hide_action_id;
        $originals = AdminAction::query()->get()->mapWithKeys(fn ($entry) => [$entry->id => $entry->getAttributes()])->all();
        $this->getJson($reportUrl.'/history')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson($conversationUrl)->assertForbidden();
        $this->getJson($conversationUrl.'/messages')->assertForbidden();

        foreach ([$owner, $reporter, $outsider] as $viewer) {
            $this->actingAs($viewer);
            foreach ([$reportUrl, $reportUrl.'/history', $userUrl.'/history', $adUrl.'/history'] as $url) {
                $this->getJson($url)->assertForbidden();
            }
        }
        foreach ([$owner, $reporter] as $viewer) {
            $this->actingAs($viewer)->postJson($conversationUrl.'/messages', ['message' => 'Forbidden while blocked.'])
                ->assertForbidden()->assertJsonPath('code', $viewer->id === $owner->id ? 'user_blocked' : 'conversation_paused');
            $history = $this->getJson($conversationUrl)->assertOk()->assertJsonPath('conversation.communication_paused', true);
            $history->assertDontSee('Private reporter explanation.')->assertDontSee('Private moderator findings.');
        }
        $this->assertDatabaseCount('messages', 1);
        $this->actingAs($reporter)->getJson($conversationUrl)->assertJsonMissingPath('conversation.advertisement.description')
            ->assertJsonMissingPath('conversation.advertisement.moderation');
        $this->postJson($conversationUrl.'/read', ['through_id' => $saved->id])->assertOk();
        $this->assertNotNull($saved->fresh()->read_at);
        $this->actingAs($owner)->getJson('/api/user')->assertJsonPath('user.block_reason', 'Public reason for both restrictions.')
            ->assertDontSee('Private reporter explanation.')->assertDontSee('Private moderator findings.');
        $this->postJson('/api/reports', [
            'target_type' => 'user', 'context_type' => 'conversation', 'context_id' => $conversation->id, 'reason' => 'spam',
        ])->assertForbidden()->assertJsonPath('code', 'user_blocked');

        $this->actingAs($admin);
        foreach ([[$userUrl, $blockId], [$adUrl, $hideId]] as [$url, $decision]) {
            $body = ['decision_id' => $decision, 'reason' => 'Restriction upheld after manual appeal.'];
            $this->postJson($url.'/appeal-rejected', $body)->assertOk()->assertJsonPath('changed', true);
            $this->postJson($url.'/appeal-rejected', $body)->assertOk()->assertJsonPath('changed', false);
            $this->getJson($url.'/history')->assertOk()->assertJsonPath('data.0.action', 'appeal_rejected')
                ->assertJsonPath('data.0.related_action_id', $decision);
        }
        if ($expired) {
            $this->travel(1)->years();
        }
        $review = ['reason_code' => 'review', 'reason' => 'Correction confirmed on manual review.'];
        $first = $restoreFirst ? $adUrl.'/restore' : $userUrl.'/unblock';
        $second = $restoreFirst ? $userUrl.'/unblock' : $adUrl.'/restore';
        $this->postJson($first, $review)->assertOk()->assertJsonPath('changed', true);
        $this->assertSame($restoreFirst, $owner->fresh()->isBlocked());
        $this->assertSame(! $restoreFirst, $ad->fresh()->isHidden());
        $this->actingAs($outsider)->getJson('/api/advertisements/'.$ad->id)->assertNotFound();
        $this->actingAs($reporter)->postJson($conversationUrl.'/messages', ['message' => 'After first review.'])
            ->assertStatus($restoreFirst ? 403 : 201);
        $this->actingAs($admin)->postJson($second, $review)->assertOk()->assertJsonPath('changed', true);
        $this->postJson($first, $review)->assertOk()->assertJsonPath('changed', false);
        $this->postJson($second, $review)->assertOk()->assertJsonPath('changed', false);
        $this->postJson($reportUrl.'/resolve', ['status' => 'dismissed', 'resolution_note' => 'Cannot rewrite.'])->assertConflict();
        $this->postJson($userUrl.'/appeal-rejected', ['decision_id' => $blockId, 'reason' => 'Stale review'])->assertConflict();
        $this->postJson($adUrl.'/appeal-rejected', ['decision_id' => $hideId, 'reason' => 'Stale review'])->assertConflict();

        $this->assertFalse($owner->fresh()->isBlocked());
        $this->assertFalse($ad->fresh()->isHidden());
        $this->assertSame($expired ? 'closed' : 'published', $ad->fresh()->status->value);
        $this->assertTrue($ad->fresh()->published_at->equalTo($publishedAt));
        $this->assertEquals($identity, $owner->fresh()->only(array_keys($identity)));
        $this->assertSame('accepted', $response->fresh()->status->value);
        $this->assertSame('Private conversation content.', $saved->fresh()->body);
        $this->assertSame($snapshot, Report::findOrFail($reportId)->snapshot);
        $this->assertSame('resolved', Report::findOrFail($reportId)->status->value);
        $this->assertDatabaseCount('admin_actions', $expired ? 8 : 7);
        foreach ($originals as $id => $attributes) {
            $this->assertSame($attributes, AdminAction::findOrFail($id)->getAttributes());
        }
        foreach ([[$userUrl, 'user_unblocked', $blockId], [$adUrl, 'advertisement_restored', $hideId]] as [$url, $action, $decision]) {
            $entries = $this->getJson($url.'/history')->assertOk()->json('data');
            $restoration = collect($entries)->firstWhere('action', $action);
            $this->assertSame($decision, $restoration['related_action_id']);
            $this->assertSame($admin->id, $restoration['actor_id']);
            $this->assertNotEmpty($restoration['occurred_at']);
            $this->assertNotEmpty($restoration['before_state']);
            $this->assertNotEmpty($restoration['after_state']);
        }
        $this->getJson($adUrl.'/history')->assertJsonPath('total', $expired ? 4 : 3);
        $this->getJson($userUrl.'/history')->assertJsonPath('total', 3);
        $this->getJson($reportUrl.'/history')->assertJsonCount(3, 'data')->assertDontSee('Private conversation content.');
        $this->getJson('/api/admin/dashboard')->assertExactJson(['blocked_users' => 0, 'hidden_advertisements' => 0, 'open_reports' => 0]);
        $this->actingAs($outsider)->getJson('/api/advertisements/'.$ad->id)->assertStatus($expired ? 404 : 200);
        foreach ([$owner, $reporter] as $viewer) {
            $this->actingAs($viewer)->postJson($conversationUrl.'/messages', ['message' => 'Continues after review.'])->assertCreated();
        }
    }
}
