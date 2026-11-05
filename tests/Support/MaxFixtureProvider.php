<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** Register only in the disposable lifecycle acceptance copy, never in application providers. */
class MaxFixtureProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (getenv('LIFECYCLE_FIXTURE_ENABLED') !== '1' || ! $this->app->environment('local')
            || config('database.default') !== 'pgsql'
            || config('database.connections.pgsql.database') !== 'family_education_test') {
            throw new RuntimeException('MAX fixture requires the isolated lifecycle database.');
        }
        config(['services.max.enabled' => true, 'services.max.bot_id' => '778',
            'services.max.username' => 'fixture_bot', 'services.max.token' => 'synthetic-token',
            'services.max.webhook_secret' => str_repeat('s', 40)]);
        $file = storage_path('framework/max-fixture-message.json');
        Http::preventStrayRequests();
        Http::fake([
            'https://platform-api2.max.ru/me' => Http::response(['user_id' => 778, 'username' => 'fixture_bot', 'is_bot' => true]),
            'https://platform-api2.max.ru/messages*' => function (ClientRequest $request) use ($file) {
                file_put_contents($file, json_encode($request->data(), JSON_THROW_ON_ERROR));

                return Http::response(['message' => ['body' => ['mid' => 'fixture']]]);
            },
        ]);
        Route::get('__lifecycle/max-message', function (Request $request) use ($file) {
            abort_unless($request->header('X-Lifecycle-Fixture') === 'isolated-d09', 403);

            return is_file($file) ? json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR) : [];
        });
    }
}
