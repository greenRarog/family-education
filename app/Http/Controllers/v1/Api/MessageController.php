<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\AdvertisementResponseStatus;
use App\Events\NewMessage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreMessageRequest;
use App\Http\Resources\Api\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        $conversation->load('advertisementResponse');
        $advertisementResponse = $conversation->advertisementResponse;
        if ($advertisementResponse->status !== AdvertisementResponseStatus::ACCEPTED
            && $advertisementResponse->user_id === $request->user()->id) {
            abort(
                422,
                'Нельзя отправлять сообщения до принятия отклика.'
            );
        }
        if ($advertisementResponse->status === AdvertisementResponseStatus::REJECTED) {
            abort(
                422,
                'Диалог завершён.'
            );
        }
        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $request->user()->id,
            'body' => $request->string('message')->toString(),
        ]);
        $conversation->touch();
        $message->load('user');
        NewMessage::dispatch($message);

        return response()->json([
            'message' => new MessageResource($message),
        ], 201);
    }

    public function read(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorizeConversation($request, $conversation);
        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => now(),
            ]);

        return response()->json([
            'message' => 'Сообщения отмечены как прочитанные.',
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
