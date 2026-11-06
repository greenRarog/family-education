<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Rules\NoBannedWords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CountryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Country::query()->orderBy('name')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Country::create($this->validated($request)), 201);
    }

    public function show(Country $country): JsonResponse
    {
        return response()->json($country);
    }

    public function update(Request $request, Country $country): JsonResponse
    {
        $country->update($this->validated($request, $country));

        return response()->json($country->fresh());
    }

    public function destroy(Country $country): JsonResponse
    {
        if ($country->cities()->exists()) {
            throw ValidationException::withMessages(['country_id' => __('This country still has cities.')]);
        }
        $country->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Country $country = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z]{2}$/', Rule::unique('countries', 'code')->ignore($country)],
            'name' => [new NoBannedWords, 'required', 'string', 'max:255'],
        ]);
    }
}
