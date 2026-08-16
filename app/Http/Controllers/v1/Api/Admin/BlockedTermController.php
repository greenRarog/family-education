<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\BannedWord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockedTermController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(BannedWord::query()->orderBy('word')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:255', 'unique:banned_words,word'],
        ]);
        $blockedTerm = BannedWord::create($data);

        return response()->json($blockedTerm, 201);
    }

    public function show(BannedWord $blockedTerm): JsonResponse
    {
        return response()->json($blockedTerm);
    }

    public function update(Request $request, BannedWord $blockedTerm): JsonResponse
    {
        $data = $request->validate([
            'word' => ['required', 'string', 'max:255', 'unique:banned_words,word,'.$blockedTerm->id],
        ]);
        $blockedTerm->update($data);

        return response()->json($blockedTerm->fresh());
    }

    public function destroy(BannedWord $blockedTerm): JsonResponse
    {
        $blockedTerm->delete();

        return response()->json(null, 204);
    }
}
