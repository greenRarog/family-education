<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Models\Advertisement;
use App\Models\Child;
use App\Models\District;
use App\Models\Family;
use App\Models\MetroStation;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicAdvertisementTest extends TestCase
{
    use RefreshDatabase;

    public static function familyAdvertisementTypes(): array
    {
        return [
            'family to family' => [AdvertisementType::FAMILY_TO_FAMILY],
            'family to teacher' => [AdvertisementType::FAMILY_TO_TEACHER],
        ];
    }

    #[DataProvider('familyAdvertisementTypes')]
    public function test_public_feed_and_detail_never_expose_children_even_to_the_owner(AdvertisementType $type): void
    {
        $family = Family::factory()->create();
        $child = Child::factory()->create([
            'family_id' => $family->id,
            'name' => 'Private child name',
            'birth_date' => '2016-04-15',
        ]);
        $district = District::factory()->create(['city_id' => $family->city_id]);
        $metroStation = MetroStation::factory()->create(['city_id' => $family->city_id]);
        $subject = Subject::factory()->create();
        $advertisement = Advertisement::factory()->create([
            'user_id' => $family->user_id,
            'type' => $type,
            'status' => AdvertisementStatus::PUBLISHED,
            'city_id' => $family->city_id,
            'district_id' => $district->id,
            'metro_station_id' => $metroStation->id,
        ]);
        $advertisement->children()->attach($child);
        $advertisement->subjects()->attach($subject);

        // Guest, unrelated user, and owner all receive the same public field whitelist.
        foreach ([null, User::factory()->create(), $family->user] as $viewer) {
            if ($viewer !== null) {
                $this->actingAs($viewer);
            }
            foreach (['/api/advertisements/feed', "/api/advertisements/{$advertisement->id}"] as $url) {
                $response = $this->getJson($url)->assertOk();
                $data = $response->json(str_ends_with($url, '/feed') ? 'advertisements.data.0' : 'advertisement');
                $this->assertSame($advertisement->id, $data['id']);
                $this->assertSame($type->value, $data['type']);
                $this->assertEqualsCanonicalizing([
                    'id', 'type', 'status', 'description', 'language', 'format', 'lesson_type',
                    'participant_age_from', 'participant_age_to', 'published_at', 'closed_at',
                    'city', 'district', 'metro_station', 'subjects', 'public_teacher',
                ], array_keys($data));
                foreach (['city' => $family->city, 'district' => $district, 'metro_station' => $metroStation] as $key => $location) {
                    $this->assertSame(['id' => $location->id, 'name' => $location->name], $data[$key]);
                }
                $this->assertSame([['id' => $subject->id, 'name' => $subject->name]], $data['subjects']);
                $this->assertNull($data['public_teacher']);
                $this->assertStringNotContainsString('Private child name', $response->getContent());
                $this->assertStringNotContainsString('2016-04-15', $response->getContent());
                foreach (['children', 'child_ids', 'child_id', 'user_id', 'family_id', 'birth_date', 'sex', 'pivot'] as $field) {
                    $this->assertStringNotContainsString('"'.$field.'":', $response->getContent());
                }
                if (! str_ends_with($url, '/feed')) {
                    $response->assertJsonPath('viewer.authenticated', $viewer !== null)
                        ->assertJsonPath('viewer.is_owner', $viewer?->id === $family->user_id);
                }
            }
        }
    }

    public static function editableFamilyAdvertisements(): array
    {
        return [
            'family to family draft' => [AdvertisementType::FAMILY_TO_FAMILY, AdvertisementStatus::DRAFT],
            'family to family published' => [AdvertisementType::FAMILY_TO_FAMILY, AdvertisementStatus::PUBLISHED],
            'family to teacher draft' => [AdvertisementType::FAMILY_TO_TEACHER, AdvertisementStatus::DRAFT],
            'family to teacher published' => [AdvertisementType::FAMILY_TO_TEACHER, AdvertisementStatus::PUBLISHED],
        ];
    }

    #[DataProvider('editableFamilyAdvertisements')]
    public function test_only_the_owner_can_load_children_for_editing(AdvertisementType $type, AdvertisementStatus $status): void
    {
        $family = Family::factory()->create();
        $child = Child::factory()->create([
            'family_id' => $family->id,
            'name' => 'Private child name',
            'birth_date' => '2016-04-15',
        ]);
        Child::factory()->create(['family_id' => $family->id]);
        $advertisement = Advertisement::factory()->create([
            'user_id' => $family->user_id,
            'type' => $type,
            'status' => $status,
        ]);
        $advertisement->children()->attach($child);
        $url = "/api/advertisements/{$advertisement->id}/edit";

        $this->getJson($url)->assertUnauthorized()->assertJsonMissingPath('advertisement');
        $this->actingAs(User::factory()->create())->getJson($url)
            ->assertNotFound()->assertJsonMissingPath('advertisement');

        $this->actingAs($family->user)->getJson($url)
            ->assertOk()
            ->assertJsonPath('advertisement.id', $advertisement->id)
            ->assertJsonCount(1, 'advertisement.children')
            ->assertJsonPath('advertisement.children.0.id', $child->id)
            ->assertJsonPath('advertisement.children.0.name', 'Private child name')
            ->assertJsonPath('advertisement.children.0.birth_date', '2016-04-15T00:00:00.000000Z');
    }

    public function test_age_filter_includes_unrestricted_services_and_both_range_boundaries(): void
    {
        $unrestricted = Advertisement::factory()->create([
            'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'status' => AdvertisementStatus::PUBLISHED,
            'participant_age_from' => null,
            'participant_age_to' => null,
        ]);
        $restricted = Advertisement::factory()->create([
            'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'status' => AdvertisementStatus::PUBLISHED,
            'participant_age_from' => 7,
            'participant_age_to' => 10,
        ]);
        $draft = Advertisement::factory()->create([
            'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'participant_age_from' => null,
            'participant_age_to' => null,
        ]);
        $closed = Advertisement::factory()->create([
            'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'status' => AdvertisementStatus::CLOSED,
            'participant_age_from' => null,
            'participant_age_to' => null,
        ]);
        $familyAdvertisements = [];
        foreach ([AdvertisementType::FAMILY_TO_FAMILY, AdvertisementType::FAMILY_TO_TEACHER] as $type) {
            $familyAdvertisements[] = Advertisement::factory()->create([
                'type' => $type,
                'status' => AdvertisementStatus::PUBLISHED,
                'participant_age_from' => 7,
                'participant_age_to' => 10,
            ]);
        }
        foreach ([0, 6, 7, 10, 11, 100] as $age) {
            $response = $this->getJson("/api/advertisements/feed?age={$age}")->assertOk();
            $ids = array_column($response->json('advertisements.data'), 'id');
            $this->assertContains($unrestricted->id, $ids);
            $this->assertNotContains($draft->id, $ids);
            $this->assertNotContains($closed->id, $ids);
            $this->assertSame($age >= 7 && $age <= 10, in_array($restricted->id, $ids, true));
            foreach ($familyAdvertisements as $familyAdvertisement) {
                $this->assertSame($age >= 7 && $age <= 10, in_array($familyAdvertisement->id, $ids, true));
            }
        }
        foreach (['', '?age='] as $query) {
            $this->getJson('/api/advertisements/feed'.$query)
                ->assertOk()->assertJsonCount(4, 'advertisements.data');
        }
    }

    public function test_age_filter_only_treats_services_with_both_null_bounds_as_unrestricted(): void
    {
        foreach ([
            [AdvertisementType::FAMILY_TO_FAMILY, null, null],
            [AdvertisementType::FAMILY_TO_TEACHER, null, null],
            [AdvertisementType::TEACHER_TO_SERVICE, 7, null],
            [AdvertisementType::TEACHER_TO_SERVICE, null, 10],
        ] as [$type, $from, $to]) {
            Advertisement::factory()->create([
                'type' => $type,
                'status' => AdvertisementStatus::PUBLISHED,
                'participant_age_from' => $from,
                'participant_age_to' => $to,
            ]);
        }

        $this->getJson('/api/advertisements/feed?age=8')
            ->assertOk()->assertJsonCount(0, 'advertisements.data');
    }

    public function test_age_filter_does_not_let_unrestricted_services_bypass_other_filters(): void
    {
        $matching = Advertisement::factory()->create([
            'type' => AdvertisementType::TEACHER_TO_SERVICE,
            'status' => AdvertisementStatus::PUBLISHED,
            'participant_age_from' => null,
            'participant_age_to' => null,
            'format' => 'online',
            'language' => 'ru',
        ]);
        $subject = Subject::factory()->create();
        $matching->subjects()->attach($subject);

        foreach (['city_id' => null, 'format' => 'offline', 'language' => 'en', 'subject' => null] as $field => $value) {
            $attributes = [
                'type' => AdvertisementType::TEACHER_TO_SERVICE,
                'status' => AdvertisementStatus::PUBLISHED,
                'participant_age_from' => null,
                'participant_age_to' => null,
                'city_id' => $matching->city_id,
                'format' => 'online',
                'language' => 'ru',
            ];
            if ($field === 'city_id') {
                unset($attributes['city_id']); // The factory supplies a different city.
            } elseif ($field !== 'subject') {
                $attributes[$field] = $value;
            }
            $other = Advertisement::factory()->create($attributes);
            if ($field !== 'subject') {
                $other->subjects()->attach($subject);
            }
        }

        $query = http_build_query([
            'age' => 8,
            'type' => 'teacher_to_service',
            'city_id' => $matching->city_id,
            'format' => 'online',
            'language' => 'ru',
            'subject_id' => $subject->id,
        ]);
        $this->getJson('/api/advertisements/feed?'.$query)
            ->assertOk()->assertJsonCount(1, 'advertisements.data')
            ->assertJsonPath('advertisements.data.0.id', $matching->id);
        $this->getJson('/api/advertisements/feed?age=8&type=family_to_family')
            ->assertOk()->assertJsonCount(0, 'advertisements.data');
    }

    public function test_age_filter_rejects_values_outside_its_integer_domain(): void
    {
        foreach (['-1', '101', '7.5', 'abc'] as $age) {
            $this->getJson('/api/advertisements/feed?age='.$age)
                ->assertUnprocessable()->assertJsonValidationErrors('age');
        }
    }
}
