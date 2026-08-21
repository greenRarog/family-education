<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AdvertisementResponseStatus;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        $owner = User::factory()->create();
        $respondent = User::factory()->create();

        $advertisement = Advertisement::factory()->create([
            'user_id' => $owner->id,
        ]);

        $response = AdvertisementResponse::factory()->create([
            'advertisement_id' => $advertisement->id,
            'user_id' => $respondent->id,
            'status' => AdvertisementResponseStatus::SENT,
        ]);

        return [
            'advertisement_response_id' => $response->id,
        ];
    }
}
