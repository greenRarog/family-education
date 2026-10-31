<?php

declare(strict_types=1);

use App\Events\NewResponse;
use App\Models\Advertisement;
use App\Models\User;
use App\Notifications\NewResponseNotification;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$request = Request::create(
    '/api/advertisements/'.$input['advertisement_id'].'/responses',
    'POST',
    server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
    content: json_encode(['message' => $input['message']], JSON_THROW_ON_ERROR),
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
Auth::guard('web')->setUser(User::query()->findOrFail($input['user_id']));
Notification::fake();
$events = [];
Event::listen(NewResponse::class, function (NewResponse $event) use (&$events): void {
    $events[] = $event->response->id;
});

$reachedLock = false;
DB::connection()->beforeExecuting(function (string $sql) use ($input, &$reachedLock): void {
    if ($reachedLock || ! str_contains($sql, 'from "users"') || ! str_contains($sql, 'for update')) {
        return;
    }
    // C05 serializes writes at the participant locks, before duplicate checks.
    $reachedLock = true;
    file_put_contents($input['barrier'].'/ready-'.$input['worker'], 'ready');
    $deadline = microtime(true) + 15;
    while (! is_file($input['barrier'].'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timed out waiting for the response race barrier.');
        }
        usleep(10000);
    }
});

$response = $kernel->handle($request);
$kernel->terminate($request, $response);
$owner = Advertisement::query()->findOrFail($input['advertisement_id'])->user;

echo json_encode([
    'status' => $response->getStatusCode(),
    'body' => json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR),
    'content_type' => $response->headers->get('Content-Type'),
    'reached_lock' => $reachedLock,
    'events' => $events,
    'notifications' => Notification::sent($owner, NewResponseNotification::class)->count(),
    'transaction_level' => DB::transactionLevel(),
    'connection_healthy' => (int) DB::selectOne('SELECT 1 AS healthy')->healthy,
], JSON_THROW_ON_ERROR);
