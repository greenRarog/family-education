<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdminActionType;
use App\Enums\AdvertisementStatus;
use App\Enums\ReportStatus;
use App\Enums\UserType;
use App\Exceptions\EmailVerificationRequired;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

class AdminActionLogger
{
    /**
     * Call inside the same transaction as the business change, using the actor
     * from authentication. The caller owns target locking and transition rules.
     * Do not pass request bodies or complete model attributes as state snapshots.
     *
     * @param  array<string, bool|string>  $before
     * @param  array<string, bool|string>  $after
     */
    public function record(
        User $actor,
        User|Advertisement|Report $target,
        AdminActionType $action,
        string $reasonCode,
        string $reason,
        array $before,
        array $after,
        ?Report $report = null,
        ?AdminAction $relatedAction = null,
    ): ?AdminAction {
        $connection = $target->getConnection();
        if ($connection->transactionLevel() === 0) {
            throw new LogicException('Administrative actions require the business transaction.');
        }
        $connectionName = $connection->getName();
        $this->assertPersistedOn($actor, $connectionName);
        // Never trust role/email attributes on an old or caller-modified model.
        $currentActor = User::on($connectionName)->findOrFail($actor->getKey());
        if ($currentActor->user_type !== UserType::ADMIN) {
            throw new AuthorizationException;
        }
        if (! $currentActor->hasVerifiedEmail()) {
            throw new EmailVerificationRequired;
        }
        $this->assertPersistedOn($target, $connectionName);
        $targetType = match (true) {
            $target instanceof User => 'user',
            $target instanceof Advertisement => 'advertisement',
            $target instanceof Report => 'report',
        };
        if (($action->targetType() !== null && $action->targetType() !== $targetType)
            || ($action === AdminActionType::APPEAL_REJECTED && $targetType === 'report')) {
            throw new InvalidArgumentException('Action does not match the target type.');
        }
        $reason = trim($reason);
        if (! preg_match('/\A[a-z][a-z0-9_]{0,63}\z/', $reasonCode) || $reason === '') {
            throw new InvalidArgumentException('A reason code and explanation are required.');
        }
        $this->validateState($targetType, $before);
        $this->validateState($targetType, $after);
        ksort($before);
        ksort($after);
        if (array_keys($before) !== array_keys($after)) {
            throw new InvalidArgumentException('Before and after states must describe the same fields.');
        }
        if ($target instanceof Report) {
            if ($report !== null && ! $report->is($target)) {
                throw new InvalidArgumentException('The report target must match the linked report.');
            }
            $report = $target;
        }
        if ($report !== null) {
            $this->assertPersistedOn($report, $connectionName);
        }
        if ($relatedAction !== null) {
            $this->assertPersistedOn($relatedAction, $connectionName);
            $relatedAction = AdminAction::on($connectionName)->findOrFail($relatedAction->getKey());
            if ($relatedAction->target_type !== $targetType || $relatedAction->target_id !== $target->getKey()) {
                throw new InvalidArgumentException('The original decision must concern the same target.');
            }
        }
        if ($action === AdminActionType::APPEAL_REJECTED) {
            if ($relatedAction === null || $before !== $after) {
                throw new InvalidArgumentException('A rejected appeal references a decision and preserves state.');
            }
        } elseif ($before === $after) {
            // No-op repeats are not new administrative actions.
            return null;
        }

        $entry = new AdminAction;
        $entry->setConnection($connectionName);
        $entry->forceFill([
            'actor_id' => $currentActor->id,
            'target_type' => $targetType,
            'target_id' => $target->getKey(),
            'action' => $action,
            'reason_code' => $reasonCode,
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'report_id' => $report?->id,
            'related_action_id' => $relatedAction?->id,
            'occurred_at' => now(),
        ]);
        $entry->save();

        return $entry;
    }

    private function assertPersistedOn(Model $model, ?string $connection): void
    {
        if ($model->getConnection()->getName() !== $connection || ! $model->exists
            || ! $model->newQuery()->whereKey($model->getKey())->exists()) {
            throw new InvalidArgumentException('Audit references must exist on the business connection.');
        }
    }

    /** @param array<string, bool|string> $state */
    private function validateState(string $targetType, array $state): void
    {
        $allowed = match ($targetType) {
            'user' => ['blocked'],
            'advertisement' => ['hidden', 'status'],
            'report' => ['status'],
            default => throw new InvalidArgumentException('Unsupported audit target type.'),
        };
        if ($state === [] || array_diff(array_keys($state), $allowed) !== []) {
            throw new InvalidArgumentException('Only moderation state fields belong in the audit log.');
        }
        foreach ($state as $key => $value) {
            $valid = match ($key) {
                'blocked', 'hidden' => is_bool($value),
                'status' => is_string($value) && ($targetType === 'report'
                    ? ReportStatus::tryFrom($value) !== null
                    : AdvertisementStatus::tryFrom($value) !== null),
                default => false,
            };
            if (! $valid) {
                throw new InvalidArgumentException('Invalid moderation state value.');
            }
        }
    }
}
