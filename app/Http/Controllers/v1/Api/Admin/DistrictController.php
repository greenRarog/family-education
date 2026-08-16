<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DistrictController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(District::query()->with('city')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $district = District::create($data);

        return response()->json($district->load('city'), 201);
    }

    public function show(District $district): JsonResponse
    {
        return response()->json($district->load('city'));
    }

    public function update(Request $request, District $district): JsonResponse
    {
        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $district->update($data);

        return response()->json($district->fresh()->load('city'));
    }

    public function destroy(District $district): JsonResponse
    {
        $district->delete();

        return response()->json(null, 204);
    }
}
