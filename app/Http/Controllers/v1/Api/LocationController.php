<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use Illuminate\Http\JsonResponse;

class LocationController extends Controller
{
    public function cities(): JsonResponse
    {
        return response()->json(
            City::query()
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    public function districts(City $city): JsonResponse
    {
        return response()->json(
            $city->districts()
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    public function metroStations(City $city): JsonResponse
    {
        return response()->json(
            $city->metroStations()
                ->orderBy('name')
                ->get(['id', 'name'])
        );
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'cities' => City::query()
                ->orderBy('name')
                ->get(['id', 'name']),

            'districts' => City::query()
                ->with([
                    'districts' => fn ($query) => $query
                        ->orderBy('name')
                        ->select(['id', 'city_id', 'name']),
                ])
                ->get()
                ->pluck('districts')
                ->flatten()
                ->values(),

            'metro_stations' => City::query()
                ->with([
                    'metroStations' => fn ($query) => $query
                        ->orderBy('name')
                        ->select(['id', 'city_id', 'name']),
                ])
                ->get()
                ->pluck('metroStations')
                ->flatten()
                ->values(),
        ]);
    }
}
