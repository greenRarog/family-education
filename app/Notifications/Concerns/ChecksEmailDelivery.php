<?php

declare(strict_types=1);

namespace App\Notifications\Concerns;

use App\Enums\AdminActionType;
use App\Models\AdminAction;
use App\Models\User;
use App\Notifications\ConfirmedMaxChannel;
use App\Notifications\NewMessageNotification;
use App\Notifications\NewResponseNotification;

/** Policy for ordinary notifications only; reset/verification do not use it. */
trait ChecksEmailDelivery
{
    private int $moderationVersion;

    /** Captured at construction, never reconstructed for a legacy queued job. */
    private array $telegramVersions = [];

    private array $maxVersions = [];

    private function captureMaxBindings(): void
    {
        $this->maxVersions = User::query()->whereIn('id', $this->accountParticipantIds())
            ->get()->filter(fn (User $user) => $user->hasConfirmedMax())
            ->mapWithKeys(fn (User $user) => [$user->id => [$user->max_binding_version, $user->max_bot_id]])->all();
    }

    private function captureTelegramBindings(): void
    {
        $this->telegramVersions = User::query()->whereIn('id', $this->accountParticipantIds())
            ->get()->filter(fn (User $user) => $user->hasConfirmedTelegram())
            ->mapWithKeys(fn (User $user) => [$user->id => [$user->telegram_binding_version, $user->telegram_bot_id]])->all();
    }

    /** @var list<int> */
    private array $moderationUserIds;

    /** @return list<int> */
    public function accountParticipantIds(): array
    {
        // Legacy jobs must retain their absent version for the existing fallback.
        return $this->moderationUserIds ?? match (true) {
            $this instanceof NewMessageNotification => [$this->message->conversation->advertisementResponse->user_id, $this->message->conversation->advertisementResponse->advertisement->user_id],
            $this instanceof NewResponseNotification => [$this->response->user_id, $this->response->advertisement->user_id],
            default => [$this->advertisement->user_id],
        };
    }

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
        return (int) User::query()->whereIn('id', $this->moderationUserIds)->sum('purged_block_count')
            + AdminAction::query()->where('target_type', 'user')->whereIn('target_id', $this->moderationUserIds)
                ->where('action', AdminActionType::USER_BLOCKED)->count();
    }

    public function moderationAllows(User $notifiable): bool
    {
        if ($notifiable->isAccountDeleted()) {
            return false;
        }
        if ($this instanceof NewResponseNotification && ! $this->advertisementAllowsDelivery()) {
            return false;
        }

        // Jobs serialized before C05 have no moderation version. Reconstruct a
        // conservative boundary from their event, never initialize it at delivery.
        if (! isset($this->moderationUserIds, $this->moderationVersion)) {
            $this->captureModerationState();
            if (User::query()->whereIn('id', $this->moderationUserIds)->where('purged_block_count', '>', 0)->exists()) {
                $this->moderationVersion = -1;

                return false; // Legacy payload has no version; purged history cannot prove it safe.
            }
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
        $ids = array_unique([...$this->moderationUserIds, $notifiable->id]);

        return User::query()->whereIn('id', $ids)->whereNull('account_deleted_at')->count() === count($ids)
            && ! User::query()->whereIn('id', $ids)->whereNotNull('block_action_id')->exists()
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
            'telegram' => ($this->telegramVersions[$current->id] ?? null) === [$current->telegram_binding_version, $current->telegram_bot_id]
                && $current->telegramNotificationsEnabled(),
            ConfirmedMaxChannel::class => ($this->maxVersions[$current->id] ?? null) === [$current->max_binding_version, $current->max_bot_id]
                && $current->maxNotificationsEnabled(),
            default => false,
        };
    }
}
