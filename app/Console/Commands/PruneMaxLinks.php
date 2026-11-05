<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneMaxLinks extends Command
{
    protected $signature = 'max:prune';

    protected $description = 'Remove expired link secrets and update IDs older than 30 days';

    public function handle(): int
    {
        DB::table('max_link_attempts')->where('expires_at', '<=', now())->delete();
        DB::table('max_processed_updates')->where('received_at', '<=', now()->subDays(30))->delete();
        $this->info('Expired Max records removed.');

        return self::SUCCESS;
    }
}
