<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Report;
use App\Models\User;
use App\Services\AdvertisementModerationService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReportSubmissionTest extends TestCase
{
    use RefreshDatabase;

    private function body(Advertisement $ad): array
    {
        return ['target_type' => 'advertisement', 'context_type' => 'advertisement', 'context_id' => $ad->id, 'reason' => 'spam'];
    }

    public function test_snapshot_and_duplicate_are_private_and_preserved_after_edit(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'description' => 'Original public content']);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/reports', $this->body($ad) + ['reporter_id' => $ad->user_id, 'snapshot' => ['secret' => 'forged']])
            ->assertCreated()->assertExactJson(['report' => ['id' => 1, 'status' => 'open']]);
        $report = Report::query()->firstOrFail();
        $this->assertSame($user->id, $report->reporter_id);
        $snapshot = $report->snapshot['advertisement'];
        $this->assertSame('Original public content', $snapshot['description']);
        foreach (['user_id', 'children', 'email', 'moderation', 'secret'] as $key) {
            $this->assertArrayNotHasKey($key, $snapshot);
        }
        $ad->update(['description' => 'Edited']);
        $this->postJson('/api/reports', array_replace($this->body($ad), ['reason' => 'fraud']))->assertUnprocessable()->assertJsonPath('code', 'report_already_open');
        $this->assertSame('Original public content', $report->fresh()->snapshot['advertisement']['description']);
        $this->assertDatabaseCount('reports', 1);
        $this->assertDatabaseCount('admin_actions', 0);
        $this->getJson('/api/reports/1')->assertNotFound();
    }

    public static function deniedActors(): array
    {
        return [['guest', 401], ['admin', 403], ['unverified', 403], ['blocked', 403], ['owner', 422]];
    }

    #[DataProvider('deniedActors')]
    public function test_actor_access(string $kind, int $status): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published']);
        if ($kind !== 'guest') {
            $user = $kind === 'owner' ? $ad->user : User::factory()->create([
                'user_type' => $kind === 'admin' ? 'admin' : 'family',
                'email_verified_at' => $kind === 'unverified' ? null : now(),
            ]);
            if ($kind === 'blocked') {
                app(UserBlockService::class)->block(User::factory()->create(['user_type' => 'admin']), $user, ['reason_code' => 'spam', 'reason' => 'Public reason for block']);
            }
            $this->actingAs($user->fresh());
        }
        $this->postJson('/api/reports', $this->body($ad))->assertStatus($status);
        $this->assertDatabaseCount('reports', 0);
    }

    public static function unavailableAds(): array
    {
        return [['draft'], ['closed'], ['hidden'], ['blocked']];
    }

    #[DataProvider('unavailableAds')]
    public function test_unavailable_advertisement(string $state): void
    {
        $ad = Advertisement::factory()->create(['status' => in_array($state, ['draft', 'closed']) ? $state : 'published']);
        $admin = User::factory()->create(['user_type' => 'admin']);
        if ($state === 'hidden') {
            app(AdvertisementModerationService::class)->change($admin, $ad, true, ['reason_code' => 'spam', 'reason' => 'Public hide reason']);
        }
        if ($state === 'blocked') {
            app(UserBlockService::class)->block($admin, $ad->user, ['reason_code' => 'spam', 'reason' => 'Public block reason']);
        }
        $this->actingAs(User::factory()->create())->postJson('/api/reports', $this->body($ad))->assertNotFound();
    }

    public function test_closed_conversation_context_and_blocked_interlocutor_without_messages(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $ad = $response->advertisement;
        $ad->update(['status' => 'closed']);
        $conversation->messages()->create(['user_id' => $response->user_id, 'body' => 'Private message must stay private']);
        $body = ['context_type' => 'conversation', 'context_id' => $conversation->id, 'target_type' => 'advertisement', 'reason' => 'spam'];
        $this->actingAs($response->user)->postJson('/api/reports', $body)->assertCreated();
        app(UserBlockService::class)->block(User::factory()->create(['user_type' => 'admin']), $ad->user, ['reason_code' => 'spam', 'reason' => 'Public block reason']);
        $this->postJson('/api/reports', array_replace($body, ['target_type' => 'user', 'target_id' => 999999]))->assertCreated();
        $report = Report::query()->where('target_type', 'user')->firstOrFail();
        $this->assertSame($ad->user_id, $report->target_id);
        $this->assertSame(['conversation'], array_keys($report->snapshot));
        $this->assertStringNotContainsString('Private message', json_encode($report->snapshot));
        $this->actingAs(User::factory()->create())->postJson('/api/reports', $body)->assertNotFound();
    }

    public function test_closed_advertisement_direct_context_requires_existing_conversation(): void
    {
        $conversation = Conversation::factory()->create();
        $ad = $conversation->advertisementResponse->advertisement;
        $ad->update(['status' => 'closed']);
        $this->actingAs($conversation->advertisementResponse->user)->postJson('/api/reports', $this->body($ad))->assertCreated();
    }

    public function test_public_teacher_context_excludes_private_profile_and_self_report(): void
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $profile = $teacher->teacherProfile()->create(['surname' => 'Public surname', 'city_id' => City::factory()->create()->id, 'about' => 'Private biography', 'education' => 'Private education', 'public_profile_requested' => true, 'public_profile_status' => 'approved']);
        $body = ['context_type' => 'teacher_profile', 'context_id' => $teacher->id, 'target_type' => 'user', 'reason' => 'other', 'comment' => 'A voluntary quotation'];
        $this->actingAs(User::factory()->create())->postJson('/api/reports', $body)->assertCreated();
        $snapshot = Report::query()->firstOrFail()->snapshot['teacher'];
        $this->assertSame(['id', 'name', 'surname', 'city', 'subjects'], array_keys($snapshot));
        $this->actingAs($teacher)->postJson('/api/reports', $body)->assertUnprocessable();
        $profile->update(['public_profile_requested' => false]);
        $this->actingAs(User::factory()->create())->postJson('/api/reports', $body)->assertNotFound();
    }

    public static function teacherVisibility(): array
    {
        return [['approved', true], ['pending', true], ['private', false]];
    }

    #[DataProvider('teacherVisibility')]
    public function test_service_snapshot_matches_public_show_without_private_teacher_data(string $status, bool $requested): void
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $profile = $teacher->teacherProfile()->create([
            'surname' => 'Public surname', 'city_id' => City::factory()->create()->id,
            'about' => 'Private biography', 'education' => 'Private education',
            'public_profile_requested' => $requested, 'public_profile_status' => $status,
        ]);
        $ad = Advertisement::factory()->create([
            'user_id' => $teacher->id, 'type' => 'teacher_to_service', 'status' => 'published',
        ]);
        $this->actingAs(User::factory()->create());
        $public = $this->getJson('/api/advertisements/'.$ad->id)->assertOk()->json('advertisement');
        $this->postJson('/api/reports', $this->body($ad))->assertCreated();
        $report = Report::query()->firstOrFail();
        $this->assertSame($public, $report->snapshot['advertisement']);
        if ($status === 'approved') {
            $this->assertSame($teacher->id, $report->snapshot['advertisement']['public_teacher']['id']);
            $this->assertSame(['id', 'name', 'surname', 'city', 'subjects'], array_keys($report->snapshot['advertisement']['public_teacher']));
        } else {
            $this->assertNull($report->snapshot['advertisement']['public_teacher']);
        }
        $this->assertStringNotContainsString('Private biography', json_encode($report->snapshot));
        $this->assertStringNotContainsString('Private education', json_encode($report->snapshot));
        $this->assertStringNotContainsString($teacher->email, json_encode($report->snapshot));
        $profile->update(['surname' => 'Changed after submission', 'public_profile_requested' => false]);
        $this->assertSame($public, $report->fresh()->snapshot['advertisement']);
    }

    public static function badBodies(): array
    {
        return [[['reason' => 'invalid']], [['reason' => 'other', 'comment' => '   short  ']], [['comment' => str_repeat('я', 2001)]], [['target_type' => 'user']], [['context_id' => -1]], [['context_type' => 'message']], [['reason' => 'other', 'comment' => " \n\t "]]];
    }

    #[DataProvider('badBodies')]
    public function test_invalid_fields(array $override): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published']);
        $this->actingAs(User::factory()->create())->postJson('/api/reports', array_replace($this->body($ad), $override))->assertUnprocessable();
        $this->assertDatabaseCount('reports', 0);
    }

    public function test_new_report_after_closed_report_preserves_original(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published']);
        $this->actingAs(User::factory()->create())->postJson('/api/reports', $this->body($ad))->assertCreated();
        Report::query()->firstOrFail()->forceFill(['status' => 'dismissed', 'resolved_by' => User::factory()->create(['user_type' => 'admin'])->id, 'resolution_note' => 'Not confirmed', 'resolved_at' => now()])->save();
        $this->postJson('/api/reports', $this->body($ad))->assertCreated();
        $this->assertDatabaseCount('reports', 2);
    }

    public function test_teacher_can_report_and_user_reason_cannot_be_off_topic(): void
    {
        $conversation = Conversation::factory()->create();
        $actor = $conversation->advertisementResponse->user;
        $actor->update(['user_type' => 'teacher']);
        $body = ['target_type' => 'user', 'context_type' => 'conversation', 'context_id' => $conversation->id, 'reason' => 'off_topic'];
        $this->actingAs($actor)->postJson('/api/reports', $body)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/reports', array_replace($body, ['reason' => 'other', 'comment' => str_repeat('я', 2000)]))->assertCreated();
    }

    public function test_unverified_and_validation_failures_consume_the_budget(): void
    {
        $actor = User::factory()->unverified()->create();
        $this->actingAs($actor);
        $this->postJson('/api/reports', [])->assertForbidden()->assertJsonPath('code', 'email_verification_required');
        $actor->forceFill(['email_verified_at' => now()])->save();
        $this->postJson('/api/reports', [])->assertUnprocessable();
        $this->postJson('/api/reports', [])->assertUnprocessable();
        $this->postJson('/api/reports', [])->assertStatus(429);
    }

    public function test_attempt_limits_and_exact_boundaries_do_not_extend_on_429(): void
    {
        $this->freezeTime();
        $start = now()->toImmutable();
        $user = User::factory()->create();
        $body = ['target_type' => 'advertisement', 'context_type' => 'advertisement', 'context_id' => 99999, 'reason' => 'spam'];
        $this->actingAs($user);
        foreach ([0, 60, 120] as $seconds) {
            $this->travelTo($start->addSeconds($seconds));
            for ($i = 0; $i < 3; $i++) {
                $this->postJson('/api/reports', $body)->assertNotFound();
            }
            $this->postJson('/api/reports', $body)->assertStatus(429)->assertHeader('Retry-After', '60');
            $this->travel(59)->seconds();
            $this->postJson('/api/reports', $body)->assertStatus(429)->assertJsonPath('retry_after', 1);
        }
        $this->travelTo($start->addSeconds(180));
        $this->postJson('/api/reports', $body)->assertNotFound();
        $this->app['session']->regenerate();
        $this->postJson('/api/reports', $body)->assertStatus(429)->assertJsonPath('retry_after', 86220);
        $this->actingAs(User::factory()->create())->postJson('/api/reports', $body)->assertNotFound();
        $this->actingAs($user);
        $this->travelTo($start->addSeconds(86399));
        $this->postJson('/api/reports', $body)->assertStatus(429)->assertHeader('Retry-After', '1');
        $this->travel(1)->seconds();
        $this->postJson('/api/reports', $body)->assertNotFound();
        $this->assertDatabaseCount('reports', 0);
    }
}
