<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Services\Max\MaxLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MaxLinkController extends Controller
{
    public function __invoke(Request $request, MaxLinkService $links): JsonResponse
    {
        $operation = match ($request->route()->getName()) {
            'max.create' => 'create', 'max.confirm' => 'confirm',
            'max.unlink' => 'unlink', default => 'status',
        };
        if ($operation !== 'status' && $request->header('X-Expected-Account-Id') !== (string) $request->user()->id) {
            return response()->json(['code' => 'account_changed'], 409)->header('Cache-Control', 'no-store, private');
        }
        $input = $operation === 'confirm' ? $request->validate([
            'attempt_id' => ['required', 'uuid'], 'code' => ['required', 'string', 'regex:/^[0-9]{8}$/D'],
        ]) : [];
        $result = $links->browser($request->user(), $request->session()->getId(), $operation, $input);
        $status = $result['_status'] ?? 200;
        unset($result['_status']);
        $response = response()->json($result, $status)->header('Cache-Control', 'no-store, private');
        if ($status === 429) {
            $response->header('Retry-After', (string) $result['retry_after']);
        }

        return $response;
    }
}
