<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Enums\UserType;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class TelegramLinkService
{
    public function status(User $user): array
    {
        return ['available' => BotConfiguration::available(), 'connected' => $user->hasConfirmedTelegram(),
            'needs_reconnect' => $user->telegram_chat_id !== null && ! $user->hasConfirmedTelegram(),
            'binding_version' => $user->telegram_binding_version];
    }

    public function browser(User $actor, string $session, string $operation, array $input = []): array
    {
        try {
            return DB::transaction(function () use ($actor, $session, $operation, $input): array {
                $user = User::query()->whereKey($actor->id)->lockForUpdate()->first();
                if ($user === null || $user->isAccountDeleted() || (int) $user->session_version !== (int) $actor->session_version) {
                    return $this->error('unauthenticated', 401);
                }
                if (! in_array($user->user_type, [UserType::FAMILY, UserType::TEACHER], true)) {
                    return $this->error('forbidden', 403);
                }
                if ($operation === 'status') {
                    return $this->status($user);
                }
                // Per-user lock serializes check+hit across sessions. Cache must be shared.
                $limits = $operation === 'create' ? [[5, 60], [20, 86400]] : [[5, 60]];
                foreach ($limits as [$max, $seconds]) {
                    $key = "telegram:$operation:{$user->id}:$seconds";
                    if (RateLimiter::tooManyAttempts($key, $max)) {
                        return [...$this->error('rate_limited', 429), 'retry_after' => RateLimiter::availableIn($key)];
                    }
                }
                foreach ($limits as [$max, $seconds]) {
                    RateLimiter::hit("telegram:$operation:{$user->id}:$seconds", $seconds);
                }
                if ($operation === 'unlink') {
                    DB::table('telegram_link_attempts')->where('user_id', $user->id)->delete();
                    if ($user->telegram_chat_id !== null || $user->telegram_confirmed_at !== null) {
                        $user->forceFill(['telegram_chat_id' => null, 'telegram_confirmed_at' => null,
                            'telegram_bot_id' => null, 'telegram_binding_version' => $user->telegram_binding_version + 1])->save();
                    }
                    $this->disableNotifications($user);

                    return $this->status($user);
                }
                if (! BotConfiguration::available()) {
                    return $this->error('telegram_unavailable', 503);
                }
                if ($operation === 'create') {
                    if ($user->hasConfirmedTelegram()) {
                        return $this->error('telegram_already_connected', 409);
                    }
                    DB::table('telegram_link_attempts')->where('user_id', $user->id)->delete();
                    $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $id = (string) Str::uuid();
                    $expires = now()->addMinutes(10)->startOfSecond();
                    DB::table('telegram_link_attempts')->insert([
                        'id' => $id, 'user_id' => $user->id, 'nonce_hash' => hash('sha256', $nonce),
                        'session_hash' => $this->secret($session), 'session_version' => $user->session_version,
                        'bot_id' => BotConfiguration::id(), 'created_at' => now(), 'expires_at' => $expires,
                    ]);

                    return ['attempt_id' => $id, 'url' => 'https://t.me/'.config('services.telegram.username').'?start='.$nonce,
                        'expires_at' => $expires->toIso8601String()];
                }
                $attempt = DB::table('telegram_link_attempts')->where('id', $input['attempt_id'])->where('user_id', $user->id)->lockForUpdate()->first();
                if ($attempt === null || ! hash_equals($attempt->session_hash, $this->secret($session))
                    || (int) $attempt->session_version !== $user->session_version || $attempt->bot_id !== BotConfiguration::id()) {
                    return $this->error('telegram_invalid', 422);
                }
                if (Carbon::parse($attempt->expires_at)->lessThanOrEqualTo(now())) {
                    DB::table('telegram_link_attempts')->where('id', $attempt->id)->delete();

                    return $this->error('telegram_expired', 422);
                }
                if ($attempt->confirmed_version !== null) {
                    return $user->hasConfirmedTelegram() && (int) $attempt->confirmed_version === $user->telegram_binding_version
                        ? $this->status($user) : $this->error('telegram_invalid', 422);
                }
                if ($attempt->code_hash === null || ! hash_equals($attempt->code_hash, $this->secret($attempt->id.':'.$input['code']))) {
                    // Return, do not throw: failed-code counters must commit even for HTTP 422.
                    $query = DB::table('telegram_link_attempts')->where('id', $attempt->id);
                    if ($attempt->wrong_codes >= 4) {
                        $query->delete();
                    } else {
                        $query->increment('wrong_codes');
                    }

                    return $this->error($attempt->wrong_codes >= 4 ? 'telegram_attempt_cancelled' : 'telegram_invalid', 422);
                }
                $user->forceFill(['telegram_chat_id' => $attempt->candidate_chat_id, 'telegram_confirmed_at' => now(),
                    'telegram_bot_id' => $attempt->bot_id, 'telegram_binding_version' => $user->telegram_binding_version + 1])->save();
                $this->disableNotifications($user);
                DB::table('telegram_link_attempts')->where('id', $attempt->id)->update([
                    'code_hash' => null, 'candidate_chat_id' => null, 'confirmed_version' => $user->telegram_binding_version,
                ]);

                return $this->status($user);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->error('telegram_conflict', 409);
        }
    }

    /** Called only by the authenticated outbound poller, never by browser input. */
    public function update(array $update, BotApi $bot): void
    {
        if (! BotConfiguration::available() || ! isset($update['update_id']) || ! is_int($update['update_id']) || $update['update_id'] < 0) {
            return;
        }
        $code = DB::transaction(function () use ($update): ?array {
            if (! DB::table('telegram_processed_updates')->insertOrIgnore([
                'bot_id' => BotConfiguration::id(), 'update_id' => $update['update_id'], 'received_at' => now(),
            ])) {
                return null;
            }
            $message = $update['message'] ?? [];
            $chat = $message['chat']['id'] ?? null;
            if (($message['chat']['type'] ?? null) !== 'private' || ($message['from']['is_bot'] ?? true) !== false
                || ! is_int($chat) || $chat < 1 || ($message['from']['id'] ?? null) !== $chat
                || ! is_string($message['text'] ?? null)) {
                return null;
            }
            if (preg_match('/^\/start(?:@'.preg_quote(config('services.telegram.username'), '/').')?$/Di', $message['text'])) {
                $key = 'telegram-help:'.BotConfiguration::id();
                if (! RateLimiter::tooManyAttempts($key.':'.$chat, 1) && ! RateLimiter::tooManyAttempts($key, 30)) {
                    RateLimiter::hit($key.':'.$chat, 60);
                    RateLimiter::hit($key, 60);

                    return [(string) $chat, null, $message['from']['language_code'] ?? 'ru'];
                }

                return null;
            }
            if (! preg_match('/^\/start(?:@'.preg_quote(config('services.telegram.username'), '/').')? ([A-Za-z0-9_-]{43})$/Di', $message['text'], $matches)) {
                return null;
            }
            $hash = hash('sha256', $matches[1]);
            $owner = DB::table('telegram_link_attempts')->where('nonce_hash', $hash)->value('user_id');
            if ($owner === null) {
                return null;
            }
            $user = User::query()->whereKey($owner)->lockForUpdate()->first();
            $attempt = DB::table('telegram_link_attempts')->where('nonce_hash', $hash)->lockForUpdate()->first();
            if ($user === null || $attempt === null || $user->isAccountDeleted()
                || (int) $attempt->session_version !== $user->session_version || $attempt->bot_id !== BotConfiguration::id()
                || Carbon::parse($attempt->expires_at)->lessThanOrEqualTo(now())) {
                return null;
            }
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            DB::table('telegram_link_attempts')->where('id', $attempt->id)->update([
                'nonce_hash' => null, 'candidate_chat_id' => (string) $chat, 'code_hash' => $this->secret($attempt->id.':'.$code),
            ]);

            return [(string) $chat, $code, $user->preferredLocale()];
        });
        if ($code !== null) {
            // At most one send attempt: a lost response requires a new site attempt.
            if ($code[1] === null) {
                $bot->instruction($code[0], $code[2]);
            } else {
                $bot->code(...$code);
            }
        }
    }

    private function secret(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    private function disableNotifications(User $user): void
    {
        $setting = NotificationSetting::firstOrNew(['user_id' => $user->id]);
        if (! $setting->exists) {
            $setting->email_enabled = true;
        }
        $setting->telegram_enabled = false;
        $setting->save();
    }

    private function error(string $code, int $status): array
    {
        return ['code' => $code, '_status' => $status];
    }
}
