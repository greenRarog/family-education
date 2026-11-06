<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function countries(): JsonResponse
    {
        return response()->json(Country::query()->orderBy('name')->get(['id', 'code', 'name']));
    }

    public function cities(Request $request): JsonResponse
    {
        $data = $request->validate(['country_id' => ['nullable', 'integer', 'exists:countries,id']]);

        return response()->json(
            City::query()
                ->when($data['country_id'] ?? null, fn ($query, $country) => $query->where('country_id', $country))
                ->orderBy('name')
                ->get(['id', 'country_id', 'name'])
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
            'countries' => Country::query()->orderBy('name')->get(['id', 'code', 'name']),
            'cities' => City::query()
                ->orderBy('name')
                ->get(['id', 'country_id', 'name']),

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
