<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\AdvertisementExpired;
use App\Notifications\AdvertisementExpiredNotification;

class SendAdvertisementExpiredNotification
{
    public function handle(AdvertisementExpired $event): void
    {
        $advertisement = $event->advertisement;

        $advertisement->loadMissing('user');

        $advertisement->user->notify(
            new AdvertisementExpiredNotification($advertisement)
        );
    }
}
