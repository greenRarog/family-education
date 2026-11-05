<?php

declare(strict_types=1);

namespace App\Services\Max;

class BotConfiguration
{
    public static function id(): ?string
    {
        $id = (string) config('services.max.bot_id');

        return preg_match('/^[1-9][0-9]*$/D', $id) ? $id : null;
    }

    public static function available(): bool
    {
        return config('services.max.enabled') === true && self::id() !== null
            && is_string(config('services.max.token')) && config('services.max.token') !== ''
            && preg_match('/^[A-Za-z0-9_]{3,100}$/D', (string) config('services.max.username')) === 1
            && strlen((string) config('services.max.webhook_secret')) >= 32;
    }
}
