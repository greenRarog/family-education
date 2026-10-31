<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AdminActionType;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Notifications\Concerns\ChecksEmailDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\Telegram\TelegramMessage;

class NewResponseNotification extends Notification implements ShouldQueue
{
    use ChecksEmailDelivery, Queueable;

    private ?int $hideVersion = null;

    public function __construct(
        public readonly AdvertisementResponse $response,
        ?int $hideVersion = null,
    ) {
        $this->captureModerationState();
        $this->hideVersion = $hideVersion ?? $this->hideCount();
    }

    private function hideCount(): int
    {
        return AdminAction::query()->where('target_type', 'advertisement')->where('target_id', $this->response->advertisement_id)
            ->where('action', AdminActionType::ADVERTISEMENT_HIDDEN)->count();
    }

    public function advertisementAllowsDelivery(): bool
    {
        if (! isset($this->hideVersion)) {
            if ($this->response->created_at === null) {
                return false;
            }
            // Old serialized jobs must not acquire a new baseline after restore.
            $this->hideVersion = AdminAction::query()->where('target_type', 'advertisement')->where('target_id', $this->response->advertisement_id)
                ->where('action', AdminActionType::ADVERTISEMENT_HIDDEN)->where('occurred_at', '<', $this->response->created_at)->count();
        }

        return Advertisement::query()->whereKey($this->response->advertisement_id)->whereNull('hide_action_id')->exists()
            && $this->hideCount() === $this->hideVersion;
    }

    public function via(object $notifiable): array
    {
        if (! $this->moderationAllows($notifiable)) {
            return [];
        }
        $notifiable = $notifiable->fresh();
        if ($notifiable === null) {
            return [];
        }
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
