<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

class EmailVerificationService
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /** @return array{status: string, retry_after: int} */
    public function send(User $user): array
    {
        if ($user->hasVerifiedEmail()) {
            return ['status' => 'already_verified', 'retry_after' => 0];
        }

        try {
            // Serialize budget reservations across registration, resend and workers.
            // Mail delivery is outside the lock; a failed attempt still uses its budget.
            /** @var Repository $cache */
            $cache = Cache::store(config('cache.limiter') ?? config('cache.default'));
            $store = $cache->getStore();
            if (! $store instanceof LockProvider) {
                throw new LogicException('Email verification requires a cache store with atomic locks.');
            }
            $wait = $store->lock('email:verification:user:'.$user->id.':lock', 10)
                ->block(3, function () use ($user): int {
                    $limits = [60 => 1, 3600 => 5];
                    $wait = 0;
                    foreach ($limits as $seconds => $maximum) {
                        $key = 'email:verification:user:'.$user->id.':'.$seconds;
                        if ($this->limiter->tooManyAttempts($key, $maximum)) {
                            $wait = max($wait, $this->limiter->availableIn($key));
                        }
                    }
                    if ($wait > 0) {
                        return $wait;
                    }
                    foreach ($limits as $seconds => $maximum) {
                        $this->limiter->hit('email:verification:user:'.$user->id.':'.$seconds, $seconds);
                    }

                    return 0;
                });
            if ($wait > 0) {
                return ['status' => 'throttled', 'retry_after' => $wait];
            }

            $user->notify(new VerifyEmail);

            return ['status' => 'sent', 'retry_after' => 0];
        } catch (LockTimeoutException) {
            return ['status' => 'throttled', 'retry_after' => 1];
        } catch (Throwable $exception) {
            // No address, signed URL, transport credentials or exception message in logs.
            Log::warning('Email verification delivery failed.', [
                'user_id' => $user->id,
                'exception_type' => $exception::class,
            ]);

            return ['status' => 'failed', 'retry_after' => 0];
        }
    }
}
