<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdminActionType;
use App\Enums\AdvertisementStatus;
use App\Enums\ReportReason;
use App\Enums\UserType;
use App\Exceptions\EmailVerificationRequired;
use App\Models\AdminAction;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

class UserBlockService
{
    public function __construct(private readonly AdminActionLogger $logger) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, changed: bool, action_id: int|null, expired_advertisements_count: int}
     */
    public function block(User $actor, User $target, array $input, ?Report $report = null): array
    {
        return $this->change($actor, $target, true, $input, $report);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, changed: bool, action_id: int|null, expired_advertisements_count: int}
     */
    public function unblock(User $actor, User $target, array $input): array
    {
        return $this->change($actor, $target, false, $input);
    }

    /** @param array<string, mixed> $input
     * @return array{changed: bool, action_id: int}
     */
    public function rejectAppeal(User $actor, User $target, array $input): array
    {
        $data = Validator::make($input, [
            'decision_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string'],
        ])->validate();
        $reason = trim($data['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Укажите пояснение.']);
        }

        return $target->getConnection()->transaction(function () use ($actor, $target, $data, $reason): array {
            $currentActor = User::on($target->getConnectionName())->findOrFail($actor->id);
            if ($currentActor->user_type !== UserType::ADMIN) {
                throw new AuthorizationException;
            }
            if (! $currentActor->hasVerifiedEmail()) {
                throw new EmailVerificationRequired;
            }
            $current = User::on($target->getConnectionName())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->block_action_id === (int) $data['decision_id'], 409, 'Решение изменилось. Обновите карточку.');
            $previous = AdminAction::on($target->getConnectionName())->where('related_action_id', $current->block_action_id)
                ->where('action', AdminActionType::APPEAL_REJECTED)->where('reason', $reason)->where('actor_id', $actor->id)->first();
            if ($previous !== null) {
                return ['changed' => false, 'action_id' => $previous->id];
            }
            $entry = $this->logger->record($currentActor, $current, AdminActionType::APPEAL_REJECTED,
                'review', $reason, ['blocked' => true], ['blocked' => true], relatedAction: $current->blockingAction);

            return ['changed' => true, 'action_id' => $entry->id];
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{user: User, changed: bool, action_id: int|null, expired_advertisements_count: int}
     */
    private function change(User $actor, User $target, bool $blocked, array $input, ?Report $report = null): array
    {
        $connection = $target->getConnection();
        if (! $actor->exists || ! $target->exists || $actor->getConnection()->getName() !== $connection->getName()) {
            throw new AuthorizationException;
        }

        return $connection->transaction(function () use ($actor, $target, $blocked, $input, $connection, $report): array {
            // Authorization also applies to no-op repeats, which do not call the logger.
            $currentActor = User::on($connection->getName())->findOrFail($actor->id);
            if ($currentActor->user_type !== UserType::ADMIN) {
                throw new AuthorizationException;
            }
            if (! $currentActor->hasVerifiedEmail()) {
                throw new EmailVerificationRequired;
            }
            $current = User::on($connection->getName())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            if ($current->id === $currentActor->id || $current->user_type === UserType::ADMIN) {
                throw ValidationException::withMessages(['user' => 'Нельзя применять блокировку к себе или администратору.']);
            }
            $data = $this->validateReason($blocked, $input);
            if (array_key_exists('decision_id', $input)) {
                Validator::make($input, ['decision_id' => ['nullable', 'integer', 'min:1']])->validate();
                abort_unless($current->block_action_id === ($input['decision_id'] === null ? null : (int) $input['decision_id'])
                    || $current->isBlocked() === $blocked, 409, 'Решение изменилось. Обновите карточку.');
            }
            $wasBlocked = $current->isBlocked();
            if ($wasBlocked === $blocked) {
                return [
                    'user' => $current->load('blockingAction'), 'changed' => false,
                    'action_id' => null, 'expired_advertisements_count' => 0,
                ];
            }
            $original = $current->blockingAction;
            $expiredCount = $blocked ? 0 : $this->closeExpiredAdvertisements($currentActor, $current, now());
            $entry = $this->logger->record(
                $currentActor, $current,
                $blocked ? AdminActionType::USER_BLOCKED : AdminActionType::USER_UNBLOCKED,
                $data['reason_code'], $data['reason'], ['blocked' => $wasBlocked], ['blocked' => $blocked],
                report: $report, relatedAction: $original,
            );
            if (! $entry instanceof AdminAction) {
                throw new LogicException('A user state transition must have an audit entry.');
            }
            // The immutable decision is the single source of reason, actor and time.
            $current->forceFill(['block_action_id' => $blocked ? $entry->id : null])->save();

            return [
                'user' => $current->fresh()->load('blockingAction'), 'changed' => true,
                'action_id' => $entry->id, 'expired_advertisements_count' => $expiredCount,
            ];
        });
    }

    /** @param array<string, mixed> $input
     * @return array{reason_code: string, reason: string}
     */
    private function validateReason(bool $blocked, array $input): array
    {
        $codes = $blocked
            ? array_map(fn (ReportReason $reason) => $reason->value,
                array_filter(ReportReason::cases(), fn (ReportReason $reason) => $reason !== ReportReason::OFF_TOPIC))
            : ['restriction_removed', 'review'];

        return Validator::make([
            'reason_code' => $input['reason_code'] ?? ($blocked ? null : 'restriction_removed'),
            'reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : ($input['reason'] ?? null),
        ], [
            'reason_code' => ['required', 'string', Rule::in($codes)],
            'reason' => ['required', 'string', 'min:'.($blocked ? 10 : 1), 'max:1000'],
        ])->validate();
    }

    private function closeExpiredAdvertisements(User $actor, User $owner, Carbon $now): int
    {
        $count = 0;
        Advertisement::on($owner->getConnectionName())
            ->where('user_id', $owner->id)
            ->where('status', AdvertisementStatus::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $now->copy()->subYear())
            ->lockForUpdate()
            ->chunkById(100, function ($advertisements) use ($actor, $now, &$count): void {
                foreach ($advertisements as $advertisement) {
                    $advertisement->forceFill([
                        'status' => AdvertisementStatus::CLOSED,
                        'closed_at' => $advertisement->closed_at ?? $now,
                    ])->save();
                    $this->logger->record(
                        $actor, $advertisement, AdminActionType::ADVERTISEMENT_EXPIRED,
                        'expired', 'Срок публикации истёк к моменту разблокировки владельца.',
                        ['status' => 'published'], ['status' => 'closed'],
                    );
                    $count++;
                }
            });

        return $count;
    }
}
