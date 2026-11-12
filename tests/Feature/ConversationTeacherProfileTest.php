<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\City;
use App\Models\Conversation;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConversationTeacherProfileTest extends TestCase
{
    use RefreshDatabase;

    public static function profiles(): iterable
    {
        foreach ([true, false] as $teacherOwnsAdvertisement) {
            foreach (['approved', 'private', 'pending', 'rejected', 'missing', 'blocked', 'deleted'] as $state) {
                yield ($teacherOwnsAdvertisement ? 'owner ' : 'respondent ').$state => [$teacherOwnsAdvertisement, $state];
            }
        }
    }

    #[DataProvider('profiles')]
    public function test_counterpart_profile_visibility_before_acceptance(bool $teacherOwnsAdvertisement, string $state): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $author = $response->user;
        $teacher = $teacherOwnsAdvertisement ? $owner : $author;
        $viewer = $teacherOwnsAdvertisement ? $author : $owner;
        $teacher->update(['user_type' => UserType::TEACHER]);
        $viewer->update(['user_type' => UserType::FAMILY]);
        if ($state !== 'missing') {
            TeacherProfile::query()->create([
                'user_id' => $teacher->id, 'surname' => 'Private surname', 'city_id' => City::factory()->create()->id,
                'public_profile_requested' => $state !== 'private',
                'public_profile_status' => in_array($state, ['blocked', 'deleted']) ? 'approved' : $state,
                'about' => 'Private biography', 'education' => 'Private education',
            ]);
        }
        if ($state === 'blocked') {
            app(UserBlockService::class)->block(User::factory()->create(['user_type' => UserType::ADMIN]), $teacher,
                ['reason_code' => 'spam', 'reason' => 'Private moderation reason']);
        }
        if ($state === 'deleted') {
            $teacher->forceFill(['account_deleted_at' => now()])->save();
            $conversation->forceFill(['account_deleted_at' => now(), 'expires_at' => now()->addDay()])->save();
        }
        $expected = [
            'id' => $state === 'deleted' ? null : $teacher->id,
            'name' => $state === 'deleted' ? 'Удалённый аккаунт' : $teacher->name,
            'teacher_profile' => match ($state) {
                'approved' => 'available', 'private', 'missing' => 'private', 'deleted' => null, default => 'unavailable',
            },
        ];
        $this->actingAs($viewer);
        $this->getJson("/api/conversations/{$conversation->id}")->assertOk()->assertJsonPath('conversation.counterpart', $expected);
        $this->getJson('/api/conversations')->assertOk()->assertJsonPath('conversations.data.0.counterpart', $expected);
        if ($state !== 'deleted') {
            $this->getJson("/api/teachers/{$teacher->id}")->assertStatus($state === 'approved' ? 200 : 404);
            $this->actingAs($teacher)->getJson("/api/conversations/{$conversation->id}")->assertOk()
                ->assertJsonPath('conversation.counterpart', ['id' => $viewer->id, 'name' => $viewer->name, 'teacher_profile' => null]);
        }
        $this->actingAs(User::factory()->create())->getJson("/api/conversations/{$conversation->id}")
            ->assertForbidden()->assertJsonMissingPath('conversation.counterpart');
    }

    public function test_polling_reflects_a_profile_made_private(): void
    {
        $conversation = Conversation::factory()->create();
        $teacher = $conversation->advertisementResponse->user;
        $teacher->update(['user_type' => UserType::TEACHER]);
        $profile = TeacherProfile::query()->create([
            'user_id' => $teacher->id, 'surname' => 'Teacher', 'city_id' => City::factory()->create()->id,
            'public_profile_requested' => true, 'public_profile_status' => 'approved',
        ]);
        $this->actingAs($conversation->advertisementResponse->advertisement->user);
        $url = "/api/conversations/{$conversation->id}?after_id=0";
        $this->getJson($url)->assertOk()->assertJsonPath('conversation.counterpart.teacher_profile', 'available');
        $profile->update(['public_profile_requested' => false]);
        $this->getJson($url)->assertOk()->assertJsonPath('conversation.counterpart.teacher_profile', 'private');
    }
}
