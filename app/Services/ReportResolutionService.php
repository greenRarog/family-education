<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdminActionType;
use App\Enums\ReportStatus;
use App\Enums\UserType;
use App\Exceptions\EmailVerificationRequired;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportResolutionService
{
    public function __construct(
        private readonly AdminActionLogger $logger,
        private readonly UserBlockService $blocks,
        private readonly AdvertisementModerationService $advertisements,
    ) {}

    /** @param array<string, mixed> $input */
    public function resolve(User $actor, Report $report, array $input): Report
    {
        return DB::transaction(function () use ($actor, $report, $input): Report {
            $actor = User::query()->findOrFail($actor->id);
            abort_unless($actor->user_type === UserType::ADMIN, 403);
            if (! $actor->hasVerifiedEmail()) {
                throw new EmailVerificationRequired;
            }
            $current = Report::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === ReportStatus::OPEN, 409, 'Жалоба уже обработана. Обновите карточку.');
            $data = Validator::make($input, [
                'status' => ['required', Rule::in(['resolved', 'dismissed'])],
                'resolution_note' => ['required', 'string'],
                'actions' => ['sometimes', 'array'],
                'actions.*' => ['string', 'distinct', Rule::in(['block_user', 'hide_advertisement'])],
                'existing_action_ids' => ['sometimes', 'array'],
                'existing_action_ids.*' => ['integer', 'min:1', 'distinct'],
                'sanction_reason_code' => ['nullable', 'string'],
                'sanction_reason' => ['nullable', 'string'],
            ])->validate();
            $note = trim($data['resolution_note']);
            if ($note === '') {
                throw ValidationException::withMessages(['resolution_note' => 'Укажите пояснение.']);
            }
            $operations = $data['actions'] ?? [];
            $existing = array_map('intval', $data['existing_action_ids'] ?? []);
            if ($data['status'] === 'dismissed' && ($operations !== [] || $existing !== [])) {
                throw ValidationException::withMessages(['actions' => 'Отклонение жалобы не применяет санкции.']);
            }
            $actionIds = [];
            if ($data['status'] === 'resolved') {
                // Match existing moderation writes: user -> advertisement, after the report lock.
                $ad = $current->target_type === 'advertisement' ? Advertisement::query()->find($current->target_id) : null;
                $userId = $current->target_type === 'user' ? $current->target_id : $ad?->user_id;
                $user = $userId === null ? null : User::query()->whereKey($userId)->lockForUpdate()->first();
                $ad = $ad === null ? null : Advertisement::query()->whereKey($ad->id)->lockForUpdate()->first();
                $activeIds = array_filter([$user?->block_action_id, $ad?->hide_action_id]);
                if (array_diff($existing, $activeIds) !== []) {
                    abort(409, 'Мера изменилась. Обновите карточку.');
                }
                $actionIds = $existing;
                $reason = ['reason_code' => $data['sanction_reason_code'] ?? null, 'reason' => $data['sanction_reason'] ?? null];
                foreach ($operations as $operation) {
                    if ($operation === 'block_user') {
                        abort_if($user === null, 404);
                        $result = $this->blocks->block($actor, $user, $reason, $current);
                        $actionIds[] = $result['user']->block_action_id;
                    } else {
                        if ($ad === null) {
                            throw ValidationException::withMessages(['actions' => 'Скрытие применимо только к объявлению жалобы.']);
                        }
                        $result = $this->advertisements->change($actor, $ad, true, $reason, $current);
                        $actionIds[] = $result['advertisement']->hide_action_id;
                    }
                }
                $actionIds = array_values(array_unique(array_filter($actionIds)));
                if ($actionIds === []) {
                    throw ValidationException::withMessages(['actions' => 'Укажите принятую меру.']);
                }
            }
            $current->forceFill([
                'status' => $data['status'], 'resolved_by' => $actor->id,
                'resolution_note' => $note, 'resolved_at' => now(),
            ])->save();
            $current->resolutionActions()->attach($actionIds);
            $this->logger->record($actor, $current,
                $data['status'] === 'resolved' ? AdminActionType::REPORT_RESOLVED : AdminActionType::REPORT_DISMISSED,
                $data['status'], $note, ['status' => 'open'], ['status' => $data['status']]);

            return $current->fresh();
        });
    }
}
