<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AccountRetentionService;
use Illuminate\Console\Command;

class PurgeAccountData extends Command
{
    protected $signature = 'accounts:purge-expired';

    protected $description = 'Purge expired conversations, completed moderation cases and unreferenced account tombstones';

    public function handle(AccountRetentionService $retention): int
    {
        $this->line(json_encode($retention->purge(), JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
