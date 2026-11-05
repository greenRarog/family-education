<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use App\Services\Telegram\BotApi;
use Illuminate\Notifications\Notification;

/** Uses the existing templates with an explicit confirmed recipient and redacted errors. */
class ConfirmedTelegramChannel
{
    public function __construct(private readonly BotApi $bot) {}

    public function send(User $notifiable, Notification $notification): ?array
    {
        $notifiable = $notifiable->fresh();
        if ($notifiable === null || ! $notifiable->hasConfirmedTelegram() || ! method_exists($notification, 'toTelegram') || ! method_exists($notification, 'shouldSend')
            || ! $notification->shouldSend($notifiable, 'telegram')) {
            return null;
        }
        $payload = $notification->toTelegram($notifiable)->toArray();
        // User-authored message/name/title is plain text, never Telegram markup.
        unset($payload['parse_mode']);
        $payload['chat_id'] = $notifiable->telegram_chat_id;
        $chunks = mb_str_split($payload['text'], 2000);
        $result = null;
        foreach ($chunks as $index => $text) {
            $part = [...$payload, 'text' => $text];
            if ($index !== count($chunks) - 1) {
                unset($part['reply_markup']);
            }
            $result = $this->bot->call('sendMessage', $part);
        }

        return $result;
    }
}
