<?php

declare(strict_types=1);

use App\Contracts\AccountDeletionRegistry;
use App\Models\Advertisement;
use App\Models\Message;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use App\Notifications\NewMessageNotification;
use App\Services\AdvertisementClosureService;
use App\Services\TurnstileService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\FakeAccountDeletionRegistry;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$input = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$request = Request::create($input['url'], $input['method'] ?? 'POST', server: ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], content: json_encode($input['body'] ?? [], JSON_THROW_ON_ERROR));
$app->instance('request', $request);
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if ($input['account_deletion_registry'] ?? false) {
    config(['account_deletion.enabled' => true]);
    $app->instance(AccountDeletionRegistry::class, new FakeAccountDeletionRegistry);
}
if (! $app->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'family_education_test') {
    throw new RuntimeException('Only the isolated PostgreSQL test database is allowed.');
}
DB::statement("SET lock_timeout = '10s'");
DB::statement("SET statement_timeout = '15s'");
if (! ($input['guest'] ?? false)) {
    Auth::guard('web')->setUser(User::query()->findOrFail($input['actor_id']));
}
if (! isset($input['notification_message_id'])) {
    Notification::fake();
}
if (($input['url'] ?? '') === '/login') {
    $app->instance(TurnstileService::class, new class extends TurnstileService
    {
        public function verify(string $token, ?string $ip = null): bool
        {
            return true;
        }
    });
}
$pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
$reached = false;
$barrier = function (string $sql) use ($input, $pid, &$reached): void {
    if ($reached || ! str_contains($sql, 'from "users"') || ! str_contains($sql, 'for update')) {
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
if (isset($input['notification_message_id'])) {
    $deliveries = 0;
    Event::listen(MessageSent::class, function () use (&$deliveries): void {
        $deliveries++;
    });
    $notification = new NewMessageNotification(Message::findOrFail($input['notification_message_id']));
    (new SendQueuedNotifications(User::findOrFail($input['actor_id']), $notification, ['mail']))->handle(app(ChannelManager::class));
    echo json_encode(['status' => 200, 'deliveries' => $deliveries, 'transaction_level' => DB::transactionLevel(), 'reached' => $reached], JSON_THROW_ON_ERROR);
    exit;
}
if (isset($input['expire_advertisement_id'])) {
    $ad = Advertisement::findOrFail($input['expire_advertisement_id']);
    $changed = app(AdvertisementClosureService::class)->close($ad);
    echo json_encode(['status' => 200, 'changed' => $changed, 'notifications' => Notification::sent($ad->user, AdvertisementExpiredNotification::class)->count(), 'transaction_level' => DB::transactionLevel(), 'reached' => $reached], JSON_THROW_ON_ERROR);
    exit;
}
$response = $kernel->handle($request);
$kernel->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'transaction_level' => DB::transactionLevel(), 'reached' => $reached], JSON_THROW_ON_ERROR);
