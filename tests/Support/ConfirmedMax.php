<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;

trait ConfirmedMax
{
    private function confirmMaxFixture(User $user): void
    {
        config(['services.max.enabled' => true, 'services.max.token' => 'synthetic-token', 'services.max.bot_id' => '123456', 'services.max.webhook_secret' => str_repeat('s', 32), 'services.max.username' => 'education_test_bot']);
        $user->forceFill(['max_user_id' => (string) (700000 + $user->id), 'max_confirmed_at' => now(),
            'max_bot_id' => '123456', 'max_binding_version' => 1])->save();
    }
}
