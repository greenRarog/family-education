<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Notifications\AdvertisementExpiredNotification;
use App\Services\AccountDeletionService;
use App\Services\AdvertisementClosureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdvertisementExpiryConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Expiry races need independent PostgreSQL connections.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
        $this->artisan('migrate:fresh')->assertSuccessful();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && config('database.default') === 'pgsql') {
            $this->artisan('migrate:fresh')->assertSuccessful();
        }
        parent::tearDown();
    }

    public static function winners(): array
    {
        return [['command'], ['manual'], ['deletion']];
    }

    #[DataProvider('winners')]
    public function test_waiting_expiry_cannot_duplicate_a_committed_closure(string $winner): void
    {
        $ad = Advertisement::factory()->create(['status' => 'published', 'published_at' => now()->subYears(2)]);
        $owner = $ad->user;
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            if ($winner === 'deletion') {
                app(AccountDeletionService::class)->delete($owner);
            } else {
                app(AdvertisementClosureService::class)->close($ad, onlyExpired: $winner !== 'manual');
            }
            $worker = $this->start(['actor_id' => $owner->id, 'url' => '/', 'barrier' => $barrier, 'expire_advertisement_id' => $ad->id]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $result = $this->workerResult($worker);
            $this->assertFalse($result['changed']);
            $this->assertSame(0, $result['notifications']);
            $this->assertNotNull($ad->fresh()->closed_at);
            if ($winner === 'deletion') {
                Notification::assertNothingSent();
            } else {
                Notification::assertSentToTimes($owner, AdvertisementExpiredNotification::class, 1);
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
