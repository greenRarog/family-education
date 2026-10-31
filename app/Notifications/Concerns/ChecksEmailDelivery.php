<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Enums\AdminActionType;
use App\Models\AdminAction;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use App\Notifications\NewResponseNotification;

/** Policy for ordinary notifications only; reset/verification do not use it. */
trait ChecksEmailDelivery
{
    private int $moderationVersion;

    /** @var list<int> */
    private array $moderationUserIds;

    private function captureModerationState(): void
    {
        $this->moderationUserIds = match (true) {
            $this instanceof NewMessageNotification => [
                $this->message->conversation->advertisementResponse->user_id,
                $this->message->conversation->advertisementResponse->advertisement->user_id,
            ],
            $this instanceof NewResponseNotification => [
                $this->response->user_id, $this->response->advertisement->user_id,
            ],
            default => [$this->advertisement->user_id],
        };
        $this->moderationVersion = $this->blockCount();
        $this->afterCommit();
    }

    private function blockCount(): int
    {
        return AdminAction::query()->where('target_type', 'user')->whereIn('target_id', $this->moderationUserIds)
            ->where('action', AdminActionType::USER_BLOCKED)->count();
    }

    public function moderationAllows(User $notifiable): bool
    {
        if ($this instanceof NewResponseNotification && ! $this->advertisementAllowsDelivery()) {
            return false;
        }

        // Jobs serialized before C05 have no moderation version. Reconstruct a
        // conservative boundary from their event, never initialize it at delivery.
        if (! isset($this->moderationUserIds, $this->moderationVersion)) {
            $this->captureModerationState();
            $eventAt = match (true) {
                $this instanceof NewMessageNotification => $this->message->created_at,
                $this instanceof NewResponseNotification => $this->response->created_at,
                default => $this->advertisement->updated_at,
            };
            if ($eventAt === null) {
                return false;
            }
            $this->moderationVersion = AdminAction::query()->where('target_type', 'user')
                ->whereIn('target_id', $this->moderationUserIds)->where('action', AdminActionType::USER_BLOCKED)
                ->where('occurred_at', '<', $eventAt)->count();
        }

        // Count committed blocks for these participants: a block/unblock cycle
        // cannot revive old jobs, even when unrelated journal IDs commit out of order.
        return ! User::query()->whereIn('id', [...$this->moderationUserIds, $notifiable->id])->whereNotNull('block_action_id')->exists()
            && $this->blockCount() === $this->moderationVersion;
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        $current = $notifiable->fresh();
        if ($current === null || ! $this->moderationAllows($current)) {
            return false;
        }

        return match ($channel) {
            'mail' => $current->email === $notifiable->email && $current->emailNotificationsEnabled(),
            'telegram' => $current->telegram_chat_id === $notifiable->telegram_chat_id && $current->telegramNotificationsEnabled(),
            default => false,
        };
    }
}
