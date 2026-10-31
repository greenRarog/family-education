<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Advertisement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicAdvertisementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Advertisement $advertisement */
        $advertisement = $this->resource;
        $teacher = $advertisement->getAttribute('public_teacher');

        // Public responses deliberately exclude children, owner IDs and pivot data.
        return [
            'moderation' => $this->when($request->user()?->id === $advertisement->user_id, fn () => $advertisement->moderationDetails()),
            'id' => $advertisement->id,
            'type' => $advertisement->type,
            'status' => $advertisement->status,
            'description' => $advertisement->description,
            'language' => $advertisement->language,
            'format' => $advertisement->format,
            'lesson_type' => $advertisement->lesson_type,
            'participant_age_from' => $advertisement->participant_age_from,
            'participant_age_to' => $advertisement->participant_age_to,
            'published_at' => $advertisement->published_at,
            'closed_at' => $advertisement->closed_at,
            'city' => $advertisement->city->only(['id', 'name']),
            'district' => $advertisement->district?->only(['id', 'name']),
            'metro_station' => $advertisement->metroStation?->only(['id', 'name']),
            'subjects' => $advertisement->subjects->map->only(['id', 'name']),
            'public_teacher' => $teacher === null ? null : [
                'id' => $teacher['id'],
                'name' => $teacher['name'],
                'surname' => $teacher['surname'],
                'city' => $teacher['city']?->only(['id', 'name']),
                'subjects' => $teacher['subjects']->map->only(['id', 'name']),
            ],
        ];
    }
}
