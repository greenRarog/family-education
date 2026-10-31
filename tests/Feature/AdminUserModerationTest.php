<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_card_history_are_admin_only_and_reads_do_not_require_verification(): void
    {
        $target = User::factory()->create();
        foreach (['/api/admin/users', "/api/admin/users/{$target->id}", "/api/admin/users/{$target->id}/history"] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->actingAs($target)->getJson('/api/admin/users')->assertForbidden();
        $admin = User::factory()->create(['user_type' => UserType::ADMIN, 'email_verified_at' => null]);
        $this->actingAs($admin)->getJson("/api/admin/users/{$target->id}")->assertOk()
            ->assertJsonPath('user.email', $target->email)->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.children')->assertJsonMissingPath('user.family');
        $this->getJson("/api/admin/users/{$target->id}/history")->assertOk();
        $this->postJson("/api/admin/users/{$target->id}/appeal-rejected", ['decision_id' => 1, 'reason' => 'Review'])
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');
    }

    public function test_pagination_filters_and_invalid_or_missing_targets(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        User::factory()->count(25)->create();
        $target = User::factory()->create();
        app(UserBlockService::class)->block($admin, $target, ['reason_code' => 'spam', 'reason' => 'Repeated spam content.']);
        $this->actingAs($admin)->getJson('/api/admin/users')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 27);
        $this->getJson('/api/admin/users?page=2')->assertOk()->assertJsonCount(7, 'data');
        $this->getJson('/api/admin/users?state=blocked')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        $this->getJson('/api/admin/users?state=active')->assertOk()->assertJsonPath('total', 26);
        $this->getJson('/api/admin/users?state=bad')->assertUnprocessable();
        $this->getJson('/api/admin/users?page=0')->assertUnprocessable();
        $this->getJson('/api/admin/users/99999')->assertNotFound();
    }

    public function test_review_reject_repeat_and_cancel_preserve_history_and_reject_stale_decisions(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $target = User::factory()->create();
        $this->actingAs($admin);
        $body = ['reason_code' => 'spam', 'reason' => 'Repeated spam content.'];
        $id = $this->postJson("/api/admin/users/{$target->id}/block", $body)->assertOk()->json('action_id');
        $original = AdminAction::findOrFail($id)->getAttributes();
        $review = ['decision_id' => $id, 'reason' => 'Reviewed, the restriction remains necessary.'];
        $this->postJson("/api/admin/users/{$target->id}/appeal-rejected", $review)->assertOk()->assertJsonPath('changed', true);
        $this->postJson("/api/admin/users/{$target->id}/appeal-rejected", $review)->assertOk()->assertJsonPath('changed', false);
        $this->getJson("/api/admin/users/{$target->id}/history")->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.action', 'appeal_rejected')->assertJsonPath('data.0.related_action_id', $id);
        $this->postJson("/api/admin/users/{$target->id}/unblock", ['decision_id' => $id, 'reason_code' => 'review', 'reason' => 'Correction confirmed.'])->assertOk();
        $new = $this->postJson("/api/admin/users/{$target->id}/block", $body)->assertOk()->json('action_id');
        $this->postJson("/api/admin/users/{$target->id}/appeal-rejected", $review)->assertConflict();
        $this->postJson("/api/admin/users/{$target->id}/unblock", $review)->assertConflict();
        $this->assertSame($new, $target->fresh()->block_action_id);
        $this->assertSame($original, AdminAction::findOrFail($id)->getAttributes());
        $this->postJson("/api/admin/users/{$target->id}/appeal-rejected", ['decision_id' => $new, 'reason' => '   '])->assertUnprocessable();
        $this->actingAs($target)->postJson("/api/admin/users/{$target->id}/appeal-rejected", $review)->assertForbidden();
    }
}
