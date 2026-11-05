<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\AccountDeletionRegistry;
use App\Models\AccountDeletion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AccountDeletionRequestService;
use App\Services\AccountDeletionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FakeAccountDeletionRegistry;
use Tests\TestCase;

class AccountDeletionConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Deletion races require independent PostgreSQL connections.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
        $this->artisan('migrate:fresh')->assertSuccessful();
        config(['account_deletion.enabled' => true]);
        $this->app->instance(AccountDeletionRegistry::class, new FakeAccountDeletionRegistry);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && config('database.default') === 'pgsql') {
            // These tests commit irreversible tombstones. Reset only the isolated fixture DB.
            $this->artisan('migrate:fresh')->assertSuccessful();
        }
        parent::tearDown();
    }

    public static function operations(): array
    {
        return array_map(fn ($operation) => [$operation], ['settings_get', 'settings_put', 'language', 'message', 'read', 'accept', 'response', 'publish', 'reset', 'login', 'delivery', 'password_change']);
    }

    #[DataProvider('operations')]
    public function test_request_started_before_deletion_waits_and_cannot_recreate_data(string $operation): void
    {
        $chat = Conversation::factory()->create();
        $response = $chat->advertisementResponse;
        $owner = $response->advertisement->user;
        $survivor = $response->user;
        $message = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $owner->id, 'read_at' => null]);
        $token = Password::broker()->createToken($owner);
        $response->advertisement->update(['status' => $operation === 'publish' ? 'draft' : 'published']);
        $input = match ($operation) {
            'settings_get' => ['url' => '/api/notification-settings', 'method' => 'GET'],
            'settings_put' => ['url' => '/api/notification-settings', 'method' => 'PUT', 'body' => ['email_enabled' => true, 'telegram_enabled' => true]],
            'language' => ['url' => '/api/user/language', 'method' => 'PUT', 'body' => ['language' => 'es']],
            'message' => ['actor_id' => $survivor->id, 'url' => "/api/conversations/{$chat->id}/messages", 'body' => ['message' => 'Late write']],
            'read' => ['actor_id' => $survivor->id, 'url' => "/api/conversations/{$chat->id}/read", 'body' => ['through_id' => $message->id]],
            'accept' => ['url' => "/api/advertisement-responses/{$response->id}/accept"],
            'response' => ['actor_id' => User::factory()->create()->id, 'url' => "/api/advertisements/{$response->advertisement_id}/responses", 'body' => ['message' => 'Late response']],
            'publish' => ['url' => "/api/advertisements/{$response->advertisement_id}/publish"],
            'password_change' => ['url' => '/api/account/password', 'method' => 'PUT', 'body' => ['current_password' => 'Password123!', 'password' => 'ChangedPassword123!', 'password_confirmation' => 'ChangedPassword123!']],
            'reset' => ['guest' => true, 'url' => '/reset-password', 'body' => ['email' => $owner->email, 'token' => $token, 'password' => 'ChangedPassword123!', 'password_confirmation' => 'ChangedPassword123!']],
            'login' => ['guest' => true, 'url' => '/login', 'body' => ['email' => $owner->email, 'password' => 'Password123!', 'cf-turnstile-response' => 'fixture']],
            'delivery' => ['actor_id' => $survivor->id, 'url' => '/', 'notification_message_id' => $message->id],
        };
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            app(AccountDeletionService::class)->delete($owner);
            $worker = $this->start($input + ['actor_id' => $owner->id, 'barrier' => $barrier]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $result = $this->workerResult($worker);
            if ($operation === 'delivery') {
                $this->assertSame(0, $result['deliveries']);
            } else {
                $this->assertContains($result['status'], [401, 403, 404, 422], json_encode($result));
            }
            $this->assertTrue($owner->fresh()->isAccountDeleted());
            $this->assertSame('ru', $owner->fresh()->locale);
            $this->assertNull($owner->fresh()->remember_token);
            $this->assertFalse(Hash::check('ChangedPassword123!', $owner->fresh()->password));
            $this->assertDatabaseMissing('notification_settings', ['user_id' => $owner->id]);
            $this->assertDatabaseCount('messages', 1);
            $this->assertDatabaseCount('advertisement_responses', 1);
            $this->assertNull($message->fresh()->read_at);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    public function test_concurrent_repeat_with_same_receipt_preserves_t0(): void
    {
        $user = User::factory()->create();
        $token = bin2hex(random_bytes(32));
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            $first = app(AccountDeletionRequestService::class)->delete($user, 'Password123!', $token);
            $worker = $this->start(['actor_id' => $user->id, 'barrier' => $barrier, 'url' => '/api/account', 'method' => 'DELETE', 'account_deletion_registry' => true, 'body' => ['current_password' => 'Password123!', 'request_token' => $token]]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $this->assertSame(200, $this->workerResult($worker)['status']);
            $this->assertDatabaseCount('account_deletions', 1);
            $this->assertTrue($first->deleted_at->eq(AccountDeletion::find($user->id)->deleted_at));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    public function test_password_is_checked_after_waiting_for_concurrent_change(): void
    {
        $user = User::factory()->create();
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            $user->forceFill(['password' => 'ChangedPassword123!'])->save();
            $worker = $this->start(['actor_id' => $user->id, 'barrier' => $barrier, 'url' => '/api/account', 'method' => 'DELETE', 'account_deletion_registry' => true, 'body' => ['current_password' => 'Password123!', 'request_token' => bin2hex(random_bytes(32))]]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $this->assertSame(422, $this->workerResult($worker)['status']);
            $this->assertFalse($user->fresh()->isAccountDeleted());
            $this->assertDatabaseCount('account_deletions', 0);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    public static function earlierOperations(): array
    {
        return [['message'], ['delivery']];
    }

    #[DataProvider('earlierOperations')]
    public function test_earlier_message_or_delivery_completes_before_waiting_deletion(string $operation): void
    {
        $chat = Conversation::factory()->create();
        $response = $chat->advertisementResponse;
        $response->update(['status' => 'accepted']);
        $owner = $response->advertisement->user;
        $survivor = $response->user;
        $message = Message::factory()->create(['conversation_id' => $chat->id, 'user_id' => $owner->id]);
        $writerBarrier = $this->barrier();
        $deleteBarrier = $this->barrier();
        $writer = $deleter = null;
        try {
            $input = $operation === 'message'
                ? ['url' => "/api/conversations/{$chat->id}/messages", 'body' => ['message' => 'Committed before deletion']]
                : ['url' => '/', 'notification_message_id' => $message->id];
            $writer = $this->start($input + ['actor_id' => $survivor->id, 'barrier' => $writerBarrier, 'hold' => 'after']);
            $this->ready($writer, $writerBarrier);
            $deleter = $this->start(['url' => '/api/account', 'method' => 'DELETE', 'actor_id' => $owner->id, 'barrier' => $deleteBarrier, 'account_deletion_registry' => true,
                'body' => ['current_password' => 'Password123!', 'request_token' => bin2hex(random_bytes(32))]]);
            $pid = $this->ready($deleter, $deleteBarrier);
            file_put_contents($deleteBarrier.'/release', 'go');
            $this->waitForLock($deleter, $pid);
            file_put_contents($writerBarrier.'/release', 'go');
            $result = $this->workerResult($writer);
            $this->assertSame($operation === 'message' ? 201 : 200, $result['status']);
            if ($operation === 'delivery') {
                $this->assertSame(1, $result['deliveries']);
            } else {
                $this->assertDatabaseHas('messages', ['body' => 'Committed before deletion']);
            }
            $this->assertSame(200, $this->workerResult($deleter)['status']);
            $this->assertNotNull($chat->fresh()->expires_at);
            $this->assertTrue($owner->fresh()->isAccountDeleted());
        } finally {
            $writer?->stop(0);
            $deleter?->stop(0);
            File::deleteDirectory($writerBarrier);
            File::deleteDirectory($deleteBarrier);
        }
    }

    private function start(array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $worker = new Process([PHP_BINARY, base_path('tests/Support/moderation-write-worker.php'), json_encode($input, JSON_THROW_ON_ERROR)], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'family_education_test', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
        ], timeout: 25);
        $worker->start();

        return $worker;
    }

    private function barrier(): string
    {
        $path = sys_get_temp_dir().'/moderation-write-'.bin2hex(random_bytes(12));
        File::makeDirectory($path);

        return $path;
    }

    private function ready(Process $worker, string $barrier): int
    {
        $deadline = microtime(true) + 10;
        while (! is_file($barrier.'/ready')) {
            $this->assertTrue($worker->isRunning(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertLessThan($deadline, microtime(true), 'Worker must reach participant lock.');
            usleep(10000);
        }

        return (int) file_get_contents($barrier.'/ready');
    }

    private function waitForLock(Process $worker, int $pid): void
    {
        $deadline = microtime(true) + 5;
        while (true) {
            // Clear the transaction's statistics snapshot when block is uncommitted.
            DB::select('SELECT pg_stat_clear_snapshot()');
            $state = DB::selectOne('SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?', [$pid]);
            if ($state?->wait_event_type === 'Lock') {
                return;
            }
            $this->assertTrue($worker->isRunning(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertLessThan($deadline, microtime(true), 'The second connection must actually wait for the row lock.');
            usleep(10000);
        }
    }

    private function workerResult(Process $worker): array
    {
        $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
        $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($result['reached']);
        $this->assertSame(0, $result['transaction_level']);

        return $result;
    }
}
