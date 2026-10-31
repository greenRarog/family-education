<?php

declare(strict_types=1);

namespace App\Http\Controllers\v1\Api;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Events\NewResponse;
use App\Exceptions\EmailVerificationRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreAdvertisementResponseRequest;
use App\Http\Resources\Api\AdvertisementResponseResource;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdvertisementResponseController extends Controller
{
    public function store(StoreAdvertisementResponseRequest $request, Advertisement $advertisement): JsonResponse
    {
        $user = $request->user();
        if ($advertisement->user_id === $user->id) {
            abort(422, 'Нельзя откликнуться на собственное объявление.');
        }
        if (! $advertisement->type->allowsResponsesFrom($user->user_type)) {
            abort(403, 'Ваш тип аккаунта не позволяет откликнуться на это объявление.');
        }
        if (! $user->hasVerifiedEmail()) {
            throw new EmailVerificationRequired;
        }
        if ($advertisement->status !== AdvertisementStatus::PUBLISHED) {
            abort(422, 'Нельзя откликнуться на это объявление.');
        }
        if (
            AdvertisementResponse::query()
                ->where('advertisement_id', $advertisement->id)
                ->where('user_id', $user->id)
                ->exists()
        ) {
            abort(422, 'Вы уже откликались на это объявление.');
        }

        $response = DB::transaction(function () use ($request, $advertisement, $user) {
            // The unique pair is authoritative if another request passed exists
            // concurrently. createOrFirst rolls back its insert to a savepoint.
            $response = AdvertisementResponse::query()->createOrFirst([
                'advertisement_id' => $advertisement->id,
                'user_id' => $user->id,
            ], [
                'status' => AdvertisementResponseStatus::SENT,
            ]);
            if (! $response->wasRecentlyCreated) {
                abort(422, 'Вы уже откликались на это объявление.');
            }
            $conversation = Conversation::query()->create([
                'advertisement_response_id' => $response->id,
            ]);
            Message::query()->create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
                'body' => $request->string('message')->toString(),
            ]);

            return $response->load([
                'user',
                'conversation',
            ]);
        });
        NewResponse::dispatch($response);

        return response()->json([
            'response' => new AdvertisementResponseResource($response),
        ], 201);
    }

    public function accept(AdvertisementResponse $advertisementResponse): JsonResponse
    {
        $advertisementResponse->load('advertisement');
        if ($advertisementResponse->advertisement->user_id !== auth()->id()) {
            abort(403);
        }
        if ($advertisementResponse->status !== AdvertisementResponseStatus::SENT) {
            abort(422, 'Отклик уже обработан.');
        }
        $advertisementResponse->update([
            'status' => AdvertisementResponseStatus::ACCEPTED,
        ]);

        return response()->json([
            'response' => new AdvertisementResponseResource(
                $advertisementResponse->fresh()->load([
                    'user',
                    'conversation',
                ])
            ),
        ]);
    }

    public function reject(AdvertisementResponse $advertisementResponse): JsonResponse
    {
        $advertisementResponse->load('advertisement');
        if ($advertisementResponse->advertisement->user_id !== auth()->id()) {
            abort(403);
        }
        if (! in_array(
            $advertisementResponse->status,
            [AdvertisementResponseStatus::SENT, AdvertisementResponseStatus::ACCEPTED],
            true)
        ) {
            abort(422, 'Отклик уже завершён.');
        }
        $advertisementResponse->update([
            'status' => AdvertisementResponseStatus::REJECTED,
        ]);

        return response()->json([
            'response' => new AdvertisementResponseResource(
                $advertisementResponse->fresh()->load([
                    'user',
                    'conversation',
                ])
            ),
        ]);
    }
}
