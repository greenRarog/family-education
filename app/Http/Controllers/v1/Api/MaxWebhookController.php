<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Services\Max\BotApi;
use App\Services\Max\BotConfiguration;
use App\Services\Max\MaxLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class MaxWebhookController extends Controller
{
    public function __invoke(Request $request, MaxLinkService $links, BotApi $bot): JsonResponse
    {
        abort_unless(BotConfiguration::available(), 503);
        abort_unless(hash_equals(config('services.max.webhook_secret'), (string) $request->header('X-Max-Bot-Api-Secret')), 403);
        abort_if(strlen($request->getContent()) > 65536, 413);
        try {
            $links->update($request->json()->all(), $bot);
        } catch (Throwable) {
            return response()->json(['ok' => false], 503);
        }

        return response()->json(['ok' => true]);
    }
}
