<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AccountDeletionRegistry;
use App\Models\AccountDeletion;

class UnavailableAccountDeletionRegistry implements AccountDeletionRegistry
{
    public function available(): bool
    {
        return false;
    }

    public function record(AccountDeletion $deletion): bool
    {
        return false;
    }
}
