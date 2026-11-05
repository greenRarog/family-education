<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;

trait ConfirmedTelegram
{
    private function confirmTelegramFixture(User $user): void
    {
        config(['services.telegram.enabled' => true, 'services.telegram.token' => '123456:synthetic', 'services.telegram.username' => 'education_test_bot']);
        $user->forceFill(['telegram_chat_id' => (string) (700000 + $user->id), 'telegram_confirmed_at' => now(),
            'telegram_bot_id' => '123456', 'telegram_binding_version' => 1])->save();
    }
}
