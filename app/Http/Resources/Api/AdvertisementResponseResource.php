<?php

namespace App\Http\Resources\Api;

use App\Models\AdvertisementResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdvertisementResponseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var AdvertisementResponse $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'status' => $r->status->value,
            'advertisement_id' => $r->advertisement_id,
            'user' => [
                'id' => $r->user->id,
                'name' => $r->user->name,
            ],
            'conversation_id' => $this->whenLoaded(
                'conversation',
                fn () => $r->conversation?->id
            ),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }
}
