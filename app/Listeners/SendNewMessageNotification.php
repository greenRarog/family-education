<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\NewMessage;
use App\Notifications\NewMessageNotification;

class SendNewMessageNotification
{
    public function handle(NewMessage $event): void
    {
        $message = $event->message;

        $message->loadMissing([
            'user',
            'conversation.advertisementResponse.user',
            'conversation.advertisementResponse.advertisement.user',
        ]);

        $response = $message->conversation->advertisementResponse;

        $responseAuthor = $response->user;
        $advertisementOwner = $response->advertisement->user;

        $recipient = $message->user_id === $responseAuthor->id
            ? $advertisementOwner
            : $responseAuthor;

        $recipient->notify(
            new NewMessageNotification($message)
        );
    }
}
