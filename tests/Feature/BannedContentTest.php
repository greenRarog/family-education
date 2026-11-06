<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\BannedWord;
use App\Models\Child;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Report;
use App\Models\Subject;
use App\Models\TeacherProfile;
use App\Models\User;
use App\Services\BannedWordChecker;
use App\Services\TurnstileService;
use Database\Seeders\BannedWordSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BannedContentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        BannedWord::create(['word' => 'blockedterm']);
    }

    public function test_seeded_dictionary_handles_languages_case_accents_and_word_boundaries(): void
    {
        $this->seed(BannedWordSeeder::class);
        $before = BannedWord::orderBy('id')->pluck('word', 'id')->all();
        $this->seed(BannedWordSeeder::class);
        $this->assertSame($before, BannedWord::orderBy('id')->pluck('word', 'id')->all());
        $checker = app(BannedWordChecker::class);
        foreach (['FUCK!', 'FuCk', 'ASSHOLES', '¡CABRÓN!', 'cabron', "cabro\u{0301}n", 'GILIPOLLAS', 'БЛЯДЬ', 'БлЯдЬ'] as $text) {
            $this->assertTrue($checker->containsBannedWord($text), $text);
        }
        foreach (['Scunthorpe', 'Essex', 'Dickinson', 'Class assignment about biology and sex education.',
            'Clases de biología: reproducción, anatomía, cono y huevos.', 'Málaga, München, Łódź, Niš',
            'blockedterms', 'preblockedterm', "blockedterm\u{0301}"] as $text) {
            $this->assertFalse($checker->containsBannedWord($text), $text);
        }
        // Administrators can add phrases; matching must not interpret regex syntax.
        BannedWord::create(['word' => 'bad phrase']);
        BannedWord::create(['word' => 'bad.word']);
        $this->assertTrue($checker->containsBannedWord('A BAD PHRASE!'));
        $this->assertFalse($checker->containsBannedWord('badXword'));
        $this->assertTrue($checker->containsBannedWord('bad.word'));
        BannedWord::where('word', 'bad.word')->delete();
        $this->assertFalse($checker->containsBannedWord('bad.word'));
    }

    public static function registrationFields(): array
    {
        return [['family', 'name'], ['family', 'surname'], ['family', 'children.0.name'],
            ['teacher', 'name'], ['teacher', 'surname'], ['teacher', 'about'], ['teacher', 'education']];
    }

    #[DataProvider('registrationFields')]
    public function test_registration_rejects_blocked_content_before_creating_any_records(string $role, string $field): void
    {
        $this->mock(TurnstileService::class)->shouldNotReceive('verify');
        $payload = $this->registration($role);
        data_set($payload, $field, 'BlOcKeDtErM');
        $this->postJson('/register', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        foreach (['users', 'families', 'children', 'teacher_profiles'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_credentials_are_not_filtered_and_valid_registration_still_works(): void
    {
        $this->mock(TurnstileService::class)->shouldReceive('verify')->once()->andReturn(true);
        $payload = $this->registration('family');
        $payload['email'] = 'blockedterm@example.com';
        $payload['password'] = $payload['password_confirmation'] = 'blockedterm!123';
        $this->postJson('/register', $payload)->assertSuccessful();
        $this->assertDatabaseHas('users', ['email' => 'blockedterm@example.com']);
    }

    public function test_account_name_update_cannot_bypass_filter(): void
    {
        $user = User::factory()->create(['name' => 'Original']);
        // Fortify profile editing is disabled in current routes; protect its action as well.
        try {
            app(UpdateUserProfileInformation::class)->update($user, ['name' => 'BLOCKEDTERM', 'email' => $user->email]);
            $this->fail('Blocked account name must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('name', $error->errors());
        }
        $this->assertSame('Original', $user->fresh()->name);
    }

    public static function familyFields(): array
    {
        return [['surname'], ['children.0.name']];
    }

    #[DataProvider('familyFields')]
    public function test_family_update_is_atomic_when_text_is_blocked(string $field): void
    {
        $child = Child::factory()->create(['name' => 'Original']);
        $family = $child->family;
        $payload = ['surname' => 'New surname', 'city_id' => $family->city_id,
            'children' => [['id' => $child->id, 'name' => 'New name', 'birth_date' => '2016-01-01', 'sex' => 'male']]];
        data_set($payload, $field, 'BLOCKEDTERM');
        $this->actingAs($family->user)->putJson('/api/family', $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('Original', $child->fresh()->name);
        $this->assertSame($family->surname, $family->fresh()->surname);
    }

    public static function teacherFields(): array
    {
        return [['surname'], ['about'], ['education']];
    }

    #[DataProvider('teacherFields')]
    public function test_teacher_update_rejects_blocked_text(string $field): void
    {
        $profile = $this->teacher();
        $payload = ['surname' => 'Original', 'city_id' => $profile->city_id,
            'subject_ids' => $profile->subjects->modelKeys(), 'about' => 'Good biography',
            'education' => 'Good education', 'public_profile_requested' => false];
        $payload[$field] = 'BLOCKEDTERM';
        $this->actingAs($profile->user)->putJson('/api/teacher-profile', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame('Original', $profile->fresh()->surname);
        $this->assertNull($profile->fresh()->about);
    }

    public function test_advertisement_create_update_and_old_draft_publication_are_checked(): void
    {
        $child = Child::factory()->create();
        $user = $child->family->user;
        $ad = Advertisement::factory()->create(['user_id' => $user->id, 'description' => 'Original']);
        $payload = ['type' => 'family_to_family', 'child_ids' => [$child->id], 'city_id' => $ad->city_id,
            'participant_age_from' => 7, 'participant_age_to' => 12, 'description' => 'BLOCKEDTERM'];
        $this->actingAs($user)->postJson('/api/advertisements', $payload)->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->putJson('/api/advertisements/'.$ad->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertDatabaseCount('advertisements', 1);
        $this->assertSame('Original', $ad->fresh()->description);
        BannedWord::create(['word' => 'original']);
        $this->postJson('/api/advertisements/'.$ad->id.'/publish')->assertUnprocessable()->assertJsonValidationErrors('description');
        $this->assertSame('draft', $ad->fresh()->status->value);
    }

    public function test_response_and_message_rejection_create_no_conversation_message_or_notification(): void
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $response->update(['status' => 'accepted']);
        $ad = $response->advertisement;
        $ad->update(['status' => 'published', 'published_at' => now()]);
        $newUser = User::factory()->create();
        $this->actingAs($newUser)->postJson('/api/advertisements/'.$ad->id.'/responses', ['message' => 'BLOCKEDTERM'])
            ->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('advertisement_responses', 1);
        $this->assertDatabaseCount('conversations', 1);
        $this->actingAs($response->user)->postJson('/api/conversations/'.$conversation->id.'/messages', ['message' => 'BLOCKEDTERM'])
            ->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertDatabaseCount('messages', 0);
        Notification::assertNothingSent();
        $this->postJson('/api/conversations/'.$conversation->id.'/messages', ['message' => 'A normal lesson discussion.'])->assertCreated();
    }

    public function test_report_comment_is_checked_but_original_evidence_can_be_reported(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'description' => 'BLOCKEDTERM']);
        $payload = ['target_type' => 'advertisement', 'context_type' => 'advertisement', 'context_id' => $ad->id,
            'reason' => 'abuse', 'comment' => 'BLOCKEDTERM'];
        $this->actingAs(User::factory()->create())->postJson('/api/reports', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('comment');
        $this->assertDatabaseCount('reports', 0);
        $payload['comment'] = 'This advertisement contains insults.';
        $this->postJson('/api/reports', $payload)->assertCreated();
        $this->assertDatabaseCount('reports', 1);
    }

    public function test_teacher_approval_rechecks_old_profile_against_current_dictionary(): void
    {
        $profile = $this->teacher();
        $profile->update(['public_profile_requested' => true, 'public_profile_status' => 'pending', 'about' => 'BLOCKEDTERM']);
        $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN]))
            ->postJson('/api/admin/teacher-profiles/'.$profile->user_id.'/approve')
            ->assertUnprocessable()->assertJsonValidationErrors('about');
        $this->assertSame('pending', $profile->fresh()->public_profile_status->value);
    }

    public static function dictionaryResources(): array
    {
        return [['countries'], ['cities'], ['districts'], ['metro-stations'], ['subjects']];
    }

    #[DataProvider('dictionaryResources')]
    public function test_admin_directory_names_are_checked(string $resource): void
    {
        $city = City::factory()->create();
        $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN]))
            ->postJson('/api/admin/'.$resource, ['name' => 'BLOCKEDTERM', 'code' => 'ZZ', 'country_id' => $city->country_id, 'city_id' => $city->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_admin_can_manage_the_blocked_dictionary_itself(): void
    {
        $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN]))
            ->postJson('/api/admin/blocked-terms', ['word' => 'BLOCKEDTERM'])->assertCreated();
    }

    public function test_moderation_explanations_are_checked_before_actions_are_applied(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published']);
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $this->actingAs($admin);
        $input = ['reason_code' => 'abuse', 'reason' => 'BLOCKEDTERM'];
        $this->postJson('/api/admin/advertisements/'.$ad->id.'/hide', $input)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/admin/users/'.$ad->user_id.'/block', $input)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->assertNull($ad->fresh()->hide_action_id);
        $this->assertNull($ad->user->fresh()->block_action_id);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    public function test_report_resolution_note_and_sanction_explanation_are_checked(): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published']);
        $this->actingAs(User::factory()->create())->postJson('/api/reports', [
            'target_type' => 'advertisement', 'context_type' => 'advertisement', 'context_id' => $ad->id, 'reason' => 'abuse',
        ])->assertCreated();
        $report = Report::sole();
        $this->actingAs(User::factory()->create(['user_type' => UserType::ADMIN]));
        foreach (['resolution_note', 'sanction_reason'] as $field) {
            $input = ['status' => 'resolved', 'resolution_note' => 'Review completed.', 'actions' => ['hide_advertisement'],
                'sanction_reason_code' => 'abuse', 'sanction_reason' => 'Please follow the rules.'];
            $input[$field] = 'BLOCKEDTERM';
            $this->postJson('/api/admin/reports/'.$report->id.'/resolve', $input)
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->assertSame('open', $report->fresh()->status->value);
        $this->assertNull($ad->fresh()->hide_action_id);
        $this->assertDatabaseCount('admin_actions', 0);
    }

    private function registration(string $role): array
    {
        return ['name' => 'Anna', 'surname' => 'Smith', 'email' => 'anna@example.com', 'user_type' => $role,
            'password' => 'Password123!', 'password_confirmation' => 'Password123!', 'cf-turnstile-response' => 'test-token',
            'city_id' => City::factory()->create()->id, 'subject_ids' => [Subject::factory()->create()->id],
            'about' => 'Good biography', 'education' => 'Good education',
            'children' => [['name' => 'Alex', 'birth_date' => '2016-01-01', 'sex' => 'male']]];
    }

    private function teacher(): TeacherProfile
    {
        $user = User::factory()->create(['user_type' => UserType::TEACHER]);
        $profile = TeacherProfile::create(['user_id' => $user->id, 'surname' => 'Original',
            'city_id' => City::factory()->create()->id, 'public_profile_requested' => false, 'public_profile_status' => 'private']);
        $profile->subjects()->attach(Subject::factory()->create());

        return $profile;
    }
}
