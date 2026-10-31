<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\AdminAction;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UserBlockConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Concurrent user state transitions require PostgreSQL.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
    }

    #[DataProvider('operations')]
    public function test_requests_serialize_state_changes_without_duplicate_audit(array $operations, bool $initiallyBlocked): void
    {
        $actor = User::factory()->create(['user_type' => UserType::ADMIN]);
        $target = User::factory()->create();
        if ($initiallyBlocked) {
            app(UserBlockService::class)->block($actor, $target, ['reason_code' => 'spam', 'reason' => 'Initial blocking decision.']);
        }
        $initialCount = AdminAction::query()->count();
        $this->assertSame(0, DB::transactionLevel());
        $barrier = sys_get_temp_dir().'/user-block-race-'.bin2hex(random_bytes(12));
        File::makeDirectory($barrier);
        $workers = [];
        try {
            foreach ($operations as $index => $operation) {
                $workers[] = $worker = new Process([
                    PHP_BINARY, base_path('tests/Support/user-block-race-worker.php'),
                    json_encode([
                        'actor_id' => $actor->id, 'target_id' => $target->id, 'operation' => $operation,
                        'barrier' => $barrier, 'worker' => $index,
                    ], JSON_THROW_ON_ERROR),
                ], base_path(), $this->workerEnvironment(), timeout: 20);
                $worker->start();
            }
            $deadline = microtime(true) + 10;
            while (! is_file($barrier.'/ready-0') || ! is_file($barrier.'/ready-1')) {
                foreach ($workers as $worker) {
                    if (! $worker->isRunning()) {
                        $this->fail($worker->getOutput().$worker->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Both requests must reach the target row lock.');
                }
                usleep(10000);
            }
            file_put_contents($barrier.'/release', 'go');
            $results = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame(200, $result['status'], json_encode($result, JSON_THROW_ON_ERROR));
                $this->assertTrue($result['reached_lock']);
                $this->assertSame(0, $result['transaction_level']);
                $results[] = $result;
                if ($result['body']['changed']) {
                    $entry = AdminAction::query()->findOrFail($result['body']['action_id']);
                    $this->assertSame($result['body']['user']['is_blocked'], $entry->after_state['blocked']);
                } else {
                    $this->assertNull($result['body']['action_id']);
                }
            }
            $this->assertCount(2, array_unique(array_column($results, 'connection_pid')));
            $changes = count(array_filter($results, fn ($result) => $result['body']['changed']));
            $this->assertDatabaseCount('admin_actions', $initialCount + $changes);
            if ($operations[0] === $operations[1]) {
                $this->assertSame(1, $changes);
                $this->assertSame($operations[0] === 'block', $target->fresh()->isBlocked());
            } else {
                $this->assertContains($changes, [1, 2]);
            }
            $previous = false;
            $lastBlockId = null;
            foreach (AdminAction::query()->orderBy('id')->get() as $entry) {
                $this->assertSame($previous, $entry->before_state['blocked']);
                $this->assertNotSame($previous, $entry->after_state['blocked']);
                $previous = $entry->after_state['blocked'];
                if ($previous) {
                    $lastBlockId = $entry->id;
                } else {
                    $this->assertSame($lastBlockId, $entry->related_action_id);
                }
            }
            $current = $target->fresh()->load('blockingAction');
            $this->assertSame($previous, $current->isBlocked());
            $this->assertSame($previous ? $lastBlockId : null, $current->block_action_id);
            if ($current->isBlocked() && $operations[0] === $operations[1]) {
                $this->assertSame($current->blockingAction->reason, $results[0]['body']['user']['block_reason']);
                $this->assertSame($current->blockingAction->reason, $results[1]['body']['user']['block_reason']);
            }
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            File::deleteDirectory($barrier);
        }
    }

    public static function operations(): array
    {
        return [
            'two blocks' => [['block', 'block'], false],
            'two unblocks' => [['unblock', 'unblock'], true],
            'opposite operations' => [['block', 'unblock'], false],
        ];
    }

    private function workerEnvironment(): array
    {
        $connection = config('database.connections.pgsql');

        return [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'),
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/user-block-race-no-config.php',
            'DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'family_education_test', 'DB_URL' => '',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null',
        ];
    }
}
