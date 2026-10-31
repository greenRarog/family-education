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
        abort_unless($conversation->hasParticipant($request->user()), 403);
        $conversation->load('advertisementResponse');
        $advertisementResponse = $conversation->advertisementResponse;
        if ($advertisementResponse->status === AdvertisementResponseStatus::REJECTED) {
            abort(
                422,
                'Диалог завершён.'
            );
        }
        if ($advertisementResponse->status !== AdvertisementResponseStatus::ACCEPTED
            && $advertisementResponse->user_id === $request->user()->id) {
            abort(
                422,
                'Нельзя отправлять сообщения до принятия отклика.'
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
        abort_unless($conversation->hasParticipant($request->user()), 403);
        $data = $request->validate([
            'through_id' => ['nullable', 'integer', 'min:1'],
        ]);
        // Confirm an existing boundary in this conversation, never a future ID.
        // Include own messages so a client can acknowledge its entire fetched page.
        $boundary = $conversation->messages();
        if (isset($data['through_id'])) {
            $boundary->where('id', '<=', $data['through_id']);
        }
        $throughId = $boundary->max('id');
        $throughId = $throughId === null ? null : (int) $throughId;
        // Match the timestamp precision stored by Eloquent and the read_at column.
        $readAt = now()->startOfSecond();
        Message::query()
            ->where('conversation_id', $conversation->id)
            ->where('id', '<=', $throughId ?? 0)
            ->where('user_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update([
                'read_at' => $readAt,
            ]);

        return response()->json([
            'message' => 'Сообщения отмечены как прочитанные.',
            'through_id' => $throughId,
            'read_at' => $readAt,
        ]);
    }
}
