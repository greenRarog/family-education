<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\Sex;
use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\District;
use App\Models\MetroStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FamilyController extends Controller
{
    public function show(): JsonResponse
    {
        $family = Auth::user()->family;

        if (! $family) {
            return response()->json([
                'message' => 'Профиль семьи не найден.',
            ], 404);
        }

        return response()->json([
            'family' => $family->load('children'),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(Request $request): JsonResponse
    {
        $family = Auth::user()->family;

        if (! $family) {
            return response()->json([
                'message' => 'Профиль семьи не найден.',
            ], 404);
        }

        $data = Validator::make($request->all(), [
            'surname' => [
                'required',
                'string',
                'max:255',
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

            'children' => [
                'required',
                'array',
                'min:1',
            ],

            'children.*.id' => [
                'nullable',
                'integer',
            ],

            'children.*.name' => [
                'required',
                'string',
                'max:255',
            ],

            'children.*.birth_date' => [
                'required',
                'date',
            ],

            'children.*.sex' => [
                'required',
                Rule::enum(Sex::class),
            ],
        ])->validate();

        if (! empty($data['district_id'])) {
            $districtBelongsToCity = District::query()
                ->whereKey($data['district_id'])
                ->where('city_id', $data['city_id'])
                ->exists();

            if (! $districtBelongsToCity) {
                throw ValidationException::withMessages([
                    'district_id' => 'Выбранный район не относится к выбранному городу.',
                ]);
            }
        }

        if (! empty($data['metro_station_id'])) {
            $metroBelongsToCity = MetroStation::query()
                ->whereKey($data['metro_station_id'])
                ->where('city_id', $data['city_id'])
                ->exists();

            if (! $metroBelongsToCity) {
                throw ValidationException::withMessages([
                    'metro_station_id' => 'Выбранная станция метро не относится к выбранному городу.',
                ]);
            }
        }

        DB::transaction(function () use ($family, $data) {
            $family->update([
                'surname' => $data['surname'],
                'city_id' => $data['city_id'],
                'district_id' => $data['district_id'] ?? null,
                'metro_station_id' => $data['metro_station_id'] ?? null,
            ]);

            $receivedChildIds = [];

            foreach ($data['children'] as $childData) {
                if (! empty($childData['id'])) {

                    /** @var Child $child */
                    $child = $family->children()
                        ->whereKey($childData['id'])
                        ->firstOrFail();

                    $child->update([
                        'name' => $childData['name'],
                        'birth_date' => $childData['birth_date'],
                        'sex' => $childData['sex'],
                    ]);

                    $receivedChildIds[] = $child->id;

                    continue;
                }

                /** @var Child $child */
                $child = $family->children()->create([
                    'name' => $childData['name'],
                    'birth_date' => $childData['birth_date'],
                    'sex' => $childData['sex'],
                ]);

                $receivedChildIds[] = $child->id;
            }

            $family->children()
                ->whereNotIn('id', $receivedChildIds)
                ->delete();
        });

        return response()->json([
            'family' => $family->fresh()->load('children'),
        ]);
    }
}
