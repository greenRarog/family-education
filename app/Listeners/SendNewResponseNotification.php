<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NewResponse;
use App\Notifications\NewResponseNotification;

class SendNewResponseNotification
{
    public function handle(NewResponse $event): void
    {
        $response = $event->response;

        $response->loadMissing([
            'user',
            'advertisement.user',
        ]);

        $response->advertisement->user->notify(
            new NewResponseNotification($response)
        );
    }
}
