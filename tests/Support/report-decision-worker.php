<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$request = Request::create($input['url'], $input['method'] ?? 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'family_education_test') {
    throw new RuntimeException('Only the isolated PostgreSQL test database is allowed.');
}
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '15s'");
Auth::guard('web')->setUser(User::query()->findOrFail($input['actor_id']));
Notification::fake();
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
$reached = false;
$barrier = function (string $sql) use ($input, $pid, &$reached): void {
    if ($reached || ! str_contains($sql, 'from "reports"') || ! str_contains($sql, 'for update')) {
        return;
    }
    $reached = true;
    file_put_contents($input['barrier'].'/ready', (string) $pid);
    $deadline = microtime(true) + 15;
    while (! is_file($input['barrier'].'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out at the moderation write barrier.');
        }
        usleep(10000);
    }
};
if (($input['hold'] ?? 'before') === 'after') {
    DB::listen(fn ($query) => $barrier($query->sql));
} else {
    DB::connection()->beforeExecuting($barrier);
}
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'transaction_level' => DB::transactionLevel(), 'reached' => $reached], JSON_THROW_ON_ERROR);
