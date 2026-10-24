<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\TeacherProfileVisibilityStatus;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\City;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\TurnstileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeacherWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_can_register_without_a_family_or_children(): void
    {
        $city = City::factory()->create();
        $subjects = Subject::factory()->count(2)->create();
        $this->mock(TurnstileService::class, function ($mock) {
            $mock->shouldReceive('verify')->once()->with('turnstile-token')->andReturn(true);
        });

        $this->postJson('/register', [
            'cf-turnstile-response' => 'turnstile-token',
            'user_type' => UserType::TEACHER->value,
            'language' => 'es',
            'name' => 'Анна',
            'email' => 'teacher@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'surname' => 'Петрова',
            'city_id' => $city->id,
            'subject_ids' => $subjects->pluck('id')->all(),
            'about' => 'Опыт работы',
            'education' => 'Педагогическое образование',
        ])->assertSuccessful();

        $user = User::query()->where('email', 'teacher@example.com')->firstOrFail();
        $this->assertSame(UserType::TEACHER, $user->user_type);
        $this->assertSame('es', $user->locale);
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseMissing('families', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('children', ['family_id' => $user->id]);
        $this->assertDatabaseHas('teacher_profiles', [
            'user_id' => $user->id,
            'surname' => 'Петрова',
            'public_profile_requested' => false,
            'public_profile_status' => TeacherProfileVisibilityStatus::PRIVATE->value,
        ]);
        $this->assertCount(2, $user->teacherProfile->subjects);
    }

    public function test_teacher_registration_requires_subjects_and_turnstile(): void
    {
        $city = City::factory()->create();
        $this->mock(TurnstileService::class, function ($mock) {
            $mock->shouldReceive('verify')->never();
        });

        $this->postJson('/register', [
            'cf-turnstile-response' => 'turnstile-token',
            'user_type' => UserType::TEACHER->value,
            'name' => 'Анна',
            'email' => 'teacher@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'surname' => 'Петрова',
            'city_id' => $city->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['subject_ids']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_teacher_can_update_profile_and_request_publication(): void
    {
        [$teacher, $profile, $city, $subjects] = $this->teacherWithProfile();
        $newCity = City::factory()->create();
        $newSubject = Subject::factory()->create();

        $this->actingAs($teacher)->putJson('/api/teacher-profile', [
            'surname' => 'Иванова',
            'city_id' => $newCity->id,
            'subject_ids' => [$subjects[0]->id, $newSubject->id],
            'about' => 'Обновлённое описание',
            'education' => 'Квалификация',
            'public_profile_requested' => true,
        ])->assertOk()
            ->assertJsonPath('teacher.public_profile_status', TeacherProfileVisibilityStatus::PENDING->value)
            ->assertJsonPath('teacher.public_profile_requested', true);

        $this->assertDatabaseHas('teacher_profiles', [
            'id' => $profile->id,
            'city_id' => $newCity->id,
            'public_profile_status' => TeacherProfileVisibilityStatus::PENDING->value,
        ]);
        $this->assertCount(2, $profile->fresh()->subjects);
    }

    public function test_public_teacher_page_is_hidden_until_admin_approval_and_exposes_only_public_fields(): void
    {
        [$teacher, $profile, $city, $subjects] = $this->teacherWithProfile([
            'public_profile_requested' => true,
            'public_profile_status' => TeacherProfileVisibilityStatus::PENDING,
            'about' => 'Private biography',
            'education' => 'Private education details',
        ]);
        $ad = $this->serviceAdvertisement($teacher, $city, $subjects[0]);
        $ad->update(['status' => AdvertisementStatus::PUBLISHED, 'published_at' => now()]);

        $this->getJson("/api/teachers/{$teacher->id}")->assertNotFound();

        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->actingAs($admin)->getJson('/api/admin/teacher-profiles')
            ->assertOk()->assertJsonPath('profiles.0.user_id', $teacher->id);
        $this->actingAs($admin)->postJson("/api/admin/teacher-profiles/{$teacher->id}/approve")
            ->assertOk()->assertJsonPath('status', TeacherProfileVisibilityStatus::APPROVED->value);

        $this->getJson("/api/teachers/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath('teacher.name', $teacher->name)
            ->assertJsonPath('teacher.surname', $profile->surname)
            ->assertJsonPath('advertisements.0.id', $ad->id)
            ->assertJsonMissingPath('teacher.email')
            ->assertJsonMissingPath('teacher.about')
            ->assertJsonMissingPath('teacher.education');
    }

    public function test_non_admin_cannot_review_public_teacher_profiles(): void
    {
        [$teacher] = $this->teacherWithProfile([
            'public_profile_requested' => true,
            'public_profile_status' => TeacherProfileVisibilityStatus::PENDING,
        ]);

        $this->actingAs(User::factory()->create())->getJson('/api/admin/teacher-profiles')->assertForbidden();
        $this->actingAs(User::factory()->create())->postJson("/api/admin/teacher-profiles/{$teacher->id}/approve")->assertForbidden();
    }

    public function test_admin_can_reject_public_profile_and_teacher_can_request_another_review(): void
    {
        [$teacher, $profile] = $this->teacherWithProfile([
            'public_profile_requested' => true,
            'public_profile_status' => TeacherProfileVisibilityStatus::PENDING,
        ]);
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);

        $this->actingAs($admin)->postJson("/api/admin/teacher-profiles/{$teacher->id}/reject")
            ->assertOk()->assertJsonPath('status', TeacherProfileVisibilityStatus::REJECTED->value);
        $this->getJson("/api/teachers/{$teacher->id}")->assertNotFound();

        $this->actingAs($teacher)->putJson('/api/teacher-profile', [
            'surname' => $profile->surname,
            'city_id' => $profile->city_id,
            'subject_ids' => $profile->subjects->pluck('id')->all(),
            'public_profile_requested' => true,
        ])->assertOk()->assertJsonPath('teacher.public_profile_status', TeacherProfileVisibilityStatus::PENDING->value);
    }

    public function test_teacher_can_make_an_approved_profile_private(): void
    {
        [$teacher, $profile] = $this->teacherWithProfile([
            'public_profile_requested' => true,
            'public_profile_status' => TeacherProfileVisibilityStatus::APPROVED,
        ]);
        $this->getJson("/api/teachers/{$teacher->id}")->assertOk();

        $this->actingAs($teacher)->putJson('/api/teacher-profile', [
            'surname' => $profile->surname,
            'city_id' => $profile->city_id,
            'subject_ids' => $profile->subjects->pluck('id')->all(),
            'public_profile_requested' => false,
        ])->assertOk()->assertJsonPath('teacher.public_profile_status', TeacherProfileVisibilityStatus::PRIVATE->value);

        auth()->logout();
        $this->getJson("/api/teachers/{$teacher->id}")->assertNotFound();
    }

    public function test_editing_public_identity_fields_requires_approval_again(): void
    {
        [$teacher, $profile] = $this->teacherWithProfile([
            'public_profile_requested' => true,
            'public_profile_status' => TeacherProfileVisibilityStatus::APPROVED,
        ]);
        $this->getJson("/api/teachers/{$teacher->id}")->assertOk();

        $this->actingAs($teacher)->putJson('/api/teacher-profile', [
            'surname' => 'Другая',
            'city_id' => $profile->city_id,
            'subject_ids' => $profile->subjects->pluck('id')->all(),
            'public_profile_requested' => true,
        ])->assertOk()->assertJsonPath('teacher.public_profile_status', TeacherProfileVisibilityStatus::PENDING->value);

        auth()->logout();
        $this->getJson("/api/teachers/{$teacher->id}")->assertNotFound();
    }

    public function test_teacher_can_create_and_publish_service_ad_with_optional_age_and_no_children(): void
    {
        [$teacher, , $city, $subjects] = $this->teacherWithProfile();
        $payload = $this->servicePayload($city, $subjects[0]);
        unset($payload['participant_age_from'], $payload['participant_age_to']);

        $response = $this->actingAs($teacher)->postJson('/api/advertisements', $payload)
            ->assertCreated()
            ->assertJsonPath('advertisement.type', AdvertisementType::TEACHER_TO_SERVICE->value)
            ->assertJsonPath('advertisement.participant_age_from', null)
            ->assertJsonPath('advertisement.participant_age_to', null)
            ->assertJsonCount(0, 'advertisement.children');
        $advertisementId = $response->json('advertisement.id');

        $this->postJson("/api/advertisements/{$advertisementId}/publish")->assertOk();
        $this->getJson('/api/advertisements/feed?type=teacher_to_service')
            ->assertOk()->assertJsonPath('advertisements.data.0.id', $advertisementId);
        $this->assertDatabaseHas('advertisements', [
            'id' => $advertisementId,
            'user_id' => $teacher->id,
            'participant_age_from' => null,
            'participant_age_to' => null,
        ]);
    }

    public function test_teacher_service_ad_requires_profile_subjects_and_does_not_accept_price_or_family_fields(): void
    {
        [$teacher, , $city, $subjects] = $this->teacherWithProfile();
        $otherSubject = Subject::factory()->create();
        $payload = $this->servicePayload($city, $otherSubject);

        $this->actingAs($teacher)->postJson('/api/advertisements', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['subject_ids']);

        $payload = $this->servicePayload($city, $subjects[0]);
        $payload['lesson_type'] = null;
        $this->postJson('/api/advertisements', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['lesson_type']);

        $payload = $this->servicePayload($city, $subjects[0]);
        $payload['child_ids'] = [1];
        $this->postJson('/api/advertisements', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors(['child_ids']);
    }

    public function test_teacher_can_edit_own_service_ad_but_cannot_edit_another_teachers_ad(): void
    {
        [$teacher, , $city, $subjects] = $this->teacherWithProfile();
        $ad = $this->serviceAdvertisement($teacher, $city, $subjects[0]);
        $payload = $this->servicePayload($city, $subjects[1]);
        $payload['description'] = 'Обновлённое описание занятия.';

        $this->actingAs($teacher)->putJson("/api/advertisements/{$ad->id}", $payload)
            ->assertOk()->assertJsonPath('advertisement.description', $payload['description'])
            ->assertJsonPath('advertisement.lesson_type', 'individual');
        $this->assertDatabaseHas('advertisement_subject', [
            'advertisement_id' => $ad->id,
            'subject_id' => $subjects[1]->id,
        ]);

        $anotherTeacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $this->actingAs($anotherTeacher)->putJson("/api/advertisements/{$ad->id}", $payload)->assertForbidden();
    }

    public function test_family_cannot_create_teacher_service_ad_and_teacher_cannot_create_family_ad(): void
    {
        [$teacher, , $city, $subjects] = $this->teacherWithProfile();
        $this->actingAs($teacher)->postJson('/api/advertisements', $this->servicePayload($city, $subjects[0], AdvertisementType::FAMILY_TO_FAMILY->value))->assertForbidden();

        $family = User::factory()->create(['user_type' => UserType::FAMILY]);
        $this->actingAs($family)->postJson('/api/advertisements', $this->servicePayload($city, $subjects[0]))->assertForbidden();
    }

    /** @return array{User, TeacherProfile, City, array<int, Subject>} */
    private function teacherWithProfile(array $profileValues = []): array
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        $city = City::factory()->create();
        $subjects = Subject::factory()->count(2)->create()->all();
        $profile = TeacherProfile::query()->create(array_merge([
            'user_id' => $teacher->id,
            'surname' => 'Петрова',
            'city_id' => $city->id,
            'about' => 'О себе',
            'education' => 'Образование',
            'public_profile_requested' => false,
            'public_profile_status' => TeacherProfileVisibilityStatus::PRIVATE,
        ], $profileValues));
        $profile->subjects()->sync(collect($subjects)->pluck('id'));

        return [$teacher, $profile->load('subjects'), $city, $subjects];
    }

    private function serviceAdvertisement(User $teacher, City $city, Subject $subject): Advertisement
    {
        $advertisement = Advertisement::factory()->create([
            'user_id' => $teacher->id,
            'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'city_id' => $city->id,
            'format' => 'online',
            'lesson_type' => 'individual',
            'participant_age_from' => null,
            'participant_age_to' => null,
        ]);
        $advertisement->subjects()->sync([$subject->id]);

        return $advertisement;
    }

    private function servicePayload(City $city, Subject $subject, string $type = 'teacher_to_service'): array
    {
        return [
            'type' => $type,
            'subject_ids' => [$subject->id],
            'format' => 'online',
            'lesson_type' => 'individual',
            'city_id' => $city->id,
            'participant_age_from' => 6,
            'participant_age_to' => 12,
            'description' => 'Занятия по выбранному предмету.',
        ];
    }
}
