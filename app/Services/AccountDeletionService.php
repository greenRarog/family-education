<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AdvertisementStatus;
use App\Enums\UserType;
use App\Models\AccountDeletion;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use LogicException;

/** Internal deletion operation; recovery may supply the original durable T0. */
class AccountDeletionService
{
    public function __construct(private readonly AccountQueueCleaner $queue) {}

    public function delete(User $user, ?Carbon $deletedAt = null): ?AccountDeletion
    {
        $db = $user->getConnection();

        return $db->transaction(function () use ($user, $db, $deletedAt): ?AccountDeletion {
            $connection = $db->getName();
            $current = User::on($connection)->whereKey($user->id)->lockForUpdate()->first();
            $previous = AccountDeletion::on($connection)->find($user->id);
            if ($previous !== null) {
                return $previous;
            }
            if ($current === null) {
                return null; // Already physically purged; never recreate an identity.
            }
            if ($current->user_type === UserType::ADMIN) {
                throw new AuthorizationException('Only an existing family or teacher account can be deleted.');
            }
            if ($current->isAccountDeleted()) {
                throw new LogicException('Deleted account is missing its recovery outbox record.');
            }
            $at = ($deletedAt ?? now())->copy()->startOfSecond();
            $email = $current->email;
            $ads = Advertisement::on($connection)->where('user_id', $current->id)->orderBy('id')->lockForUpdate()->get();
            $responses = AdvertisementResponse::on($connection)->where(function ($query) use ($current, $ads): void {
                $query->where('user_id', $current->id)->orWhereIn('advertisement_id', $ads->modelKeys());
            })->pluck('id');
            Conversation::on($connection)->whereIn('advertisement_response_id', $responses)
                ->whereNull('account_deleted_at')->update([
                    'account_deleted_at' => $at,
                    'expires_at' => $at->copy()->addMonthsNoOverflow(12),
                ]);
            foreach ($ads as $ad) {
                $ad->children()->detach();
                $ad->subjects()->detach();
                $ad->forceFill([
                    'account_deleted_at' => $at, 'status' => AdvertisementStatus::CLOSED,
                    'description' => '', 'subject' => null, 'format' => null, 'lesson_type' => null,
                    'language' => '', 'city_id' => null, 'district_id' => null, 'metro_station_id' => null,
                    'participant_age_from' => null, 'participant_age_to' => null,
                    'published_at' => null, 'closed_at' => $at, 'created_at' => null,
                ])->save();
            }
            $current->family()->delete();
            $current->teacherProfile()->delete();
            $current->notificationSetting()->delete();
            $db->table('sessions')->where('user_id', $current->id)->delete();
            $db->table('telegram_link_attempts')->where('user_id', $current->id)->delete();
            $db->table('max_link_attempts')->where('user_id', $current->id)->delete();
            $db->table('password_reset_tokens')->where('email', $email)->delete();
            $this->queue->clean($db, $current->id);
            $current->forceFill([
                'account_deleted_at' => $at, 'name' => 'Удалённый аккаунт',
                'email' => 'deleted-'.Str::uuid().'@account.invalid',
                'telegram_chat_id' => null, 'telegram_confirmed_at' => null, 'telegram_bot_id' => null,
                'telegram_binding_version' => $current->telegram_binding_version + 1, 'email_verified_at' => null,
                'max_user_id' => null, 'max_confirmed_at' => null, 'max_bot_id' => null,
                'max_binding_version' => $current->max_binding_version + 1,
                'password' => Str::random(80), 'remember_token' => null,
                'locale' => 'ru', 'user_type' => UserType::FAMILY, 'created_at' => null,
            ])->save();
            $deletion = new AccountDeletion;
            $deletion->setConnection($connection);
            $deletion->forceFill([
                'user_id' => $current->id, 'deleted_at' => $at, 'policy_version' => 'D02-2026-11-02',
            ])->save();

            return $deletion;
        });
    }
}
