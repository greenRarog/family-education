<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Services\Max\BotApi;
use Illuminate\Notifications\Notification;

class ConfirmedMaxChannel
{
    public function __construct(private readonly BotApi $bot) {}

    public function send(User $notifiable, Notification $notification): void
    {
        $notifiable = $notifiable->fresh();
        if ($notifiable === null || ! $notifiable->hasConfirmedMax() || ! method_exists($notification, 'toTelegram') || ! method_exists($notification, 'shouldSend')
            || ! $notification->shouldSend($notifiable, self::class)) {
            return;
        }
        // Existing localized notification content; no provider-specific markup.
        $payload = $notification->toTelegram($notifiable)->toArray();
        $text = $payload['text'];
        $markup = $payload['reply_markup'] ?? [];
        if (is_string($markup)) {
            $markup = json_decode($markup, true, flags: JSON_THROW_ON_ERROR);
        }
        foreach ($markup['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                if (isset($button['url'])) {
                    $text .= "\n\n".$button['url'];
                }
            }
        }
        foreach (mb_str_split($text, 3500) as $chunk) {
            $this->bot->send($notifiable->max_user_id, $chunk);
        }
    }
}
