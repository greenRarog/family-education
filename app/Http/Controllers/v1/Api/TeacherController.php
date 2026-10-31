<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementType;
use App\Enums\TeacherProfileVisibilityStatus;
use App\Enums\UserType;
use App\Exceptions\EmailVerificationRequired;
use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\MetroStation;
use App\Models\TeacherProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TeacherController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->user_type === UserType::TEACHER, 403);

        return response()->json([
            'teacher' => $this->privateProfile(
                $user->teacherProfile()->with(['city', 'district', 'metroStation', 'subjects'])->firstOrFail()
            ),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->user_type === UserType::TEACHER, 403);

        /** @var TeacherProfile $profile */
        $profile = $user->teacherProfile()->firstOrFail();

        $data = $request->validate([
            'surname' => ['required', 'string', 'max:255'],
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'metro_station_id' => ['nullable', 'integer', 'exists:metro_stations,id'],
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['required', 'integer', 'distinct', 'exists:subjects,id'],
            'about' => ['nullable', 'string', 'max:3000'],
            'education' => ['nullable', 'string', 'max:3000'],
            'public_profile_requested' => ['required', 'boolean'],
        ]);

        if ($data['public_profile_requested'] && ! $user->hasVerifiedEmail()) {
            throw new EmailVerificationRequired;
        }

        $this->validateLocation($data);

        $oldSubjectIds = $profile->subjects()->orderBy('subjects.id')->pluck('subjects.id')->all();
        $newSubjectIds = collect($data['subject_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $publicFieldsChanged = $profile->surname !== $data['surname']
            || $profile->city_id !== (int) $data['city_id']
            || $oldSubjectIds !== $newSubjectIds;

        $requested = (bool) $data['public_profile_requested'];
        $status = $profile->public_profile_status;
        $reviewedAt = $profile->public_profile_reviewed_at;
        $reviewedBy = $profile->public_profile_reviewed_by;

        if (! $requested) {
            $status = TeacherProfileVisibilityStatus::PRIVATE;
            $reviewedAt = null;
            $reviewedBy = null;
        } elseif ($publicFieldsChanged || $status === TeacherProfileVisibilityStatus::PRIVATE || $status === TeacherProfileVisibilityStatus::REJECTED) {
            $status = TeacherProfileVisibilityStatus::PENDING;
            $reviewedAt = null;
            $reviewedBy = null;
        }

        $profile->fill([
            'surname' => $data['surname'],
            'city_id' => $data['city_id'],
            'district_id' => $data['district_id'] ?? null,
            'metro_station_id' => $data['metro_station_id'] ?? null,
            'about' => $data['about'] ?? null,
            'education' => $data['education'] ?? null,
            'public_profile_requested' => $requested,
            'public_profile_status' => $status,
            'public_profile_reviewed_at' => $reviewedAt,
            'public_profile_reviewed_by' => $reviewedBy,
        ]);
        $profile->save();
        $profile->subjects()->sync($newSubjectIds);

        return response()->json([
            'teacher' => $this->privateProfile(
                $profile->fresh()->load(['user', 'city', 'district', 'metroStation', 'subjects'])
            ),
        ]);
    }

    public function publicProfile(Request $request, User $user): JsonResponse
    {
        abort_unless($user->user_type === UserType::TEACHER, 404);

        /** @var TeacherProfile|null $profile */
        $profile = $user->teacherProfile()
            ->with(['city', 'subjects'])
            ->first();
        abort_if($profile === null, 404);

        $isOwner = $request->user()?->id === $user->id;
        abort_if($user->isBlocked() && ! $isOwner, 404);
        abort_unless(
            $isOwner || (
                $profile->public_profile_requested
                && $profile->public_profile_status === TeacherProfileVisibilityStatus::APPROVED
            ),
            404
        );

        $advertisements = $user->advertisements()
            ->where('type', AdvertisementType::TEACHER_TO_SERVICE)
            ->where('status', AdvertisementStatus::PUBLISHED)
            ->whereNull('hide_action_id')
            ->with(['city', 'subjects'])
            ->latest('published_at')
            ->get()
            ->map(fn ($advertisement) => [
                'id' => $advertisement->id,
                'description' => $advertisement->description,
                'city' => $advertisement->city,
                'subjects' => $advertisement->subjects,
                'format' => $advertisement->format,
                'lesson_type' => $advertisement->lesson_type,
                'participant_age_from' => $advertisement->participant_age_from,
                'participant_age_to' => $advertisement->participant_age_to,
                'published_at' => $advertisement->published_at,
                'language' => $advertisement->language,
            ]);

        return response()->json([
            'teacher' => [
                'id' => $user->id,
                'name' => $user->name,
                'surname' => $profile->surname,
                'city' => $profile->city,
                'subjects' => $profile->subjects,
                'public_profile_status' => $profile->public_profile_status,
            ],
            'advertisements' => $advertisements,
        ]);
    }

    /** @return array<string, mixed> */
    private function privateProfile(TeacherProfile $profile): array
    {
        return [
            'user_id' => $profile->user_id,
            'name' => $profile->user->name,
            'surname' => $profile->surname,
            'city_id' => $profile->city_id,
            'district_id' => $profile->district_id,
            'metro_station_id' => $profile->metro_station_id,
            'city' => $profile->city,
            'district' => $profile->district,
            'metro_station' => $profile->metroStation,
            'subjects' => $profile->subjects,
            'about' => $profile->about,
            'education' => $profile->education,
            'public_profile_requested' => $profile->public_profile_requested,
            'public_profile_status' => $profile->public_profile_status,
            'public_profile_reviewed_at' => $profile->public_profile_reviewed_at,
        ];
    }

    /**
     * @param  array{city_id: int|string, district_id?: int|string|null, metro_station_id?: int|string|null}  $data
     *
     * @throws ValidationException
     */
    private function validateLocation(array $data): void
    {
        if (! empty($data['district_id']) && ! District::query()
            ->whereKey($data['district_id'])
            ->where('city_id', $data['city_id'])
            ->exists()) {
            throw ValidationException::withMessages([
                'district_id' => 'Выбранный район не относится к выбранному городу.',
            ]);
        }

        if (! empty($data['metro_station_id']) && ! MetroStation::query()
            ->whereKey($data['metro_station_id'])
            ->where('city_id', $data['city_id'])
            ->exists()) {
            throw ValidationException::withMessages([
                'metro_station_id' => 'Выбранная станция метро не относится к выбранному городу.',
            ]);
        }
    }
}
