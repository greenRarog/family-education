<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\DatabaseAccountDeletionRegistry;
use Illuminate\Console\Command;
use Throwable;

class InitializeDeletionRegistry extends Command
{
    protected $signature = 'accounts:registry-init {--database= : Exact independent database name to initialize}';

    protected $description = 'Provision deletion registry tables in the explicitly selected independent database';

    public function handle(DatabaseAccountDeletionRegistry $registry): int
    {
        try {
            if (! $this->option('database') || $this->option('database') !== $registry->connection()->getDatabaseName()) {
                $this->error('Specify the exact independent registry database using --database.');

                return self::FAILURE;
            }
            $registry->initialize();
        } catch (Throwable) {
            $this->error('Registry initialization failed. Check the independent connection and its permissions.');

            return self::FAILURE;
        }
        $this->info('Independent deletion registry initialized.');

        return self::SUCCESS;
    }
}
