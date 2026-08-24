<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AdvertisementResponse;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

class NewResponseNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly AdvertisementResponse $response,
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
            ->subject(__('notifications.new_response.subject'))
            ->view('emails.notifications.new-response', [
                'notifiable' => $notifiable,
                'response' => $this->response,
                'subject' => __('notifications.new_response.subject'),
            ]);
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $advertisementTitle =
            $this->response->advertisement->title
            ?? __('notifications.new_response.advertisement_default');

        $url = url(
            "/conversations/{$this->response->conversation->id}"
        );

        return TelegramMessage::create()
            ->content(
                implode("\n\n", [
                    __('notifications.new_response.greeting', [
                        'name' => $notifiable->name,
                    ]),
                    __('notifications.new_response.line', [
                        'user' => $this->response->user->name,
                    ]),
                    __('notifications.new_response.advertisement').': '
                    .$advertisementTitle,
                ])
            )
            ->button(
                __('notifications.new_response.action'),
                $url
            );
    }
}
