<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminAction;
use App\Models\Report;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ReportResolutionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Report decision races require PostgreSQL.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
    }

    public function test_two_admin_decisions_serialize_and_loser_cannot_overwrite_or_apply_sanctions(): void
    {
        $report = Report::factory()->create();
        $firstAdmin = User::factory()->create(['user_type' => 'admin']);
        $secondAdmin = User::factory()->create(['user_type' => 'admin']);
        $one = $this->barrier();
        $two = $this->barrier();
        $first = $second = null;
        try {
            $first = $this->start(['actor_id' => $firstAdmin->id, 'url' => '/api/admin/reports/'.$report->id.'/resolve',
                'body' => ['status' => 'dismissed', 'resolution_note' => 'First committed decision'], 'barrier' => $one, 'hold' => 'after']);
            $this->ready($first, $one);
            $second = $this->start(['actor_id' => $secondAdmin->id, 'url' => '/api/admin/reports/'.$report->id.'/resolve',
                'body' => ['status' => 'resolved', 'resolution_note' => 'Losing decision', 'actions' => ['hide_advertisement', 'block_user'],
                    'sanction_reason_code' => 'spam', 'sanction_reason' => 'Must never be applied.'], 'barrier' => $two]);
            $pid = $this->ready($second, $two);
            file_put_contents($two.'/release', 'go');
            $this->waitForLock($second, $pid);
            file_put_contents($one.'/release', 'go');
            $this->assertSame(200, $this->workerResult($first)['status']);
            $this->assertSame(409, $this->workerResult($second)['status']);
            $this->assertSame('dismissed', $report->fresh()->status->value);
            $this->assertSame($firstAdmin->id, $report->fresh()->resolved_by);
            $this->assertSame('First committed decision', $report->fresh()->resolution_note);
            $this->assertFalse($report->target->fresh()->isHidden());
            $this->assertFalse($report->target->user->fresh()->isBlocked());
            $this->assertDatabaseCount('admin_actions', 1);
            $this->assertSame('report_dismissed', AdminAction::query()->firstOrFail()->action->value);
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
        $worker = new Process([PHP_BINARY, base_path('tests/Support/report-decision-worker.php'), json_encode($input, JSON_THROW_ON_ERROR)], base_path(), [
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
            $this->assertLessThan($deadline, microtime(true), 'Worker must reach report lock.');
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
