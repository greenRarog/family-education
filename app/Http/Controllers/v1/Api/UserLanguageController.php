<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\SupportedLanguage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserLanguageController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'language' => ['required', Rule::enum(SupportedLanguage::class)],
        ]);

        $user = $request->user();
        $user->locale = $validated['language'];
        $user->save();

        return response()->json(['language' => $user->locale]);
    }
}
