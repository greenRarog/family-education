<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$request = Request::create(
    '/api/admin/users/'.$input['target_id'].'/'.$input['operation'], 'POST',
    server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
    content: json_encode([
        'reason_code' => $input['operation'] === 'block' ? 'spam' : 'review',
        'reason' => 'Decision from worker '.$input['worker'],
    ], JSON_THROW_ON_ERROR),
);
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

if (! $app->environment('testing') || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'family_education_test') {
    throw new RuntimeException('The race worker requires the isolated PostgreSQL test database.');
}
DB::statement("SET lock_timeout = '5s'");
DB::statement("SET statement_timeout = '10s'");
Auth::guard('web')->setUser(User::query()->findOrFail($input['actor_id']));
$reachedLock = false;
DB::connection()->beforeExecuting(function (string $sql) use ($input, &$reachedLock): void {
    if ($reachedLock || ! str_contains($sql, 'from "users"') || ! str_contains($sql, 'for update')) {
        return;
    }
    // Both independent HTTP requests must reach the target lock before either
    // acquires it. A missing FOR UPDATE cannot accidentally pass this test.
    $reachedLock = true;
    file_put_contents($input['barrier'].'/ready-'.$input['worker'], 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($input['barrier'].'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the user-block race barrier.');
        }
        usleep(10000);
    }
});
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
    'reached_lock' => $reachedLock,
    'transaction_level' => DB::transactionLevel(),
    'connection_pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
], JSON_THROW_ON_ERROR);
