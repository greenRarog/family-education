<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetroStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetroStationController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(MetroStation::query()->with('city')->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $station = MetroStation::create($data);

        return response()->json($station->load('city'), 201);
    }

    public function show(MetroStation $metroStation): JsonResponse
    {
        return response()->json($metroStation->load('city'));
    }

    public function update(Request $request, MetroStation $metroStation): JsonResponse
    {
        $data = $request->validate([
            'city_id' => ['required', 'integer', 'exists:cities,id'],
            'name' => ['required', 'string', 'max:255'],
        ]);
        $metroStation->update($data);

        return response()->json($metroStation->fresh()->load('city'));
    }

    public function destroy(MetroStation $metroStation): JsonResponse
    {
        $metroStation->delete();

        return response()->json(null, 204);
    }
}
