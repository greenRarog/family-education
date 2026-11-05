<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountRecoveryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileRestoredAccounts extends Command
{
    protected $signature = 'accounts:reconcile-restored {--database=} {--workers-stopped : Confirm all workers and pollers are stopped}';

    protected $description = 'Reconcile an explicitly closed restore target against the independent deletion registry';

    public function handle(AccountRecoveryService $recovery): int
    {
        if (! $this->option('workers-stopped') || ! app()->isDownForMaintenance()
            || ! $this->option('database') || $this->option('database') !== DB::connection()->getDatabaseName()
            || config('account_deletion.enabled') || config('services.telegram.enabled') || config('services.max.enabled')) {
            $this->error('Requires maintenance mode, exact --database, --workers-stopped and disabled deletion/integrations.');

            return self::FAILURE;
        }
        try {
            $count = $recovery->reconcile();
        } catch (Throwable) {
            $this->error('Reconciliation failed. Keep HTTP and all workers closed; inspect registry/configuration before retry.');

            return self::FAILURE;
        }
        $this->info("Reconciled {$count} records. Maintenance remains enabled; verify the restored service before reopening.");

        return self::SUCCESS;
    }
}
