<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AdvertisementStatus;
use App\Models\Advertisement;
use Illuminate\Console\Command;

class CloseExpiredAdvertisements extends Command
{
    protected $signature = 'advertisements:close-expired';

    protected $description = 'Close published advertisements older than one year';

    public function handle(): int
    {
        $expiredBefore = now()->subYear();

        $count = Advertisement::query()
            ->where('status', AdvertisementStatus::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $expiredBefore)
            ->update([
                'status' => AdvertisementStatus::CLOSED,
            ]);

        $this->info("Closed {$count} expired advertisement(s).");

        return self::SUCCESS;
    }
}
