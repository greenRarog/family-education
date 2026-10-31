<?php

namespace App\Providers;

use App\Enums\ReportTargetType;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\Telegram\Telegram;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(Telegram::class, function (): Telegram {
            $proxy = config('services.telegram.proxy');

            return new Telegram(
                config('services.telegram.token'),
                new HttpClient($proxy ? ['proxy' => $proxy] : []),
                config('services.telegram.base_uri'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            ReportTargetType::ADVERTISEMENT->value => Advertisement::class,
            ReportTargetType::USER->value => User::class,
            'report' => Report::class,
        ]);

        RateLimiter::for('email-verification-check', fn (Request $request) => Limit::perMinute(6)
            ->by('email:verification:check:user:'.$request->user()->id)
            ->response(static fn (Request $request, array $headers) => response()->json([
                'message' => 'Слишком много попыток. Повторите запрос позже.',
                'retry_after' => (int) $headers['Retry-After'],
            ], 429, $headers)));

        foreach (['create', 'publish'] as $action) {
            RateLimiter::for('ads-'.$action, function (Request $request) use ($action): array {
                $key = 'ads:'.$action.':user:'.$request->user()->id;
                $response = static fn (Request $request, array $headers) => response()->json([
                    'message' => 'Слишком много попыток. Повторите запрос позже.',
                    'retry_after' => (int) $headers['Retry-After'],
                ], 429, $headers);

                return [
                    Limit::perMinute(5)->by($key.':60')->response($response),
                    Limit::perDay(20)->by($key.':86400')->response($response),
                ];
            });
        }

        RateLimiter::for('responses-create', function (Request $request): array {
            $key = 'responses:create:user:'.$request->user()->id;
            $response = static fn (Request $request, array $headers) => response()->json([
                'message' => 'Слишком много попыток. Повторите запрос позже.',
                'retry_after' => (int) $headers['Retry-After'],
            ], 429, $headers);

            return [
                Limit::perMinute(5)->by($key.':60')->response($response),
                Limit::perDay(50)->by($key.':86400')->response($response),
            ];
        });

        RateLimiter::for('reports-create', function (Request $request): array {
            $key = 'reports:create:user:'.$request->user()->id;
            $response = static fn (Request $request, array $headers) => response()->json([
                'message' => 'Слишком много попыток. Повторите запрос позже.',
                'retry_after' => (int) $headers['Retry-After'],
            ], 429, $headers);

            return [
                Limit::perMinute(3)->by($key.':60')->response($response),
                Limit::perDay(10)->by($key.':86400')->response($response),
            ];
        });

        RateLimiter::for('messages-create', function (Request $request): array {
            $key = 'messages:create:user:'.$request->user()->id;
            $response = static fn (Request $request, array $headers) => response()->json([
                'message' => 'Слишком много попыток. Повторите запрос позже.',
                'retry_after' => (int) $headers['Retry-After'],
            ], 429, $headers);

            return [
                Limit::perMinute(30)->by($key.':60')->response($response),
                Limit::perHour(300)->by($key.':3600')->response($response),
            ];
        });
    }
}
