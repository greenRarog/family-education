<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneTelegramLinks extends Command
{
    protected $signature = 'telegram:prune';

    protected $description = 'Remove expired link secrets and update IDs older than 30 days';

    public function handle(): int
    {
        DB::table('telegram_link_attempts')->where('expires_at', '<=', now())->delete();
        DB::table('telegram_processed_updates')->where('received_at', '<=', now()->subDays(30))->delete();
        $this->info('Expired Telegram records removed.');

        return self::SUCCESS;
    }
}
