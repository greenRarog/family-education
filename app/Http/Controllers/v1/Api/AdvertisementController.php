<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\AdvertisementStatus;
use App\Enums\AdvertisementStudyFormat;
use App\Enums\AdvertisementType;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Advertisement;
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
    )
    {
    }

    public function feed(): JsonResponse
    {
        return response()->json([
            'advertisements' => Advertisement::query()
                ->where('status', AdvertisementStatus::PUBLISHED)
                ->with($this->relations())
                ->latest('published_at')
                ->paginate(20),
        ]);
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
        $this->ensureFamilyUser($request);

        $data = $this->validatedData($request);

        $advertisement = DB::transaction(function () use ($request, $data) {
            /** @var Advertisement $advertisement */
            $advertisement = $request->user()->advertisements()->create([
                ...$data,
                'type' => $data['type'],
                'status' => AdvertisementStatus::DRAFT,
            ]);

            $advertisement->children()->sync($data['child_ids']);

            if ($data['type'] === AdvertisementType::FAMILY_TO_TEACHER->value) {
                $advertisement->subjects()->sync($data['subject_ids']);
            }

            return $advertisement;
        });

        return response()->json([
            'advertisement' => $advertisement->load($this->relations()),
        ], 201);
    }

    public function show(
        Request       $request,
        Advertisement $advertisement
    ): JsonResponse
    {
        $isOwner = $request->user()?->id === $advertisement->user_id;

        abort_unless(
            $advertisement->status === AdvertisementStatus::PUBLISHED || $isOwner,
            404
        );

        return response()->json([
            'advertisement' => $advertisement->load($this->relations()),
        ]);
    }

    public function edit(
        Request       $request,
        Advertisement $advertisement
    ): JsonResponse
    {
        abort_unless(
            $advertisement->user_id === $request->user()->id,
            404
        );

        return response()->json([
            'advertisement' => $advertisement->load($this->relations()),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(
        Request       $request,
        Advertisement $advertisement
    ): JsonResponse
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
                'city_id' => $data['city_id'],
                'district_id' => $data['district_id'] ?? null,
                'metro_station_id' => $data['metro_station_id'] ?? null,
                'participant_age_from' => $data['participant_age_from'],
                'participant_age_to' => $data['participant_age_to'],
                'description' => $data['description'],
            ]);

            $advertisement->children()->sync($data['child_ids']);

            if ($data['type'] === AdvertisementType::FAMILY_TO_TEACHER->value) {
                $advertisement->subjects()->sync($data['subject_ids']);
            } else {
                $advertisement->subjects()->detach();
            }
        });

        return response()->json([
            'advertisement' => $advertisement->fresh()->load($this->relations()),
        ]);
    }

    public function publish(
        Request       $request,
        Advertisement $advertisement
    ): JsonResponse
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

    public function close(
        Request       $request,
        Advertisement $advertisement
    ): JsonResponse
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

    /**
     * @return array{
     *     type: string,
     *     subject_ids?: array<int, int>,
     *     format: string|null,
     *     city_id: int,
     *     district_id: int|null,
     *     metro_station_id: int|null,
     *     participant_age_from: int,
     *     participant_age_to: int,
     *     description: string,
     *     child_ids: array<int, int>
     * }
     *
     * @throws ValidationException
     */
    private function validatedData(Request $request): array
    {
        $data = $request->validate([
            'type' => [
                'required',
                Rule::enum(AdvertisementType::class),
            ],

            'subject_ids' => [
                Rule::requiredIf(
                    fn() => $request->input('type') === AdvertisementType::FAMILY_TO_TEACHER->value
                ),
                'array',
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
                'required_if:type,' . AdvertisementType::FAMILY_TO_TEACHER->value,
                Rule::enum(AdvertisementStudyFormat::class),
            ],

            'child_ids' => [
                'required',
                'array',
                'min:1',
            ],

            'child_ids.*' => [
                'required',
                'integer',
                'distinct',
            ],

            'participant_age_from' => [
                'required',
                'integer',
                'min:0',
                'max:18',
            ],

            'participant_age_to' => [
                'required',
                'integer',
                'min:0',
                'max:18',
                'gte:participant_age_from',
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

        $family = $request->user()->family;

        if (
            !$family ||
            $family->children()
                ->whereIn('id', $data['child_ids'])
                ->count() !== count($data['child_ids'])
        ) {
            throw ValidationException::withMessages([
                'child_ids' => 'Можно выбрать только детей из своего профиля семьи.',
            ]);
        }

        if (
            isset($data['district_id']) &&
            !District::query()
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
            !MetroStation::query()
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

    private function ensureOwner(
        Request       $request,
        Advertisement $advertisement
    ): void
    {
        abort_unless(
            $advertisement->user_id === $request->user()->id,
            403
        );
    }
}
