<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

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

        return [
            'id' => $r->id,
            'advertisement' => [
                'id' => $advertisement->id,
                'type' => $advertisement->type->value,
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
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }
}
