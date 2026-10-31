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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicTeacherQueryTest extends TestCase
{
    use RefreshDatabase;

    public static function teachers(): array
    {
        return ['same teacher' => [false], 'different teachers' => [true]];
    }

    #[DataProvider('teachers')]
    public function test_feed_query_count_does_not_grow_with_service_count(bool $differentTeachers): void
    {
        $this->freezeTime();
        $teacher = $this->teacher();
        $ids = [];
        $counts = [];
        foreach ([1, 5, 20] as $size) {
            while (count($ids) < $size) {
                $owner = $differentTeachers ? $this->teacher() : $teacher;
                $ids[] = $this->service($owner, ['published_at' => now()->subMinutes(count($ids))])->id;
            }
            [$result, $queries] = $this->requestQueries('/api/advertisements/feed?type=teacher_to_service');
            $result->assertOk()->assertJsonCount($size, 'advertisements.data')->assertJsonPath('advertisements.total', $size);
            $this->assertSame($ids, array_column($result->json('advertisements.data'), 'id'));
            foreach ($result->json('advertisements.data') as $ad) {
                $this->assertNotNull($ad['public_teacher']);
            }
            $counts[] = count($queries);
        }
        $last = $this->service($this->teacher(), ['published_at' => now()->subDay()]);
        [$page, $queries] = $this->requestQueries('/api/advertisements/feed?type=teacher_to_service&page=2');
        $page->assertOk()->assertJsonCount(1, 'advertisements.data')->assertJsonPath('advertisements.data.0.id', $last->id)
            ->assertJsonPath('advertisements.total', 21)->assertJsonPath('advertisements.last_page', 2);
        $counts[] = count($queries);
        $this->assertSame(array_fill(0, 4, $counts[0]), $counts, 'Queries for 1/5/20 ads and page 2: '.json_encode($counts));
        // Count, ads, ad subjects/cities and four batched teacher relations.
        $this->assertLessThanOrEqual(8, $counts[0]);
        [$empty, $queries] = $this->requestQueries('/api/advertisements/feed?type=teacher_to_service&page=3');
        $empty->assertOk()->assertJsonCount(0, 'advertisements.data');
        $this->assertLessThanOrEqual(2, count($queries));
    }

    public static function profileStates(): array
    {
        return array_map(fn ($state) => [$state], ['approved', 'private', 'pending', 'rejected', 'disabled', 'missing']);
    }

    #[DataProvider('profileStates')]
    public function test_feed_and_detail_preserve_public_teacher_contract(string $state): void
    {
        $teacher = $this->teacher($state);
        $ad = $this->service($teacher);
        $familyAd = Advertisement::factory()->create(['status' => AdvertisementStatus::PUBLISHED, 'published_at' => now()->subDay()]);
        $this->service($teacher, ['status' => AdvertisementStatus::DRAFT]);
        $this->service($teacher, ['status' => AdvertisementStatus::CLOSED]);
        $feed = $this->getJson('/api/advertisements/feed')->assertOk()->assertJsonCount(2, 'advertisements.data');
        $data = collect($feed->json('advertisements.data'))->keyBy('id');
        $detail = $this->getJson("/api/advertisements/{$ad->id}")->assertOk()->json('advertisement');
        $this->assertSame($data[$ad->id], $detail);
        $this->assertNull($data[$familyAd->id]['public_teacher']);
        $this->assertEqualsCanonicalizing([
            'id', 'type', 'status', 'description', 'language', 'format', 'lesson_type',
            'participant_age_from', 'participant_age_to', 'published_at', 'closed_at',
            'city', 'district', 'metro_station', 'subjects', 'public_teacher',
        ], array_keys($detail));
        if ($state === 'approved') {
            $profile = $teacher->teacherProfile;
            $this->assertSame([
                'id' => $teacher->id, 'name' => $teacher->name, 'surname' => $profile->surname,
                'city' => $profile->city->only(['id', 'name']),
                'subjects' => $profile->subjects->map->only(['id', 'name'])->all(),
            ], $detail['public_teacher']);
        } else {
            $this->assertNull($detail['public_teacher']);
        }
        foreach (['email', 'password', 'user_id', 'teacher_profile', 'about', 'education', 'pivot', 'children'] as $key) {
            $this->assertStringNotContainsString('"'.$key.'":', $feed->getContent());
        }
        $this->assertStringNotContainsString('Private biography', $feed->getContent());
    }

    public function test_family_only_feed_does_not_load_teacher_relations(): void
    {
        Advertisement::factory()->count(3)->create(['status' => AdvertisementStatus::PUBLISHED]);
        [$result, $queries] = $this->requestQueries('/api/advertisements/feed');
        $result->assertOk()->assertJsonCount(3, 'advertisements.data');
        foreach ($queries as $query) {
            // The moderation EXISTS subquery is required; loading user models is not.
            $this->assertDoesNotMatchRegularExpression('/^select [^(]* from ["`]users["`]/i', $query['query']);
            $this->assertDoesNotMatchRegularExpression('/\b(teacher_profiles|teacher_profile_subject)\b/i', $query['query']);
        }
    }

    public function test_creating_service_does_not_serialize_new_user_relations(): void
    {
        $teacher = $this->teacher();
        $profile = $teacher->teacherProfile;
        $this->actingAs($teacher)->postJson('/api/advertisements', [
            'type' => 'teacher_to_service', 'city_id' => $profile->city_id,
            'subject_ids' => $profile->subjects->pluck('id')->all(),
            'format' => 'online', 'language' => 'ru', 'lesson_type' => 'individual',
            'description' => 'Занятия по математике.',
        ])->assertCreated()->assertJsonPath('advertisement.public_teacher.name', $teacher->name)
            ->assertJsonMissingPath('advertisement.user')->assertJsonMissingPath('advertisement.teacher_profile');
    }

    private function teacher(string $state = 'approved'): User
    {
        $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
        if ($state !== 'missing') {
            $profile = TeacherProfile::query()->create([
                'user_id' => $teacher->id, 'surname' => 'Петрова', 'city_id' => City::factory()->create()->id,
                'public_profile_requested' => $state !== 'disabled',
                'public_profile_status' => $state === 'disabled' ? TeacherProfileVisibilityStatus::APPROVED : TeacherProfileVisibilityStatus::from($state),
                'about' => 'Private biography', 'education' => 'Private education',
            ]);
            $profile->subjects()->attach(Subject::factory()->create());
        }

        return $teacher;
    }

    private function service(User $teacher, array $attributes = []): Advertisement
    {
        $ad = Advertisement::factory()->create(array_merge([
            'user_id' => $teacher->id, 'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'status' => AdvertisementStatus::PUBLISHED, 'published_at' => now(),
            'format' => 'online', 'language' => 'ru', 'lesson_type' => 'individual',
        ], $attributes));
        $ad->subjects()->attach(Subject::factory()->create());

        return $ad;
    }

    /** @return array{TestResponse, array} */
    private function requestQueries(string $url): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->getJson($url);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return [$response, $queries];
    }
}
