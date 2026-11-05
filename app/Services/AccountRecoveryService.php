<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountDeletion;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AccountRecoveryService
{
    public function __construct(private readonly DatabaseAccountDeletionRegistry $registry,
        private readonly AccountDeletionService $deletion, private readonly AccountQueueCleaner $queue,
        private readonly AccountRetentionService $retention) {}

    /** Caller must keep HTTP and every worker closed until all steps succeed. */
    public function reconcile(): int
    {
        if (! $this->registry->available()) {
            throw new RuntimeException('Independent deletion registry unavailable.');
        }
        // A restored committed outbox may not have reached the registry before the snapshot.
        foreach (AccountDeletion::query()->cursor() as $deletion) {
            if (! $this->registry->record($deletion)) {
                throw new RuntimeException('Restored outbox conflicts with independent registry.');
            }
        }
        // Validate the entire external stream before altering the restored database.
        $records = iterator_to_array($this->registry->records());
        DB::transaction(function () use ($records): void {
            foreach ($records as $record) {
                $user = User::query()->find($record['user_id']);
                if ($user !== null) {
                    $deletion = $this->deletion->delete($user, Carbon::createFromTimestampUTC($record['deleted_at']));
                    if ($deletion === null || $deletion->deleted_at->timestamp !== $record['deleted_at']
                        || $deletion->policy_version !== $record['policy_version']) {
                        throw new RuntimeException('Restored account conflicts with independent registry.');
                    }
                    $deletion->forceFill(['registry_confirmed_at' => now()])->save();
                }
            }
            foreach (['sessions', 'password_reset_tokens', 'jobs', 'failed_jobs', 'telegram_link_attempts', 'max_link_attempts'] as $table) {
                DB::table($table)->delete();
            }
            DB::table('users')->update(['remember_token' => null,
                'session_version' => DB::raw('session_version + 1'), 'telegram_chat_id' => null,
                'telegram_confirmed_at' => null, 'telegram_bot_id' => null,
                'telegram_binding_version' => DB::raw('telegram_binding_version + 1'),
                'max_user_id' => null, 'max_confirmed_at' => null, 'max_bot_id' => null,
                'max_binding_version' => DB::raw('max_binding_version + 1')]);
            DB::table('notification_settings')->update(['telegram_enabled' => false, 'max_enabled' => false]);
            // IDs recorded after the snapshot must never be assigned to a new registration.
            $maxId = max([(int) DB::table('users')->max('id'), ...array_column($records, 'user_id')]);
            if ($maxId > 0 && DB::getDriverName() === 'pgsql') {
                DB::select("SELECT setval(pg_get_serial_sequence('users', 'id'), GREATEST(?, (SELECT last_value FROM users_id_seq)), true)", [$maxId]);
            } elseif ($maxId > 0 && DB::getDriverName() === 'sqlite') {
                $maxId = max($maxId, (int) DB::table('sqlite_sequence')->where('name', 'users')->value('seq'));
                DB::table('sqlite_sequence')->updateOrInsert(['name' => 'users'], ['seq' => $maxId]);
            }
        });
        $this->queue->clearRestoredRedisQueues();
        $this->retention->purge();

        return count($records);
    }
}
