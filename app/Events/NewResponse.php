<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\AdvertisementResponse;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewResponse
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly AdvertisementResponse $response,
    ) {}
}
