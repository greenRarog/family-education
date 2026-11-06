<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Rules\NoBannedWords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(City::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name' => [new NoBannedWords, 'required', 'string', 'max:255', Rule::unique('cities', 'name')->where('country_id', $request->input('country_id'))],
        ]);
        $city = City::create($data);

        return response()->json($city->load('country'), 201);
    }

    public function show(City $city): JsonResponse
    {
        return response()->json($city);
    }

    public function update(Request $request, City $city): JsonResponse
    {
        $data = $request->validate([
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name' => [new NoBannedWords, 'required', 'string', 'max:255', Rule::unique('cities', 'name')->where('country_id', $request->input('country_id'))->ignore($city)],
        ]);
        $city->update($data);

        return response()->json($city->fresh());
    }

    public function destroy(City $city): JsonResponse
    {
        $city->delete();

        return response()->json(null, 204);
    }
}
