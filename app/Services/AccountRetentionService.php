<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountDeletion;
use App\Models\Advertisement;
use App\Models\AdvertisementResponse;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AccountRetentionService
{
    public function __construct(private readonly ModerationRetentionService $moderation, private readonly AccountQueueCleaner $queue) {}

    /** @return array{conversations: int, moderation_records: int, advertisements: int, users: int} */
    public function purge(?string $connection = null): array
    {
        $db = DB::connection($connection);
        $result = ['conversations' => 0, 'moderation_records' => 0, 'advertisements' => 0, 'users' => 0];
        // Reconcile any late queued rows before deleting identifiers needed to attribute them.
        User::on($db->getName())->whereNotNull('account_deleted_at')->eachById(function (User $user) use ($db): void {
            $this->queue->clean($db, $user->id);
            $db->table('sessions')->where('user_id', $user->id)->delete();
        });
        Conversation::on($db->getName())->where('expires_at', '<=', now())->eachById(function (Conversation $conversation) use ($db, &$result): void {
            $db->transaction(function () use ($conversation, $db, &$result): void {
                $current = Conversation::on($db->getName())->whereKey($conversation->id)->lockForUpdate()->first();
                if ($current === null || ! $current->isExpired()) {
                    return;
                }
                AdvertisementResponse::on($db->getName())->whereKey($current->advertisement_response_id)->delete();
                $result['conversations']++;
            });
        });
        $result['moderation_records'] = $this->moderation->purge($db->getName());
        Advertisement::on($db->getName())->whereNotNull('account_deleted_at')->eachById(function (Advertisement $ad) use ($db, &$result): void {
            $db->transaction(function () use ($ad, $db, &$result): void {
                $ad = Advertisement::on($db->getName())->whereKey($ad->id)->lockForUpdate()->first();
                if ($ad === null || $ad->responses()->exists()
                    || $db->table('reports')->where('target_type', 'advertisement')->where('target_id', $ad->id)->exists()
                    || $db->table('admin_actions')->where('target_type', 'advertisement')->where('target_id', $ad->id)->exists()) {
                    return;
                }
                $ad->delete();
                $result['advertisements']++;
            });
        });
        AccountDeletion::on($db->getName())->where('deleted_at', '<=', now()->subDays(35))
            ->whereNotNull('registry_confirmed_at')->whereNotNull('backups_cleared_at')
            ->eachById(function (AccountDeletion $deletion) use ($db, &$result): void {
                $db->transaction(function () use ($deletion, $db, &$result): void {
                    $user = User::on($db->getName())->whereKey($deletion->user_id)->lockForUpdate()->first();
                    if ($user !== null) {
                        if (! $user->isAccountDeleted() || $user->advertisements()->exists()
                            || $user->advertisementResponses()->exists() || $user->messages()->exists()
                            || $db->table('reports')->where(fn ($q) => $q->where('reporter_id', $user->id)->orWhere('resolved_by', $user->id)
                                ->orWhere(fn ($q) => $q->where('target_type', 'user')->where('target_id', $user->id)))->exists()
                            || $db->table('admin_actions')->where(fn ($q) => $q->where('actor_id', $user->id)
                                ->orWhere(fn ($q) => $q->where('target_type', 'user')->where('target_id', $user->id)))->exists()) {
                            return;
                        }
                        $user->delete();
                        $result['users']++;
                    }
                    $deletion->delete();
                });
            }, column: 'user_id');

        return $result;
    }
}
