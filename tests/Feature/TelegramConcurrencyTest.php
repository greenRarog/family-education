<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\User;
use App\Notifications\AdvertisementExpiredNotification;
use App\Services\AccountDeletionService;
use App\Services\Telegram\TelegramLinkService;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TelegramConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Telegram races require independent PostgreSQL connections.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
        $this->artisan('migrate:fresh')->assertSuccessful();
        config(['services.telegram.enabled' => true, 'services.telegram.token' => '123456:synthetic', 'services.telegram.username' => 'education_test_bot']);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    public static function races(): array
    {
        return array_map(fn ($value) => [$value], ['confirm-unlink', 'unlink-confirm', 'delete-confirm', 'password-confirm', 'unique', 'confirm-confirm', 'send-unlink', 'unlink-send']);
    }

    private function pending(User $user, string $chat): array
    {
        $attempt = app(TelegramLinkService::class)->browser($user, 'fixture-session', 'create');
        // Synthetic candidate equivalent to a trusted /start; no secret in argv or provider calls.
        DB::table('telegram_link_attempts')->where('id', $attempt['attempt_id'])->update([
            'nonce_hash' => null, 'candidate_chat_id' => $chat,
            'code_hash' => hash_hmac('sha256', $attempt['attempt_id'].':12345678', config('app.key')),
        ]);

        return ['attempt_id' => $attempt['attempt_id'], 'code' => '12345678'];
    }

    #[DataProvider('races')]
    public function test_real_postgres_lock_order_and_unique_constraint(string $race): void
    {
        $user = User::factory()->family()->create();
        $body = $this->pending($user, '777');
        $links = app(TelegramLinkService::class);
        $other = $race === 'unique' ? User::factory()->family()->create() : $user;
        $otherBody = $race === 'unique' ? $this->pending($other, '777') : $body;
        $barrier = $this->barrier();
        $worker = null;
        try {
            if (in_array($race, ['send-unlink', 'unlink-send'], true)) {
                $links->browser($user, 'fixture-session', 'confirm', $body);
                $user->refresh();
                $user->notificationSetting()->update(['email_enabled' => false, 'telegram_enabled' => true]);
                $ad = Advertisement::factory()->create(['user_id' => $user->id]);
                Queue::fake();
                $user->notify(new AdvertisementExpiredNotification($ad));
                $job = Queue::pushed(SendQueuedNotifications::class)->first();
                $this->assertNotNull($job);
                file_put_contents($barrier.'/job', serialize($job));
            }
            DB::beginTransaction();
            if (in_array($race, ['confirm-unlink', 'confirm-confirm', 'unique'], true)) {
                $this->assertTrue($links->browser($user, 'fixture-session', 'confirm', $body)['connected']);
            } elseif (in_array($race, ['unlink-confirm', 'unlink-send'], true)) {
                $links->browser($user, 'fixture-session', 'unlink');
            } elseif ($race === 'delete-confirm') {
                app(AccountDeletionService::class)->delete($user);
            } elseif ($race === 'password-confirm') {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail()->forceFill(['session_version' => 1])->save();
            } elseif ($race === 'send-unlink') {
                $job->handle(app(ChannelManager::class));
                Http::assertSentCount(1);
            }
            $operation = match ($race) {
                'confirm-unlink', 'send-unlink' => 'unlink', 'unlink-send' => 'deliver', default => 'confirm',
            };
            $worker = $this->start(['actor_id' => $other->id, 'barrier' => $barrier, 'operation' => $operation, 'body' => $otherBody, 'job' => $barrier.'/job']);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $result = $this->workerResult($worker)['result'];
            match ($race) {
                'confirm-unlink', 'send-unlink' => $this->assertFalse($result['connected']),
                'unlink-confirm' => $this->assertSame('telegram_invalid', $result['code']),
                'delete-confirm', 'password-confirm' => $this->assertSame(401, $result['_status']),
                'unique' => $this->assertSame('telegram_conflict', $result['code']),
                'confirm-confirm' => $this->assertSame(1, $result['binding_version']),
                'unlink-send' => $this->assertSame(0, $result['sent']),
            };
            if (in_array($race, ['confirm-unlink', 'send-unlink', 'unlink-send', 'delete-confirm'], true)) {
                $this->assertNull($user->fresh()->telegram_chat_id);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    private function start(array $input): Process
    {
        $connection = config('database.connections.pgsql');
        $worker = new Process([PHP_BINARY, base_path('tests/Support/telegram-worker.php'), json_encode($input, JSON_THROW_ON_ERROR)], base_path(), [
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
        $path = sys_get_temp_dir().'/telegram-race-'.bin2hex(random_bytes(12));
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
