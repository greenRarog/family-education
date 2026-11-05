<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Contracts\AccountDeletionRegistry;
use App\Enums\ReportTargetType;
use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use App\Notifications\AccountChannelManager;
use App\Notifications\ConfirmedTelegramChannel;
use App\Services\DatabaseAccountDeletionRegistry;
use App\Services\UnavailableAccountDeletionRegistry;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use NotificationChannels\Telegram\Telegram;
use NotificationChannels\Telegram\TelegramChannel;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AccountDeletionRegistry::class, fn () => config('account_deletion.registry.driver') === 'database'
            ? new DatabaseAccountDeletionRegistry : new UnavailableAccountDeletionRegistry);
        $this->app->bind(TelegramChannel::class, ConfirmedTelegramChannel::class);
        $this->app->singleton(ChannelManager::class, fn ($app) => new AccountChannelManager($app));
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
        Auth::provider('active-eloquent', fn ($app, array $config) => (new ActiveUserProvider($app['hash'], $config['model']))
            ->withQuery(fn ($query) => $query->whereNull('account_deleted_at')));
        Relation::morphMap([
            ReportTargetType::ADVERTISEMENT->value => Advertisement::class,
            ReportTargetType::USER->value => User::class,
            'report' => Report::class,
        ]);

        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === 'web' && $event->user instanceof User && request()->hasSession()) {
                request()->session()->put('account_session_version', ['id' => $event->user->id, 'version' => (int) $event->user->session_version]);
            }
        });

        RateLimiter::for('password-change', fn (Request $request) => Limit::perMinute(5)
            ->by('account:password:user:'.$request->user()->id)
            ->response(static fn (Request $request, array $headers) => response()->json([
                'message' => 'Слишком много попыток. Повторите запрос позже.',
                'retry_after' => (int) $headers['Retry-After'],
            ], 429, $headers)));

        RateLimiter::for('account-deletion', fn (Request $request) => Limit::perMinute(5)
            ->by('account:deletion:user:'.$request->user()->id)
            ->response(static fn (Request $request, array $headers) => response()->json([
                'message' => 'Слишком много попыток. Повторите запрос позже.',
                'retry_after' => (int) $headers['Retry-After'],
            ], 429, $headers)));

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
