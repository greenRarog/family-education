<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\AdminActionType;
use App\Models\AdminAction;
use App\Models\AdvertisementResponse;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewResponse implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly int $hideVersion;

    public function __construct(
        public readonly AdvertisementResponse $response,
    ) {
        // Constructed while EnforceModeration still holds the owner lock.
        $this->hideVersion = AdminAction::query()->where('target_type', 'advertisement')
            ->where('target_id', $response->advertisement_id)->where('action', AdminActionType::ADVERTISEMENT_HIDDEN)->count();
    }
}
