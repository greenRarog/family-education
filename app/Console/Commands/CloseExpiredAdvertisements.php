<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AdvertisementStatus;
use App\Models\Advertisement;
use App\Services\AdvertisementClosureService;
use Illuminate\Console\Command;

class CloseExpiredAdvertisements extends Command
{
    protected $signature = 'advertisements:close-expired';

    protected $description = 'Close published advertisements older than one year';

    public function handle(AdvertisementClosureService $closure): int
    {
        $at = now();
        $count = 0;
        Advertisement::query()->where('status', AdvertisementStatus::PUBLISHED)
            ->whereNull('account_deleted_at')->expired($at)
            ->eachById(function (Advertisement $advertisement) use ($closure, $at, &$count): void {
                if ($closure->close($advertisement, at: $at)) {
                    $count++;
                }
            });
        $this->info("Closed {$count} expired advertisement(s).");

        return self::SUCCESS;
    }
}
