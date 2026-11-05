<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationSettingController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $settings = NotificationSetting::firstOrCreate(
            ['user_id' => $request->user()->id],
            [
                'email_enabled' => true,
                'telegram_enabled' => false,
                'max_enabled' => false,
            ],
        );

        return response()->json([
            'email_enabled' => $settings->email_enabled,
            'telegram_enabled' => $settings->telegram_enabled && $request->user()->hasConfirmedTelegram(),
            'max_enabled' => $settings->max_enabled && $request->user()->hasConfirmedMax(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'telegram_enabled' => ['required', 'boolean'],
            'max_enabled' => ['sometimes', 'boolean'],
        ]);

        if ($request->hasHeader('X-Expected-Account-Id') && $request->header('X-Expected-Account-Id') !== (string) $request->user()->id) {
            return response()->json(['code' => 'account_changed'], 409);
        }
        if ($validated['telegram_enabled'] && ! $request->user()->hasConfirmedTelegram()) {
            return response()->json(['code' => 'telegram_not_connected'], 422);
        }

        if (($validated['max_enabled'] ?? false) && ! $request->user()->hasConfirmedMax()) {
            return response()->json(['code' => 'max_not_connected'], 422);
        }

        $settings = NotificationSetting::firstOrNew([
            'user_id' => $request->user()->id,
        ]);

        $settings->email_enabled = $validated['email_enabled'];
        $settings->telegram_enabled = $validated['telegram_enabled'];
        if (array_key_exists('max_enabled', $validated)) {
            $settings->max_enabled = $validated['max_enabled'];
        }

        $settings->save();

        return response()->json([
            'email_enabled' => $settings->email_enabled,
            'telegram_enabled' => $settings->telegram_enabled && $request->user()->hasConfirmedTelegram(),
            'max_enabled' => $settings->max_enabled && $request->user()->hasConfirmedMax(),
        ]);
    }
}
