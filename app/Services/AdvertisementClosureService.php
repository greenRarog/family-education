<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdminActionType;
use App\Enums\AdvertisementStatus;
use App\Events\AdvertisementExpired;
use App\Models\Advertisement;
use App\Models\User;
use Illuminate\Support\Carbon;

class AdvertisementClosureService
{
    public function __construct(private readonly AdminActionLogger $logger) {}

    public function close(Advertisement $target, bool $onlyExpired = true, ?User $actor = null, ?string $reason = null, ?Carbon $at = null): bool
    {
        return $target->getConnection()->transaction(function () use ($target, $onlyExpired, $actor, $reason, $at): bool {
            // The same owner -> advertisement lock order as deletion/business writes.
            User::on($target->getConnectionName())->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $current = Advertisement::on($target->getConnectionName())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $at ??= now();
            $expired = $current->status === AdvertisementStatus::PUBLISHED && $current->publicationExpired($at);
            if ($current->isAccountDeleted() || $current->status === AdvertisementStatus::CLOSED || ($onlyExpired && ! $expired)) {
                $target->setRawAttributes($current->getAttributes(), true);

                return false;
            }
            $current->forceFill(['status' => AdvertisementStatus::CLOSED, 'closed_at' => $current->closed_at ?? $at])->save();
            if ($expired && $actor !== null) {
                $this->logger->record($actor, $current, AdminActionType::ADVERTISEMENT_EXPIRED, 'expired',
                    $reason ?? 'Срок публикации истёк.', ['status' => 'published'], ['status' => 'closed']);
            }
            if ($expired) {
                AdvertisementExpired::dispatch($current);
            }
            $target->setRawAttributes($current->getAttributes(), true);

            return true;
        });
    }
}
