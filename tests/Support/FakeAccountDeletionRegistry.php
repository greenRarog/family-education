<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\AccountDeletionRegistry;
use App\Models\AccountDeletion;
use RuntimeException;

/** Fixture only: deliberately not a production implementation of the registry. */
class FakeAccountDeletionRegistry implements AccountDeletionRegistry
{
    public bool $acknowledge = true;

    public bool $fail = false;

    public array $recorded = [];

    public function available(): bool
    {
        return true;
    }

    public function record(AccountDeletion $deletion): bool
    {
        if ($this->fail) {
            throw new RuntimeException('Fixture registry unavailable.');
        }
        $this->recorded[] = $deletion->user_id;

        return $this->acknowledge;
    }
}
