<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class InvalidateRestoredMaxLinks extends Command
{
    protected $signature = 'max:invalidate-restored-links {--force : Confirm this is a closed restore target}';

    protected $description = 'Revoke every restored MAX binding; run only on a closed restore target';

    public function handle(): int
    {
        if (! $this->option('force') || config('services.max.enabled')) {
            $this->error('Requires --force and MAX_ENABLED=false on a closed restore target.');

            return self::FAILURE;
        }
        DB::transaction(function (): void {
            // Same ordering as delivery/deletion. Caller must keep HTTP and workers closed.
            User::query()->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('users')->update(['max_user_id' => null, 'max_confirmed_at' => null,
                'max_bot_id' => null, 'max_binding_version' => DB::raw('max_binding_version + 1')]);
            DB::table('notification_settings')->update(['max_enabled' => false]);
            DB::table('max_link_attempts')->delete();
        });
        $this->info('All restored MAX bindings revoked. Independent deletion reconciliation is still required.');

        return self::SUCCESS;
    }
}
