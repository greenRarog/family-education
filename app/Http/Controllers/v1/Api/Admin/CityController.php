<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CityController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(City::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:cities,name'],
        ]);
        $city = City::create($data);

        return response()->json($city, 201);
    }

    public function show(City $city): JsonResponse
    {
        return response()->json($city);
    }

    public function update(Request $request, City $city): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:cities,name,'.$city->id],
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
