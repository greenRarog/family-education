<?php

declare(strict_types=1);

namespace App\Services\Max;

use App\Enums\UserType;
use App\Models\NotificationSetting;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class MaxLinkService
{
    public function status(User $user): array
    {
        return ['available' => BotConfiguration::available(), 'connected' => $user->hasConfirmedMax(),
            'needs_reconnect' => $user->max_user_id !== null && ! $user->hasConfirmedMax(),
            'binding_version' => $user->max_binding_version];
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
                    $key = "max:$operation:{$user->id}:$seconds";
                    if (RateLimiter::tooManyAttempts($key, $max)) {
                        return [...$this->error('rate_limited', 429), 'retry_after' => RateLimiter::availableIn($key)];
                    }
                }
                foreach ($limits as [$max, $seconds]) {
                    RateLimiter::hit("max:$operation:{$user->id}:$seconds", $seconds);
                }
                if ($operation === 'unlink') {
                    DB::table('max_link_attempts')->where('user_id', $user->id)->delete();
                    if ($user->max_user_id !== null || $user->max_confirmed_at !== null) {
                        $user->forceFill(['max_user_id' => null, 'max_confirmed_at' => null,
                            'max_bot_id' => null, 'max_binding_version' => $user->max_binding_version + 1])->save();
                    }
                    $this->disableNotifications($user);

                    return $this->status($user);
                }
                if (! BotConfiguration::available()) {
                    return $this->error('max_unavailable', 503);
                }
                if ($operation === 'create') {
                    if ($user->hasConfirmedMax()) {
                        return $this->error('max_already_connected', 409);
                    }
                    DB::table('max_link_attempts')->where('user_id', $user->id)->delete();
                    $nonce = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
                    $id = (string) Str::uuid();
                    $expires = now()->addMinutes(10)->startOfSecond();
                    DB::table('max_link_attempts')->insert([
                        'id' => $id, 'user_id' => $user->id, 'nonce_hash' => hash('sha256', $nonce),
                        'session_hash' => $this->secret($session), 'session_version' => $user->session_version,
                        'bot_id' => BotConfiguration::id(), 'created_at' => now(), 'expires_at' => $expires,
                    ]);

                    return ['attempt_id' => $id, 'url' => 'https://max.ru/'.config('services.max.username').'?start='.$nonce,
                        'expires_at' => $expires->toIso8601String()];
                }
                $attempt = DB::table('max_link_attempts')->where('id', $input['attempt_id'])->where('user_id', $user->id)->lockForUpdate()->first();
                if ($attempt === null || ! hash_equals($attempt->session_hash, $this->secret($session))
                    || (int) $attempt->session_version !== $user->session_version || $attempt->bot_id !== BotConfiguration::id()) {
                    return $this->error('max_invalid', 422);
                }
                if (Carbon::parse($attempt->expires_at)->lessThanOrEqualTo(now())) {
                    DB::table('max_link_attempts')->where('id', $attempt->id)->delete();

                    return $this->error('max_expired', 422);
                }
                if ($attempt->confirmed_version !== null) {
                    return $user->hasConfirmedMax() && (int) $attempt->confirmed_version === $user->max_binding_version
                        ? $this->status($user) : $this->error('max_invalid', 422);
                }
                if ($attempt->code_hash === null || ! hash_equals($attempt->code_hash, $this->secret($attempt->id.':'.$input['code']))) {
                    // Return, do not throw: failed-code counters must commit even for HTTP 422.
                    $query = DB::table('max_link_attempts')->where('id', $attempt->id);
                    if ($attempt->wrong_codes >= 4) {
                        $query->delete();
                    } else {
                        $query->increment('wrong_codes');
                    }

                    return $this->error($attempt->wrong_codes >= 4 ? 'max_attempt_cancelled' : 'max_invalid', 422);
                }
                $user->forceFill(['max_user_id' => $attempt->candidate_chat_id, 'max_confirmed_at' => now(),
                    'max_bot_id' => $attempt->bot_id, 'max_binding_version' => $user->max_binding_version + 1])->save();
                $this->disableNotifications($user);
                DB::table('max_link_attempts')->where('id', $attempt->id)->update([
                    'code_hash' => null, 'candidate_chat_id' => null, 'confirmed_version' => $user->max_binding_version,
                ]);

                return $this->status($user);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->error('max_conflict', 409);
        }
    }

    /** Only authenticated provider webhooks may supply bot_started events. */
    public function update(array $update, BotApi $bot): void
    {
        $recipient = $update['user']['user_id'] ?? null;
        if (! BotConfiguration::available() || ($update['update_type'] ?? null) !== 'bot_started'
            || ! is_int($recipient) || $recipient < 1 || ($update['user']['is_bot'] ?? true) !== false
            || ! is_int($update['timestamp'] ?? null)) {
            return;
        }
        $nonce = $update['payload'] ?? null;
        if ($nonce !== null && (! is_string($nonce) || ! preg_match('/^[A-Za-z0-9_-]{43}$/D', $nonce))) {
            return;
        }
        // MAX has no Telegram update_id. Keep only a stable digest, never the raw event.
        $eventId = hash('sha256', json_encode([$update['timestamp'], $recipient, $nonce], JSON_THROW_ON_ERROR));
        $code = DB::transaction(function () use ($recipient, $nonce, $eventId): ?array {
            if (! DB::table('max_processed_updates')->insertOrIgnore([
                'bot_id' => BotConfiguration::id(), 'update_id' => $eventId, 'received_at' => now(),
            ])) {
                return null;
            }
            if ($nonce === null) {
                $key = 'max-help:'.BotConfiguration::id();
                if (! RateLimiter::tooManyAttempts($key.':'.$recipient, 1) && ! RateLimiter::tooManyAttempts($key, 30)) {
                    RateLimiter::hit($key.':'.$recipient, 60);
                    RateLimiter::hit($key, 60);

                    return [(string) $recipient, null, 'ru'];
                }

                return null;
            }
            $hash = hash('sha256', $nonce);
            $owner = DB::table('max_link_attempts')->where('nonce_hash', $hash)->value('user_id');
            if ($owner === null) {
                return null;
            }
            $user = User::query()->whereKey($owner)->lockForUpdate()->first();
            $attempt = DB::table('max_link_attempts')->where('nonce_hash', $hash)->lockForUpdate()->first();
            if ($user === null || $attempt === null || $user->isAccountDeleted()
                || (int) $attempt->session_version !== $user->session_version || $attempt->bot_id !== BotConfiguration::id()
                || Carbon::parse($attempt->expires_at)->lessThanOrEqualTo(now())) {
                return null;
            }
            $code = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            DB::table('max_link_attempts')->where('id', $attempt->id)->update([
                'nonce_hash' => null, 'candidate_chat_id' => (string) $recipient, 'code_hash' => $this->secret($attempt->id.':'.$code),
            ]);

            return [(string) $recipient, $code, $user->preferredLocale()];
        });
        if ($code !== null) {
            // Lost provider response requires a new website attempt, never a different replacement code.
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
        $setting->max_enabled = false;
        $setting->save();
    }

    private function error(string $code, int $status): array
    {
        return ['code' => $code, '_status' => $status];
    }
}
