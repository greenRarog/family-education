<?php

declare(strict_types=1);

namespace App\Services\Telegram;

class BotConfiguration
{
    public static function id(): ?string
    {
        $token = config('services.telegram.token');

        return is_string($token) && preg_match('/^([1-9][0-9]*):[A-Za-z0-9_-]+$/D', $token, $matches) ? $matches[1] : null;
    }

    public static function available(): bool
    {
        return config('services.telegram.enabled') === true && self::id() !== null
            && is_string(config('services.telegram.username'))
            && preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/D', config('services.telegram.username')) === 1;
    }
}
