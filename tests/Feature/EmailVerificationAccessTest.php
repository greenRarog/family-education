<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\TeacherProfileVisibilityStatus;
use App\Enums\UserType;
use App\Events\NewResponse;
use App\Models\Advertisement;
use App\Models\Child;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Family;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailVerificationAccessTest extends TestCase
{
    use RefreshDatabase;

    public static function advertisementTypes(): array
    {
        return array_map(fn ($type) => [$type], AdvertisementType::cases());
    }

    #[DataProvider('advertisementTypes')]
    public function test_unverified_author_can_prepare_draft_but_cannot_publish_or_edit_public_ad(AdvertisementType $type): void
    {
        [$user, $payload] = $this->author($type);
        $this->actingAs($user);
        $id = $this->postJson('/api/advertisements', $payload)->assertCreated()->json('advertisement.id');
        $this->putJson('/api/advertisements/'.$id, [...$payload, 'description' => 'Обновлённый черновик'])->assertOk();
        $ad = Advertisement::findOrFail($id);
        $before = $ad->getAttributes();
        $this->postJson('/api/advertisements/'.$id.'/publish')->assertForbidden()
            ->assertJsonPath('code', 'email_verification_required');
        $this->assertSame($before, $ad->fresh()->getAttributes());
        $ad->update(['status' => AdvertisementStatus::PUBLISHED]);
        $this->getJson('/api/advertisements/feed')->assertOk();
        $this->getJson('/api/advertisements/'.$id)->assertOk();
        $this->putJson('/api/advertisements/'.$id, [...$payload, 'description' => 'Запрещённая правка'])->assertForbidden()
            ->assertJsonPath('code', 'email_verification_required');
        $this->assertSame('Обновлённый черновик', $ad->fresh()->description);
        $this->postJson('/api/advertisements/'.$id.'/close')->assertOk();
        $this->assertSame(AdvertisementStatus::CLOSED, $ad->fresh()->status);
        $this->putJson('/api/advertisements/'.$id, $payload)->assertUnprocessable();
        $user->markEmailAsVerified();
        $new = $this->postJson('/api/advertisements', $payload)->assertCreated()->json('advertisement.id');
        $this->postJson('/api/advertisements/'.$new.'/publish')->assertOk();
        $this->putJson('/api/advertisements/'.$new, $payload)->assertOk();
    }

    #[DataProvider('advertisementTypes')]
    public function test_new_response_requires_verified_author_but_not_verified_advertisement_owner(AdvertisementType $type): void
    {
        Notification::fake();
        Event::fake([NewResponse::class]);
        $owner = User::factory()->unverified()->create();
        $ad = Advertisement::factory()->create(['user_id' => $owner->id, 'type' => $type, 'status' => AdvertisementStatus::PUBLISHED]);
        $user = User::factory()->unverified()->create([
            'user_type' => $type === AdvertisementType::FAMILY_TO_TEACHER ? UserType::TEACHER : UserType::FAMILY,
        ]);
        $this->actingAs($user);
        $this->getJson('/api/advertisements/'.$ad->id)->assertJsonPath('viewer.can_respond', false);
        $this->postJson('/api/advertisements/'.$ad->id.'/responses', ['message' => 'Первый текст'])->assertForbidden()
            ->assertJsonPath('code', 'email_verification_required');
        $this->assertDatabaseCount('advertisement_responses', 0);
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('messages', 0);
        Event::assertNotDispatched(NewResponse::class);
        Notification::assertNothingSent();
        $user->markEmailAsVerified();
        $this->getJson('/api/advertisements/'.$ad->id)->assertJsonPath('viewer.can_respond', true);
        $this->postJson('/api/advertisements/'.$ad->id.'/responses', ['message' => 'Первый текст'])->assertCreated();
        $this->assertDatabaseCount('advertisement_responses', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 1);
        Event::assertDispatchedTimes(NewResponse::class, 1);
    }

    public static function limitedActions(): array
    {
        return [['publish'], ['responses']];
    }

    #[DataProvider('limitedActions')]
    public function test_verification_denials_count_after_auth_and_csrf_and_before_sixth_request_429(string $action): void
    {
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $ad = Advertisement::factory()->create([
            'user_id' => $action === 'publish' ? $user->id : User::factory()->create()->id,
            'status' => $action === 'publish' ? AdvertisementStatus::DRAFT : AdvertisementStatus::PUBLISHED,
        ]);
        $path = '/api/advertisements/'.$ad->id.'/'.$action;
        $this->postJson($path, ['message' => 'Текст'])->assertUnauthorized();
        $this->actingAs($user);
        $this->app['env'] = 'local';
        try {
            $this->postJson($path, ['message' => 'Текст'])->assertStatus(419);
        } finally {
            $this->app['env'] = 'testing';
        }
        for ($i = 0; $i < 5; $i++) {
            $this->postJson($path, ['message' => 'Текст'])->assertForbidden()
                ->assertJsonPath('code', 'email_verification_required');
        }
        $this->postJson($path, ['message' => 'Текст'])->assertStatus(429)->assertHeader('Retry-After', '60');
        $this->assertDatabaseCount('advertisement_responses', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_unverified_participants_keep_existing_dialogue_lifecycle_and_outsiders_stay_denied(): void
    {
        Notification::fake();
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $owner = $response->advertisement->user;
        $respondent = $response->user;
        foreach ([$owner, $respondent] as $user) {
            $user->forceFill(['email_verified_at' => null])->save();
            $this->assertFalse($user->fresh()->hasVerifiedEmail());
            $this->actingAs($user);
            foreach (['/api/conversations', '/api/conversations/unread-count', '/api/conversations/'.$conversation->id,
                '/api/conversations/'.$conversation->id.'/messages', '/api/advertisements/'.$response->advertisement_id] as $path) {
                $this->getJson($path)->assertOk();
            }
            $this->postJson('/api/conversations/'.$conversation->id.'/read')->assertOk();
        }
        $messagePath = '/api/conversations/'.$conversation->id.'/messages';
        $this->actingAs($respondent)->postJson($messagePath, ['message' => 'Рано'])->assertUnprocessable();
        $this->actingAs($owner)->postJson($messagePath, ['message' => 'Вопрос'])->assertCreated();
        $this->postJson('/api/advertisement-responses/'.$response->id.'/accept')->assertOk();
        $this->actingAs($respondent)->postJson($messagePath, ['message' => 'Ответ'])->assertCreated();
        $this->actingAs($owner)->postJson('/api/advertisement-responses/'.$response->id.'/reject')->assertOk();
        foreach ([$owner, $respondent] as $user) {
            $this->actingAs($user)->postJson($messagePath, ['message' => 'После завершения'])->assertUnprocessable();
            $this->getJson($messagePath)->assertOk();
        }
        $outsider = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->actingAs($outsider)->getJson($messagePath)->assertForbidden();
        $this->postJson($messagePath, ['message' => 'Чужой'])->assertForbidden();
        $this->assertSame(AdvertisementResponseStatus::REJECTED, $response->fresh()->status);
        $this->assertDatabaseCount('messages', 2);
    }

    public function test_unverified_teacher_can_keep_private_profile_or_hide_approved_profile_but_not_publish(): void
    {
        [$teacher, $payload] = $this->author(AdvertisementType::TEACHER_TO_SERVICE);
        $profile = $teacher->teacherProfile;
        $profilePayload = ['surname' => 'Петров', 'city_id' => $payload['city_id'],
            'subject_ids' => $payload['subject_ids'], 'about' => 'Описание', 'public_profile_requested' => false];
        $this->actingAs($teacher)->getJson('/api/teacher-profile')->assertOk();
        $this->putJson('/api/teacher-profile', $profilePayload)->assertOk();
        $before = $profile->fresh()->getAttributes();
        $this->putJson('/api/teacher-profile', [...$profilePayload, 'public_profile_requested' => true])->assertForbidden()
            ->assertJsonPath('code', 'email_verification_required');
        $this->assertSame($before, $profile->fresh()->getAttributes());
        $profile->update(['public_profile_requested' => true, 'public_profile_status' => TeacherProfileVisibilityStatus::APPROVED]);
        $this->getJson('/api/teachers/'.$teacher->id)->assertOk();
        $this->putJson('/api/teacher-profile', [...$profilePayload, 'public_profile_requested' => true])->assertForbidden();
        $this->assertSame(TeacherProfileVisibilityStatus::APPROVED, $profile->fresh()->public_profile_status);
        $this->putJson('/api/teacher-profile', $profilePayload)->assertOk();
        $this->assertSame(TeacherProfileVisibilityStatus::PRIVATE, $profile->fresh()->public_profile_status);
        $teacher->markEmailAsVerified();
        $this->putJson('/api/teacher-profile', [...$profilePayload, 'public_profile_requested' => true])->assertOk();
        $this->assertSame(TeacherProfileVisibilityStatus::PENDING, $profile->fresh()->public_profile_status);
    }

    public function test_admin_reads_remain_available_but_all_write_methods_require_verification(): void
    {
        $admin = User::factory()->unverified()->create(['user_type' => UserType::ADMIN]);
        $city = City::factory()->create();
        $this->actingAs($admin)->getJson('/api/admin/cities')->assertOk();
        foreach ([['post', '/api/admin/cities'], ['put', '/api/admin/cities/'.$city->id],
            ['patch', '/api/admin/cities/'.$city->id], ['delete', '/api/admin/cities/'.$city->id]] as [$method, $path]) {
            $this->json(strtoupper($method), $path, ['name' => 'Новый город'])->assertForbidden()
                ->assertJsonPath('code', 'email_verification_required');
        }
        $this->assertDatabaseCount('cities', 1);
        $this->assertSame($city->name, $city->fresh()->name);
        $admin->markEmailAsVerified();
        $this->postJson('/api/admin/cities', ['name' => 'Новый город'])->assertCreated();
        $this->actingAs(User::factory()->create())->postJson('/api/admin/cities', ['name' => 'Чужое действие'])->assertForbidden();
    }

    public function test_admin_cannot_approve_unverified_profile_owner_but_can_reject(): void
    {
        [$teacher] = $this->author(AdvertisementType::TEACHER_TO_SERVICE);
        $profile = $teacher->teacherProfile;
        $profile->update(['public_profile_requested' => true, 'public_profile_status' => TeacherProfileVisibilityStatus::PENDING]);
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->actingAs($admin)->postJson('/api/admin/teacher-profiles/'.$teacher->id.'/approve')->assertForbidden()
            ->assertJsonPath('code', 'profile_email_verification_required');
        $this->assertSame(TeacherProfileVisibilityStatus::PENDING, $profile->fresh()->public_profile_status);
        $this->postJson('/api/admin/teacher-profiles/'.$teacher->id.'/reject')->assertOk();
        $profile->refresh()->update(['public_profile_status' => TeacherProfileVisibilityStatus::PENDING]);
        $teacher->markEmailAsVerified();
        $this->postJson('/api/admin/teacher-profiles/'.$teacher->id.'/approve')->assertOk();
    }

    public function test_unverified_family_can_manage_profile_settings_language_and_read_public_pages(): void
    {
        [$user] = $this->author(AdvertisementType::FAMILY_TO_FAMILY);
        $family = $user->family;
        $this->actingAs($user)->getJson('/api/family')->assertOk();
        $this->putJson('/api/family', ['surname' => 'Новое имя', 'city_id' => $family->city_id,
            'children' => $family->children->map(fn ($child) => ['id' => $child->id, 'name' => $child->name,
                'birth_date' => '2020-01-01', 'sex' => 'male'])->all()])->assertOk();
        $this->putJson('/api/user/language', ['language' => 'en'])->assertOk();
        $this->putJson('/api/notification-settings', ['email_enabled' => false, 'telegram_enabled' => false])->assertOk();
        foreach (['/api/notification-settings', '/api/advertisements', '/api/locations', '/api/advertisements/feed', '/api/subjects'] as $path) {
            $this->getJson($path)->assertOk();
        }
        $this->assertNull($user->fresh()->email_verified_at);
        $this->postJson('/logout')->assertNoContent();
    }

    private function author(AdvertisementType $type): array
    {
        $teacher = $type === AdvertisementType::TEACHER_TO_SERVICE;
        $user = User::factory()->unverified()->create(['user_type' => $teacher ? UserType::TEACHER : UserType::FAMILY]);
        $city = City::factory()->create();
        $subject = Subject::factory()->create();
        $payload = ['type' => $type->value, 'city_id' => $city->id, 'description' => 'Учебные занятия',
            'participant_age_from' => 7, 'participant_age_to' => 10];
        if ($teacher) {
            $profile = $user->teacherProfile()->create(['surname' => 'Петров', 'city_id' => $city->id,
                'public_profile_requested' => false, 'public_profile_status' => TeacherProfileVisibilityStatus::PRIVATE]);
            $profile->subjects()->sync([$subject->id]);
            $payload['lesson_type'] = 'individual';
        } else {
            $family = Family::factory()->create(['user_id' => $user->id, 'city_id' => $city->id]);
            $payload['child_ids'] = [Child::factory()->create(['family_id' => $family->id])->id];
        }
        if ($type !== AdvertisementType::FAMILY_TO_FAMILY) {
            $payload['subject_ids'] = [$subject->id];
            $payload['format'] = 'online';
        }

        return [$user, $payload];
    }
}
