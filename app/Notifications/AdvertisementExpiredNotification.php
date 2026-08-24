<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Advertisement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

class AdvertisementExpiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Advertisement $advertisement,
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
            ->subject(__('notifications.advertisement_expired.subject'))
            ->view('emails.notifications.advertisement-expired', [
                'notifiable' => $notifiable,
                'advertisement' => $this->advertisement,
                'subject' => __('notifications.advertisement_expired.subject'),
            ]);
    }

    public function toTelegram(object $notifiable): TelegramMessage
    {
        $advertisementTitle =
            $this->advertisement->title
            ?? __('notifications.advertisement_expired.advertisement_default');

        $url = url('/advertisements');

        return TelegramMessage::create()
            ->content(
                implode("\n\n", [
                    __('notifications.advertisement_expired.greeting', [
                        'name' => $notifiable->name,
                    ]),
                    __('notifications.advertisement_expired.line'),
                    __('notifications.advertisement_expired.advertisement').': '
                    .$advertisementTitle,
                ])
            )
            ->button(
                __('notifications.advertisement_expired.action'),
                $url
            );
    }
}
