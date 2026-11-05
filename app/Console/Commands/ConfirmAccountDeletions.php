<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccountDeletion;
use App\Services\AccountDeletionRequestService;
use Illuminate\Console\Command;

class ConfirmAccountDeletions extends Command
{
    protected $signature = 'accounts:confirm-deletions';

    protected $description = 'Retry durable recovery registry acknowledgement for committed account deletions';

    public function handle(AccountDeletionRequestService $service): int
    {
        $confirmed = 0;
        $pending = 0;
        AccountDeletion::query()->whereNull('registry_confirmed_at')->eachById(function (AccountDeletion $deletion) use ($service, &$confirmed, &$pending): void {
            if ($service->confirm($deletion)->registry_confirmed_at !== null) {
                $confirmed++;
            } else {
                $pending++;
            }
        }, column: 'user_id');
        $this->line(json_encode(['confirmed' => $confirmed, 'pending' => $pending], JSON_THROW_ON_ERROR));

        return $pending === 0 ? self::SUCCESS : self::FAILURE;
    }
}
