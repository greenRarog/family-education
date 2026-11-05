<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AccountChannelManager extends ChannelManager
{
    public function send($notifiables, $notification)
    {
        if ($notification instanceof ShouldQueue) {
            parent::send($notifiables, $notification);
        } else {
            $this->sendNow($notifiables, $notification);
        }
    }

    public function sendNow($notifiables, $notification, ?array $channels = null)
    {
        $recipients = $notifiables instanceof Collection ? $notifiables : collect(is_array($notifiables) ? $notifiables : [$notifiables]);
        $ids = $recipients->filter(fn ($recipient) => $recipient instanceof User)->pluck('id')->all();
        if (method_exists($notification, 'accountParticipantIds')) {
            $ids = [...$ids, ...$notification->accountParticipantIds()];
        }
        $ids = array_unique($ids);
        if ($ids === []) {
            parent::sendNow($notifiables, $notification, $channels);

            return;
        }
        // Hold participant locks through provider handoff, including old fetched jobs.
        // Never retry this transaction automatically: external delivery is not reversible.
        DB::transaction(function () use ($ids, $notifiables, $notification, $channels): void {
            $users = User::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            if ($users->count() !== count($ids) || $users->contains(fn (User $user) => $user->isAccountDeleted())) {
                return;
            }
            parent::sendNow($notifiables, $notification, $channels);
        });
    }
}
