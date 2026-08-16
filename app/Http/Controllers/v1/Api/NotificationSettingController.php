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
            ],
        );

        return response()->json([
            'email_enabled' => $settings->email_enabled,
            'telegram_enabled' => $settings->telegram_enabled,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'telegram_enabled' => ['required', 'boolean'],
        ]);

        $settings = NotificationSetting::firstOrNew([
            'user_id' => $request->user()->id,
        ]);

        $settings->email_enabled = $validated['email_enabled'];
        $settings->telegram_enabled = $validated['telegram_enabled'];

        $settings->save();

        return response()->json([
            'email_enabled' => $settings->email_enabled,
            'telegram_enabled' => $settings->telegram_enabled,
        ]);
    }
}
