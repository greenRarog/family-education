<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\AccountDeletion;

interface AccountDeletionRegistry
{
    /** Whether an independently durable recovery registry has been configured. */
    public function available(): bool;

    /** Idempotently persist ID, T0, policy and applied result outside the restored DB.
     * Return true only after durable acknowledgement. Never export the status secret.
     */
    public function record(AccountDeletion $deletion): bool;
}
