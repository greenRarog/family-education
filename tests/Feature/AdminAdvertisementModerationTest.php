<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\User;
use App\Services\AdvertisementModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAdvertisementModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_list_all_statuses_filters_pagination_and_safe_content(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create(['status' => 'draft', 'description' => 'Review this content']);
        Advertisement::factory()->count(21)->create(['status' => 'published']);
        Advertisement::factory()->create(['status' => 'closed']);
        app(AdvertisementModerationService::class)->change($admin, $ad, true, ['reason_code' => 'spam', 'reason' => 'Repeated spam content']);
        $this->getJson('/api/admin/advertisements')->assertUnauthorized();
        $this->actingAs($ad->user)->getJson('/api/admin/advertisements')->assertForbidden();
        $admin->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($admin)->getJson('/api/admin/advertisements')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('total', 23);
        $this->getJson('/api/admin/advertisements?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/admin/advertisements?state=hidden&status=draft')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $ad->id);
        $this->getJson('/api/admin/advertisements?state=visible&status=closed')->assertJsonPath('total', 1);
        $this->getJson('/api/admin/advertisements?state=visible')->assertJsonPath('total', 22);
        $this->getJson('/api/admin/advertisements?status=bad')->assertUnprocessable();
        $this->getJson('/api/admin/advertisements?state=bad')->assertUnprocessable();
        $this->getJson('/api/admin/advertisements?page=0')->assertUnprocessable();
        $this->getJson("/api/admin/advertisements/{$ad->id}")->assertOk()->assertJsonPath('advertisement.description', 'Review this content')
            ->assertJsonPath('advertisement.moderation.is_hidden', true)->assertJsonMissingPath('advertisement.children')
            ->assertJsonMissingPath('advertisement.user')->assertJsonMissingPath('advertisement.family');
        $this->getJson("/api/admin/advertisements/{$ad->id}/history")->assertOk();
        $this->postJson("/api/admin/advertisements/{$ad->id}/appeal-rejected", ['decision_id' => $ad->fresh()->hide_action_id, 'reason' => 'Review'])
            ->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $this->getJson('/api/admin/advertisements/99999')->assertNotFound();
    }

    public function test_review_repeats_history_pagination_and_stale_decision(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create();
        $this->actingAs($admin);
        $id = $this->postJson("/api/admin/advertisements/{$ad->id}/hide", ['reason_code' => 'spam', 'reason' => 'Repeated spam content'])->assertOk()->json('action_id');
        $original = AdminAction::findOrFail($id)->getAttributes();
        for ($i = 0; $i < 21; $i++) {
            $this->postJson("/api/admin/advertisements/{$ad->id}/appeal-rejected", ['decision_id' => $id, 'reason' => "Reviewed explanation $i"])
                ->assertOk()->assertJsonPath('changed', true);
        }
        $this->postJson("/api/admin/advertisements/{$ad->id}/appeal-rejected", ['decision_id' => $id, 'reason' => 'Reviewed explanation 20'])
            ->assertOk()->assertJsonPath('changed', false);
        $this->getJson("/api/admin/advertisements/{$ad->id}/history")->assertJsonCount(20, 'data')->assertJsonPath('total', 22)
            ->assertJsonPath('data.0.action', 'appeal_rejected')->assertJsonPath('data.0.related_action_id', $id);
        $this->getJson("/api/admin/advertisements/{$ad->id}/history?page=2")->assertJsonCount(2, 'data');
        $this->postJson("/api/admin/advertisements/{$ad->id}/restore", ['decision_id' => $id, 'reason_code' => 'review', 'reason' => 'Correction reviewed'])->assertOk();
        $this->postJson("/api/admin/advertisements/{$ad->id}/appeal-rejected", ['decision_id' => $id, 'reason' => 'Old decision'])->assertConflict();
        $this->assertSame($original, AdminAction::findOrFail($id)->getAttributes());
        $this->actingAs($ad->user)->getJson("/api/admin/advertisements/{$ad->id}/history")->assertForbidden();
        $this->postJson("/api/admin/advertisements/{$ad->id}/appeal-rejected", ['decision_id' => $id, 'reason' => 'Old decision'])->assertForbidden();
    }
}
