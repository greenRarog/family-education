<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ConversationResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $conversations = Conversation::query()
            ->with([
                'advertisementResponse.user',
                'advertisementResponse.advertisement',
            ])
            ->where(function ($query) use ($user) {
                $query
                    ->whereHas(
                        'advertisementResponse',
                        fn ($query) => $query->where('user_id', $user->id)
                    )
                    ->orWhereHas(
                        'advertisementResponse.advertisement',
                        fn ($query) => $query->where('user_id', $user->id)
                    );
            })
            ->latest('updated_at')
            ->paginate(20);

        return response()->json([
            'conversations' => ConversationResource::collection(
                $conversations
            ),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $conversation->load([
            'advertisementResponse.user',
            'advertisementResponse.advertisement',
            'messages.user',
        ]);

        return response()->json([
            'conversation' => new ConversationResource($conversation),
        ]);
    }

    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        $user = $request->user();
        $conversation->loadMissing([
            'advertisementResponse.advertisement',
        ]);
        $response = $conversation->advertisementResponse;
        $isResponseOwner = $response->user_id === $user->id;
        $isAdvertisementOwner = $response->advertisement->user_id === $user->id;

        if (! $isResponseOwner && ! $isAdvertisementOwner) {
            abort(403);
        }
    }
}
