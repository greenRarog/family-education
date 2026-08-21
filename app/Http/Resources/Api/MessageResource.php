<?php

namespace App\Http\Resources\Api;

use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Message $r */
        $r = $this->resource;

        return [
            'id' => $r->id,
            'user' => [
                'id' => $r->user->id,
                'name' => $r->user->name,
            ],
            'message' => $r->body,
            'read_at' => $r->read_at,
            'created_at' => $r->created_at,
        ];
    }
}
