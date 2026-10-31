<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\ConversationResource;
use App\Http\Resources\Api\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
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
                'advertisementResponse.advertisement.user',
                'advertisementResponse.advertisement.hidingAction',
                'lastMessage.user',
            ])->withExists([
                'messages as has_unread_messages' => function ($query) use ($user) {
                    $query
                        ->where('user_id', '!=', $user->id)
                        ->whereNull('read_at');
                },
            ])->withCount([
                'messages as unread_messages_count' => fn ($query) => $query
                    ->where('user_id', '!=', $user->id)
                    ->whereNull('read_at'),
            ])
            ->where(function ($query) use ($user) {
                $query
                    ->whereHas(
                        'advertisementResponse',
                        fn ($query) => $query->where('user_id', $user->id)
                    )
                    ->orWhereHas(
                        'advertisementResponse.advertisement.user',
                        fn ($query) => $query->where('user_id', $user->id)
                    );
            })
            ->orderByDesc('has_unread_messages')
            ->latest('updated_at')
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json([
            'conversations' => ConversationResource::collection($conversations)->response()->getData(true),
        ]);
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->hasParticipant($request->user()), 403);
        $data = $request->validate(['after_id' => ['nullable', 'integer', 'min:0']]);
        $conversation->load([
            'advertisementResponse.user',
            'advertisementResponse.advertisement.user',
            'advertisementResponse.advertisement.hidingAction',
        ]);

        $after = isset($data['after_id']);
        $query = $conversation->messages()->with('user');
        if ($after) {
            $query->where('id', '>', $data['after_id'])->orderBy('id');
        } else {
            $query->orderByDesc('id');
        }
        $messages = $query->limit(51)->get();
        $hasMore = $messages->count() > 50;
        $messages = $messages->take(50);
        $messages = ($after ? $messages : $messages->reverse())->values();
        $conversation->setRelation('messages', $messages);

        return response()->json([
            'conversation' => new ConversationResource($conversation),
            'messages_meta' => [
                'has_older' => ! $after && $hasMore,
                'has_more' => $after && $hasMore,
                'oldest_id' => $messages->first()?->id,
                'latest_id' => $messages->last()?->id,
                'read_by_other_through_id' => $conversation->messages()
                    ->where('user_id', $request->user()->id)
                    ->whereNotNull('read_at')->max('id'),
            ],
        ]);
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        abort_unless($conversation->hasParticipant($request->user()), 403);
        $data = $request->validate([
            'before_id' => ['nullable', 'integer', 'min:1', 'prohibits:after_id'],
            'after_id' => ['nullable', 'integer', 'min:0', 'prohibits:before_id'],
        ]);

        $query = $conversation->messages()->with('user');
        $after = isset($data['after_id']);
        if ($after) {
            $query->where('id', '>', $data['after_id'])->orderBy('id');
        } else {
            if (isset($data['before_id'])) {
                $query->where('id', '<', $data['before_id']);
            }
            $query->orderByDesc('id');
        }

        $messages = $query->limit(51)->get();
        $hasMore = $messages->count() > 50;
        $page = $messages->take(50);
        if (! $after) {
            $page = $page->reverse();
        }

        return response()->json([
            'messages' => MessageResource::collection($page->values()),
            'meta' => ['has_more' => $hasMore],
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = Message::query()
            ->whereNull('read_at')
            ->where('user_id', '!=', $user->id)
            ->whereHas('conversation', function ($query) use ($user) {
                $query->where(function ($query) use ($user) {
                    $query->whereHas(
                        'advertisementResponse',
                        fn ($query) => $query->where('user_id', $user->id)
                    )->orWhereHas(
                        'advertisementResponse.advertisement.user',
                        fn ($query) => $query->where('user_id', $user->id)
                    );
                });
            })
            ->count();

        return response()->json([
            'count' => $count,
        ]);
    }
}
