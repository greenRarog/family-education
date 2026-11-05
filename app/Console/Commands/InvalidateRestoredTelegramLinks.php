<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InvalidateRestoredTelegramLinks extends Command
{
    protected $signature = 'telegram:invalidate-restored-links {--force : Confirm this is a closed restore target}';

    protected $description = 'Revoke every restored Telegram binding; run only on a closed restore target';

    public function handle(): int
    {
        if (! $this->option('force') || config('services.telegram.enabled')) {
            $this->error('Requires --force and TELEGRAM_ENABLED=false on a closed restore target.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            // Same ordering as delivery/deletion. Caller must keep HTTP and workers closed.
            User::query()->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('users')->update(['telegram_chat_id' => null, 'telegram_confirmed_at' => null,
                'telegram_bot_id' => null, 'telegram_binding_version' => DB::raw('telegram_binding_version + 1')]);
            DB::table('notification_settings')->update(['telegram_enabled' => false]);
            DB::table('telegram_link_attempts')->delete();
        });
        $this->info('All restored Telegram bindings revoked. Independent deletion reconciliation is still required.');

        return self::SUCCESS;
    }
}
