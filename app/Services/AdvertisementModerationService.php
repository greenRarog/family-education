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
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;

class AdvertisementModerationService
{
    public function __construct(private readonly AdminActionLogger $logger) {}

    /** @param array<string, mixed> $input
     * @return array{advertisement: Advertisement, changed: bool, action_id: int|null}
     */
    public function change(User $actor, Advertisement $target, bool $hidden, array $input, ?Report $report = null): array
    {
        return $target->getConnection()->transaction(function () use ($actor, $target, $hidden, $input, $report): array {
            $actor = $this->actor($actor, $target);
            // Same owner -> advertisement order as business writes and unblock.
            User::on($target->getConnectionName())->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $current = Advertisement::on($target->getConnectionName())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $data = Validator::make([
                'reason_code' => $input['reason_code'] ?? ($hidden ? null : 'restriction_removed'),
                'reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : ($input['reason'] ?? null),
                'decision_id' => $input['decision_id'] ?? null,
            ], [
                'reason_code' => ['required', Rule::in($hidden ? array_column(ReportReason::cases(), 'value') : ['restriction_removed', 'review'])],
                'reason' => ['required', 'string', 'min:'.($hidden ? 10 : 1), 'max:1000'],
                'decision_id' => ['nullable', 'integer', 'min:1'],
            ])->validate();
            if (array_key_exists('decision_id', $input)) {
                abort_unless($current->hide_action_id === ($data['decision_id'] === null ? null : (int) $data['decision_id'])
                    || $current->isHidden() === $hidden, 409, 'Решение изменилось. Обновите карточку.');
            }
            if ($current->isHidden() === $hidden) {
                return ['advertisement' => $current, 'changed' => false, 'action_id' => null];
            }
            $original = $current->hidingAction;
            if (! $hidden && $current->status === AdvertisementStatus::PUBLISHED
                && $current->published_at !== null && $current->published_at->lte(now()->subYear())) {
                $current->forceFill(['status' => AdvertisementStatus::CLOSED, 'closed_at' => $current->closed_at ?? now()])->save();
                $this->logger->record($actor, $current, AdminActionType::ADVERTISEMENT_EXPIRED, 'expired',
                    'Срок публикации истёк к моменту восстановления объявления.', ['status' => 'published'], ['status' => 'closed']);
            }
            $entry = $this->logger->record($actor, $current,
                $hidden ? AdminActionType::ADVERTISEMENT_HIDDEN : AdminActionType::ADVERTISEMENT_RESTORED,
                $data['reason_code'], $data['reason'], ['hidden' => ! $hidden], ['hidden' => $hidden], report: $report, relatedAction: $original);
            if ($entry === null) {
                throw new LogicException('A moderation transition requires an audit entry.');
            }
            $current->forceFill(['hide_action_id' => $hidden ? $entry->id : null])->save();

            return ['advertisement' => $current->fresh(), 'changed' => true, 'action_id' => $entry->id];
        });
    }

    /** @param array<string, mixed> $input
     * @return array{changed: bool, action_id: int}
     */
    public function rejectAppeal(User $actor, Advertisement $target, array $input): array
    {
        $data = Validator::make([
            'decision_id' => $input['decision_id'] ?? null,
            'reason' => is_string($input['reason'] ?? null) ? trim($input['reason']) : ($input['reason'] ?? null),
        ], ['decision_id' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string']])->validate();

        return $target->getConnection()->transaction(function () use ($actor, $target, $data): array {
            $actor = $this->actor($actor, $target);
            User::on($target->getConnectionName())->whereKey($target->user_id)->lockForUpdate()->firstOrFail();
            $current = Advertisement::on($target->getConnectionName())->whereKey($target->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->hide_action_id === (int) $data['decision_id'], 409, 'Решение изменилось. Обновите карточку.');
            $previous = AdminAction::on($target->getConnectionName())->where('related_action_id', $current->hide_action_id)
                ->where('action', AdminActionType::APPEAL_REJECTED)->where('reason', $data['reason'])->where('actor_id', $actor->id)->first();
            if ($previous !== null) {
                return ['changed' => false, 'action_id' => $previous->id];
            }
            $entry = $this->logger->record($actor, $current, AdminActionType::APPEAL_REJECTED,
                'review', $data['reason'], ['hidden' => true], ['hidden' => true], relatedAction: $current->hidingAction);

            return ['changed' => true, 'action_id' => $entry->id];
        });
    }

    private function actor(User $actor, Advertisement $target): User
    {
        if (! $actor->exists || $actor->getConnection()->getName() !== $target->getConnection()->getName()) {
            throw new AuthorizationException;
        }
        $current = User::on($target->getConnectionName())->findOrFail($actor->id);
        if ($current->user_type !== UserType::ADMIN) {
            throw new AuthorizationException;
        }
        if (! $current->hasVerifiedEmail()) {
            throw new EmailVerificationRequired;
        }

        return $current;
    }
}
