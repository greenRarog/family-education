<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AdvertisementStatus;
use App\Events\AdvertisementExpired;
use App\Models\Advertisement;
use Illuminate\Console\Command;

class CloseExpiredAdvertisements extends Command
{
    protected $signature = 'advertisements:close-expired';

    protected $description = 'Close published advertisements older than one year';

    public function handle(): int
    {
        $expiredBefore = now()->subYear();

        $count = 0;

        Advertisement::query()
            ->where('status', AdvertisementStatus::PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', $expiredBefore)
            ->eachById(function (Advertisement $advertisement) use (&$count) {
                $advertisement->update([
                    'status' => AdvertisementStatus::CLOSED,
                ]);

                AdvertisementExpired::dispatch($advertisement);

                $count++;
            });

        $this->info("Closed {$count} expired advertisement(s).");

        return self::SUCCESS;
    }
}
