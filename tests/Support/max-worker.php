<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Max\MaxLinkService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'family_education_test') {
    throw new RuntimeException('Only the isolated PostgreSQL test database is allowed.');
}
config(['services.max.enabled' => true, 'services.max.token' => 'synthetic-token', 'services.max.bot_id' => '123456', 'services.max.webhook_secret' => str_repeat('s', 32), 'services.max.username' => 'education_test_bot']);
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '15s'");
$user = User::query()->findOrFail($input['actor_id']);
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
$reached = false;
DB::connection()->beforeExecuting(function (string $sql) use ($input, $pid, &$reached): void {
    if ($reached || ! str_contains($sql, 'from "users"') || ! str_contains($sql, 'for update')) {
        return;
    }
    $reached = true;
    file_put_contents($input['barrier'].'/ready', (string) $pid);
    $deadline = microtime(true) + 15;
    while (! is_file($input['barrier'].'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timed out.');
        }
        usleep(10000);
    }
});
$sent = 0;
Http::preventStrayRequests();
Http::fake(function ($request) use (&$sent) {
    $sent++;

    return Http::response(str_ends_with($request->url(), '/me')
        ? ['user_id' => 123456, 'username' => 'education_test_bot', 'is_bot' => true]
        : ['message' => ['body' => ['mid' => '1']]]);
});
if ($input['operation'] === 'deliver') {
    $job = unserialize(file_get_contents($input['job']));
    $job->handle(app(ChannelManager::class));
    $result = ['sent' => $sent];
} else {
    $result = app(MaxLinkService::class)->browser($user, 'fixture-session', $input['operation'], $input['body'] ?? []);
}
echo json_encode(['result' => $result, 'reached' => $reached, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
