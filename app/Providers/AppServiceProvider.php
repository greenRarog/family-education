<?php

namespace App\Providers;

use GuzzleHttp\Client as HttpClient;
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
        //
    }
}
