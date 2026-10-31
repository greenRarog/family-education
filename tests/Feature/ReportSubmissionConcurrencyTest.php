<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Advertisement;
use App\Models\Report;
use App\Models\User;
use App\Services\AdvertisementModerationService;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReportSubmissionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Report races require PostgreSQL.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
    }

    public static function races(): array
    {
        return [['duplicate'], ['block'], ['owner_block'], ['hide']];
    }

    #[DataProvider('races')]
    public function test_submission_rechecks_duplicate_and_access_after_waiting_for_commit(string $operation): void
    {
        $actor = User::factory()->create();
        $ad = Advertisement::factory()->create(['status' => 'published']);
        $admin = User::factory()->create(['user_type' => 'admin']);
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            if ($operation === 'duplicate') {
                Report::query()->create(['reporter_id' => $actor->id, 'target_type' => 'advertisement', 'target_id' => $ad->id, 'context_type' => 'advertisement', 'context_id' => $ad->id, 'reason' => 'spam', 'snapshot' => ['description' => 'First accepted']]);
            } elseif ($operation === 'block') {
                app(UserBlockService::class)->block($admin, $actor, ['reason_code' => 'spam', 'reason' => 'Block before report']);
            } elseif ($operation === 'owner_block') {
                app(UserBlockService::class)->block($admin, $ad->user, ['reason_code' => 'spam', 'reason' => 'Block owner before report']);
            } else {
                app(AdvertisementModerationService::class)->change($admin, $ad, true, ['reason_code' => 'spam', 'reason' => 'Hide before report']);
            }
            $worker = $this->start(['actor_id' => $actor->id, 'url' => '/api/reports', 'body' => ['context_type' => 'advertisement', 'context_id' => $ad->id, 'target_type' => 'advertisement', 'reason' => 'spam'], 'barrier' => $barrier]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $result = $this->workerResult($worker);
            $this->assertSame(match ($operation) {
                'duplicate' => 422, 'block' => 403, default => 404,
            }, $result['status']);
            if (in_array($operation, ['duplicate', 'block'], true)) {
                $this->assertSame($operation === 'duplicate' ? 'report_already_open' : 'user_blocked', $result['body']['code']);
            }
            $this->assertDatabaseCount('reports', $operation === 'duplicate' ? 1 : 0);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    public function test_two_http_submissions_create_one_report(): void
    {
        $actor = User::factory()->create();
        $ad = Advertisement::factory()->create(['status' => 'published']);
        $one = $this->barrier();
        $two = $this->barrier();
        $first = $second = null;
        $input = ['actor_id' => $actor->id, 'url' => '/api/reports', 'body' => ['context_type' => 'advertisement', 'context_id' => $ad->id, 'target_type' => 'advertisement', 'reason' => 'spam']];
        try {
            $first = $this->start($input + ['barrier' => $one, 'hold' => 'after']);
            $this->ready($first, $one);
            $second = $this->start($input + ['barrier' => $two]);
            $pid = $this->ready($second, $two);
            file_put_contents($two.'/release', 'go');
            $this->waitForLock($second, $pid);
            file_put_contents($one.'/release', 'go');
            $this->assertSame(201, $this->workerResult($first)['status']);
            $result = $this->workerResult($second);
            $this->assertSame(422, $result['status']);
            $this->assertSame('report_already_open', $result['body']['code']);
            $this->assertDatabaseCount('reports', 1);
            $this->assertDatabaseCount('admin_actions', 0);
        } finally {
            $first?->stop(0);
            $second?->stop(0);
            File::deleteDirectory($one);
            File::deleteDirectory($two);
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
