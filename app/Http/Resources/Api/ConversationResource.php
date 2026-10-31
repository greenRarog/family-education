<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Enums\AdvertisementResponseStatus;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Conversation $r */
        $r = $this->resource;
        $response = $r->advertisementResponse;
        $advertisement = $response->advertisement;
        $ownerBlocked = $advertisement->user->isBlocked();
        $authorBlocked = $response->user->isBlocked();
        $paused = $ownerBlocked || $authorBlocked;
        $isOwner = $advertisement->user_id === $request->user()?->id;
        $unavailable = ($ownerBlocked || $advertisement->isHidden()) && ! $isOwner;

        return [
            'id' => $r->id,
            'communication_paused' => $paused,
            'advertisement' => $unavailable ? [
                'id' => $advertisement->id,
                'type' => $advertisement->type->value,
                'unavailable' => true,
            ] : [
                'id' => $advertisement->id,
                'type' => $advertisement->type->value,
                'moderation' => $this->when($isOwner, fn () => $advertisement->moderationDetails()),
                'description' => $advertisement->description,
                'status' => $advertisement->status->value,
            ],
            'response' => [
                'id' => $response->id,
                'status' => $response->status->value,
                'user' => [
                    'id' => $response->user->id,
                    'name' => $response->user->name,
                ],
            ],
            'messages' => MessageResource::collection(
                $this->whenLoaded('messages')
            ),
            'last_message' => $this->whenLoaded('lastMessage', fn () => new MessageResource($r->lastMessage)),
            'unread_count' => (int) $r->getAttribute('unread_messages_count'),
            'viewer' => [
                'id' => $request->user()?->id,
                'can_accept' => $isOwner && ! $paused && $response->status === AdvertisementResponseStatus::SENT,
                'can_reject' => $isOwner && ! $ownerBlocked && $response->status !== AdvertisementResponseStatus::REJECTED,
                'is_advertisement_owner' => $advertisement->user_id === $request->user()?->id,
                'is_response_author' => $response->user_id === $request->user()?->id,
            ],
            'has_unread_messages' => (bool) $r->getAttribute('has_unread_messages'),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }
}
