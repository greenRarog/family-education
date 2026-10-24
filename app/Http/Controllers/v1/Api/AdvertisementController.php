<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementStudyFormat;
use App\Enums\AdvertisementType;
use App\Enums\LessonType;
use App\Enums\SupportedLanguage;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\District;
use App\Models\MetroStation;
use App\Services\BannedWordChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdvertisementController extends Controller
{
    public function __construct(
        private readonly BannedWordChecker $bannedWordChecker,
    ) {}

    public function feed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::enum(AdvertisementType::class)],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'age' => ['nullable', 'integer', 'min:0', 'max:100'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'format' => ['nullable', Rule::enum(AdvertisementStudyFormat::class)],
            'language' => ['nullable', Rule::enum(SupportedLanguage::class)],
        ]);

        $query = Advertisement::query()
            ->where('status', AdvertisementStatus::PUBLISHED)
            ->with($this->relations());

        if (! empty($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        if (! empty($validated['format'])) {
            $query->where('format', $validated['format']);
        }

        if (! empty($validated['language'])) {
            $query->where('language', $validated['language']);
        }

        if (! empty($validated['city_id'])) {
            $query->where('city_id', $validated['city_id']);
        }

        if (isset($validated['age'])) {
            $age = $validated['age'];

            $query->where('participant_age_from', '<=', $age)
                ->where('participant_age_to', '>=', $age);
        }

        if (! empty($validated['subject_id'])) {
            $query->whereHas('subjects', function ($query) use ($validated) {
                $query->where('subjects.id', $validated['subject_id']);
            });
        }

        $advertisements = $query->latest('published_at')->paginate(20);
        $advertisements->through(fn (Advertisement $advertisement) => $this->appendPublicTeacher($advertisement));

        return response()->json(['advertisements' => $advertisements]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'advertisements' => $request->user()
                ->advertisements()
                ->with($this->relations())
                ->latest()
                ->paginate(20),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        if ($request->input('type') === AdvertisementType::TEACHER_TO_SERVICE->value) {
            $this->ensureTeacherUser($request);
        } else {
            $this->ensureFamilyUser($request);
        }

        $data = $this->validatedData($request);

        $advertisement = DB::transaction(function () use ($request, $data) {
            /** @var Advertisement $advertisement */
            $advertisement = $request->user()->advertisements()->create([
                'type' => $data['type'],
                'status' => AdvertisementStatus::DRAFT,
                'format' => $data['format'] ?? null,
                'language' => $data['language'],
                'lesson_type' => $data['lesson_type'] ?? null,
                'city_id' => $data['city_id'],
                'district_id' => $data['district_id'] ?? null,
                'metro_station_id' => $data['metro_station_id'] ?? null,
                'participant_age_from' => $data['participant_age_from'] ?? null,
                'participant_age_to' => $data['participant_age_to'] ?? null,
                'description' => $data['description'],
            ]);

            $advertisement->children()->sync($data['child_ids'] ?? []);

            if (! empty($data['subject_ids'])) {
                $advertisement->subjects()->sync($data['subject_ids']);
            }

            return $advertisement;
        });

        return response()->json([
            'advertisement' => $this->appendPublicTeacher($advertisement->load($this->relations())),
        ], 201);
    }

    public function show(Advertisement $advertisement): JsonResponse
    {
        $user = auth()->user();
        $isOwner = $user?->id === $advertisement->user_id;

        /** @var AdvertisementResponse|null $response */
        $response = $user !== null && ! $isOwner
            ? $advertisement->responses()
                ->where('user_id', $user->id)
                ->first()
            : null;
        $hasResponded = $response !== null;
        $hasConversation = $response !== null && $response->conversation()->exists();
        if ($advertisement->status !== AdvertisementStatus::PUBLISHED && ! $isOwner && ! $hasConversation) {
            abort(404);
        }

        return response()->json([
            'advertisement' => $this->appendPublicTeacher($advertisement->load($this->relations())),
            'viewer' => [
                'authenticated' => $user !== null,
                'is_owner' => $isOwner,
                'has_responded' => $hasResponded,
                'has_conversation' => $hasConversation,
            ],
        ]);
    }

    public function edit(Request $request, Advertisement $advertisement): JsonResponse
    {
        abort_unless($advertisement->user_id === $request->user()->id, 404);

        return response()->json([
            'advertisement' => $advertisement->load($this->relations()),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request, Advertisement $advertisement): JsonResponse
    {
        $this->ensureOwner($request, $advertisement);

        if ($advertisement->status === AdvertisementStatus::CLOSED) {
            return response()->json([
                'message' => 'Закрытое объявление нельзя редактировать.',
            ], 422);
        }

        $data = $this->validatedData($request);

        DB::transaction(function () use ($advertisement, $data) {
            $advertisement->update([
                'type' => $data['type'],
                'format' => $data['format'] ?? null,
                'language' => $data['language'],
                'lesson_type' => $data['lesson_type'] ?? null,
                'city_id' => $data['city_id'],
                'district_id' => $data['district_id'] ?? null,
                'metro_station_id' => $data['metro_station_id'] ?? null,
                'participant_age_from' => $data['participant_age_from'] ?? null,
                'participant_age_to' => $data['participant_age_to'] ?? null,
                'description' => $data['description'],
            ]);

            $advertisement->children()->sync($data['child_ids'] ?? []);

            if (! empty($data['subject_ids'])) {
                $advertisement->subjects()->sync($data['subject_ids']);
            } else {
                $advertisement->subjects()->detach();
            }
        });

        return response()->json([
            'advertisement' => $advertisement->fresh()->load($this->relations()),
        ]);
    }

    public function publish(Request $request, Advertisement $advertisement): JsonResponse
    {
        $this->ensureOwner($request, $advertisement);

        if ($advertisement->status !== AdvertisementStatus::DRAFT) {
            return response()->json([
                'message' => 'Опубликовать можно только черновик.',
            ], 422);
        }

        $advertisement->update([
            'status' => AdvertisementStatus::PUBLISHED,
            'published_at' => now(),
        ]);

        return response()->json([
            'advertisement' => $advertisement->fresh()->load($this->relations()),
        ]);
    }

    public function close(Request $request, Advertisement $advertisement): JsonResponse
    {
        $this->ensureOwner($request, $advertisement);

        if ($advertisement->status === AdvertisementStatus::CLOSED) {
            return response()->json([
                'message' => 'Объявление уже закрыто.',
            ], 422);
        }

        $advertisement->update([
            'status' => AdvertisementStatus::CLOSED,
            'closed_at' => now(),
        ]);

        return response()->json([
            'advertisement' => $advertisement->fresh()->load($this->relations()),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function relations(): array
    {
        return [
            'children',
            'subjects',
            'city',
            'district',
            'metroStation',
        ];
    }

    private function appendPublicTeacher(Advertisement $advertisement): Advertisement
    {
        if ($advertisement->type !== AdvertisementType::TEACHER_TO_SERVICE) {
            return $advertisement;
        }

        $profile = $advertisement->user()
            ->with(['teacherProfile.city', 'teacherProfile.subjects'])
            ->first()?->teacherProfile;

        if (
            $profile === null ||
            ! $profile->public_profile_requested ||
            $profile->public_profile_status !== \App\Enums\TeacherProfileVisibilityStatus::APPROVED
        ) {
            $advertisement->setAttribute('public_teacher', null);

            return $advertisement;
        }

        $advertisement->setAttribute('public_teacher', [
            'id' => $profile->user_id,
            'name' => $profile->user->name,
            'surname' => $profile->surname,
            'city' => $profile->city,
            'subjects' => $profile->subjects,
        ]);

        return $advertisement;
    }

    /**
     * @return array{
     *     type: string,
     *     subject_ids?: array<int, int>,
     *     format: string|null,
     *     language: string,
     *     lesson_type?: string|null,
     *     city_id: int,
     *     district_id: int|null,
     *     metro_station_id: int|null,
     *     participant_age_from: int|null,
     *     participant_age_to: int|null,
     *     description: string,
     *     child_ids?: array<int, int>
     * }
     *
     * @throws ValidationException
     */
    private function validatedData(Request $request): array
    {
        $type = $request->input('type');
        $isTeacherService = $type === AdvertisementType::TEACHER_TO_SERVICE->value;
        $isFamilyAdvertisement = in_array($type, [
            AdvertisementType::FAMILY_TO_FAMILY->value,
            AdvertisementType::FAMILY_TO_TEACHER->value,
        ], true);
        $needsSubjects = in_array($type, [
            AdvertisementType::FAMILY_TO_TEACHER->value,
            AdvertisementType::TEACHER_TO_SERVICE->value,
        ], true);
        $needsFormat = $type !== AdvertisementType::FAMILY_TO_FAMILY->value;

        $ageRules = $isFamilyAdvertisement
            ? ['required', 'integer', 'min:0', 'max:18']
            : ['nullable', 'integer', 'min:0', 'max:100', 'required_with:participant_age_to'];
        $ageToRules = $isFamilyAdvertisement
            ? ['required', 'integer', 'min:0', 'max:18', 'gte:participant_age_from']
            : ['nullable', 'integer', 'min:0', 'max:100', 'required_with:participant_age_from', 'gte:participant_age_from'];

        $data = $request->validate([
            'type' => [
                'required',
                Rule::enum(AdvertisementType::class),
            ],

            'subject_ids' => [
                Rule::requiredIf(
                    $needsSubjects
                ),
                'array',
                $needsSubjects ? 'min:1' : 'sometimes',
            ],

            'subject_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('subjects', 'id'),
            ],

            'format' => [
                'nullable',
                'max:50',
                ...($needsFormat ? ['required'] : []),
                Rule::enum(AdvertisementStudyFormat::class),
            ],

            'language' => ['nullable', Rule::enum(SupportedLanguage::class)],

            'lesson_type' => [
                ...($isTeacherService ? ['required'] : ['prohibited']),
                Rule::enum(LessonType::class),
            ],

            'child_ids' => [
                ...($isFamilyAdvertisement ? ['required', 'array', 'min:1'] : ['sometimes', 'array', 'size:0']),
            ],

            'child_ids.*' => [
                'required',
                'integer',
                'distinct',
            ],

            'participant_age_from' => [
                ...$ageRules,
            ],

            'participant_age_to' => [
                ...$ageToRules,
            ],

            'city_id' => [
                'required',
                'integer',
                Rule::exists('cities', 'id'),
            ],

            'district_id' => [
                'nullable',
                'integer',
                Rule::exists('districts', 'id'),
            ],

            'metro_station_id' => [
                'nullable',
                'integer',
                Rule::exists('metro_stations', 'id'),
            ],

            'description' => [
                'required',
                'string',
                'max:5000',
            ],
        ]);

        if ($isFamilyAdvertisement) {
            $family = $request->user()->family;

            if (
                ! $family ||
                $family->children()
                    ->whereIn('id', $data['child_ids'])
                    ->count() !== count($data['child_ids'])
            ) {
                throw ValidationException::withMessages([
                    'child_ids' => 'Можно выбрать только детей из своего профиля семьи.',
                ]);
            }
        } elseif ($isTeacherService) {
            $profile = $request->user()->teacherProfile;
            $allowedSubjectIds = $profile?->subjects()->pluck('subjects.id')->all() ?? [];
            $invalidSubjectIds = array_diff($data['subject_ids'], $allowedSubjectIds);

            if ($profile === null || $invalidSubjectIds !== []) {
                throw ValidationException::withMessages([
                    'subject_ids' => 'Выберите только предметы из профиля педагога.',
                ]);
            }
        }

        if (
            isset($data['district_id']) &&
            ! District::query()
                ->whereKey($data['district_id'])
                ->where('city_id', $data['city_id'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'district_id' => 'Выбранный район не относится к выбранному городу.',
            ]);
        }

        if (
            isset($data['metro_station_id']) &&
            ! MetroStation::query()
                ->whereKey($data['metro_station_id'])
                ->where('city_id', $data['city_id'])
                ->exists()
        ) {
            throw ValidationException::withMessages([
                'metro_station_id' => 'Выбранная станция метро не относится к выбранному городу.',
            ]);
        }

        if ($this->bannedWordChecker->containsBannedWord($data['description'])) {
            throw ValidationException::withMessages([
                'description' => 'Описание содержит запрещённое слово.',
            ]);
        }

        $data['language'] = $data['language'] ?? $request->user()->locale ?? SupportedLanguage::RUSSIAN->value;

        return $data;
    }

    private function ensureFamilyUser(Request $request): void
    {
        abort_unless(
            $request->user()->user_type === UserType::FAMILY &&
            $request->user()->family,
            403
        );
    }

    private function ensureTeacherUser(Request $request): void
    {
        abort_unless(
            $request->user()->user_type === UserType::TEACHER &&
            $request->user()->teacherProfile,
            403
        );
    }

    private function ensureOwner(
        Request $request,
        Advertisement $advertisement
    ): void {
        abort_unless(
            $advertisement->user_id === $request->user()->id,
            403
        );
    }
}
