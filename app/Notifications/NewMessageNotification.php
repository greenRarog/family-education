<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

class NewMessageNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Message $message,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];

        if ($notifiable->emailNotificationsEnabled()) {
            $channels[] = 'mail';
        }
        if ($notifiable->telegramNotificationsEnabled()) {
            $channels[] = 'telegram';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('notifications.new_message.subject'))
            ->view('emails.notifications.new-message', [
                'notifiable' => $notifiable,
                'message' => $this->message,
                'subject' => __('notifications.new_message.subject'),
            ]);
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $url = url(
            "/conversations/{$this->message->conversation_id}"
        );

        return TelegramMessage::create()
            ->content(
                implode("\n\n", [
                    __('notifications.new_message.greeting', [
                        'name' => $notifiable->name,
                    ]),
                    __('notifications.new_message.line', [
                        'user' => $this->message->user->name,
                    ]),
                    __('notifications.new_message.message').': '
                    .$this->message->body,
                ])
            )
            ->button(
                __('notifications.new_message.action'),
                $url
            );
    }
}
