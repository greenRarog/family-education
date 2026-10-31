<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\Advertisement;
use App\Models\User;
use App\Services\AdvertisementModerationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdvertisementHideConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Hide races require PostgreSQL.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
    }

    public static function operations(): array
    {
        return [['hide'], ['restore'], ['publish'], ['response'], ['block'], ['edit'], ['close']];
    }

    #[DataProvider('operations')]
    public function test_committed_hide_serializes_with_admin_and_business_operations(string $operation): void
    {
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create(['status' => $operation === 'response' ? 'published' : 'draft']);
        $owner = $ad->user;
        $actor = match ($operation) {
            'publish', 'edit', 'close' => $owner, 'response' => User::factory()->create(), default => $admin
        };
        $url = match ($operation) {
            'edit' => "/api/advertisements/{$ad->id}",
            'close' => "/api/advertisements/{$ad->id}/close",
            'publish', 'response' => "/api/advertisements/{$ad->id}/".($operation === 'response' ? 'responses' : 'publish'),
            'block' => "/api/admin/users/{$owner->id}/block",
            default => "/api/admin/advertisements/{$ad->id}/{$operation}",
        };
        $body = ['reason_code' => $operation === 'restore' ? 'review' : 'spam', 'reason' => 'Concurrent moderation decision.', 'message' => 'New response'];
        if ($operation === 'edit') {
            $family = $owner->family()->create(['surname' => 'Owner', 'city_id' => $ad->city_id]);
            $child = $family->children()->create(['name' => 'Child', 'birth_date' => '2020-01-01', 'sex' => 'male']);
            $body = ['type' => 'family_to_family', 'city_id' => $ad->city_id, 'child_ids' => [$child->id], 'participant_age_from' => 5, 'participant_age_to' => 10, 'description' => 'Corrected while hidden'];
        }
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            app(AdvertisementModerationService::class)->change($admin, $ad, true, ['reason_code' => 'spam', 'reason' => 'Hide before concurrent operation.']);
            $worker = $this->start(['actor_id' => $actor->id, 'method' => $operation === 'edit' ? 'PUT' : 'POST', 'url' => $url, 'body' => $body, 'barrier' => $barrier]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $result = $this->workerResult($worker);
            $this->assertSame(match ($operation) {
                'publish' => 403, 'response' => 404, default => 200
            }, $result['status'], json_encode($result));
            $this->assertSame($operation !== 'restore', $ad->fresh()->isHidden());
            $this->assertDatabaseCount('advertisement_responses', 0);
            $this->assertDatabaseCount('admin_actions', in_array($operation, ['restore', 'block']) ? 2 : 1);
            if ($operation === 'edit') {
                $this->assertSame('Corrected while hidden', $ad->fresh()->description);
            }
            if ($operation === 'close') {
                $this->assertSame('closed', $ad->fresh()->status->value);
            }
            if ($operation === 'hide') {
                $this->assertFalse($result['body']['changed']);
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    public function test_publish_commits_before_waiting_hide_preserving_both_transitions(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $ad = Advertisement::factory()->create();
        $one = $this->barrier();
        $two = $this->barrier();
        $writer = $hider = null;
        try {
            $writer = $this->start(['actor_id' => $ad->user_id, 'url' => "/api/advertisements/{$ad->id}/publish", 'barrier' => $one, 'hold' => 'after']);
            $this->ready($writer, $one);
            $hider = $this->start(['actor_id' => $admin->id, 'url' => "/api/admin/advertisements/{$ad->id}/hide", 'body' => ['reason_code' => 'spam', 'reason' => 'Hide after publication.'], 'barrier' => $two]);
            $pid = $this->ready($hider, $two);
            file_put_contents($two.'/release', 'go');
            $this->waitForLock($hider, $pid);
            file_put_contents($one.'/release', 'go');
            $this->assertSame(200, $this->workerResult($writer)['status']);
            $this->assertSame(200, $this->workerResult($hider)['status']);
            $this->assertSame('published', $ad->fresh()->status->value);
            $this->assertTrue($ad->fresh()->isHidden());
        } finally {
            $writer?->stop(0);
            $hider?->stop(0);
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
