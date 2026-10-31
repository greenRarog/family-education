<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdvertisementResponseStatus;
use App\Enums\AdvertisementStatus;
use App\Enums\TeacherProfileVisibilityStatus;
use App\Enums\UserType;
use App\Models\City;
use App\Models\Conversation;
use App\Models\User;
use App\Services\UserBlockService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ModerationWriteConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Business write/block races require PostgreSQL.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
    }

    public static function writes(): array
    {
        return [['publish'], ['response'], ['message_author'], ['message_recipient'], ['accept'], ['approve']];
    }

    #[DataProvider('writes')]
    public function test_write_waits_for_block_commit_and_rechecks_stale_models(string $operation): void
    {
        [$actor, $target, $input, $assertUnchanged] = $this->scenario($operation);
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            app(UserBlockService::class)->block($admin, $target, ['reason_code' => 'spam', 'reason' => 'Committed before business write.']);
            $worker = $this->start($input + ['actor_id' => $actor->id, 'barrier' => $barrier]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $result = $this->workerResult($worker);
            $this->assertContains($result['status'], [403, 404], json_encode($result));
            $assertUnchanged();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    public function test_business_write_finishes_before_waiting_block_without_losing_committed_message(): void
    {
        [$actor, $target, $input] = $this->scenario('message_recipient');
        $admin = User::factory()->create(['user_type' => UserType::ADMIN]);
        $writeBarrier = $this->barrier();
        $blockBarrier = $this->barrier();
        $writer = $blocker = null;
        try {
            $writer = $this->start($input + ['actor_id' => $actor->id, 'barrier' => $writeBarrier, 'hold' => 'after']);
            $this->ready($writer, $writeBarrier);
            $blocker = $this->start(['actor_id' => $admin->id, 'barrier' => $blockBarrier, 'url' => "/api/admin/users/{$target->id}/block", 'body' => ['reason_code' => 'spam', 'reason' => 'Block after business commit.']]);
            $pid = $this->ready($blocker, $blockBarrier);
            file_put_contents($blockBarrier.'/release', 'go');
            $this->waitForLock($blocker, $pid);
            file_put_contents($writeBarrier.'/release', 'go');
            $this->assertSame(201, $this->workerResult($writer)['status']);
            $this->assertSame(200, $this->workerResult($blocker)['status']);
            $this->assertDatabaseHas('messages', ['body' => 'Concurrent business write']);
            $this->assertTrue($target->fresh()->isBlocked());
        } finally {
            $writer?->stop(0);
            $blocker?->stop(0);
            File::deleteDirectory($writeBarrier);
            File::deleteDirectory($blockBarrier);
        }
    }

    private function scenario(string $operation): array
    {
        $conversation = Conversation::factory()->create();
        $response = $conversation->advertisementResponse;
        $ad = $response->advertisement;
        $owner = $ad->user;
        $author = $response->user;
        if ($operation === 'publish') {
            return [$owner, $owner, ['url' => "/api/advertisements/{$ad->id}/publish"], fn () => $this->assertSame(AdvertisementStatus::DRAFT, $ad->fresh()->status)];
        }
        if ($operation === 'response') {
            $ad->update(['status' => AdvertisementStatus::PUBLISHED]);
            $newAuthor = User::factory()->create();

            return [$newAuthor, $owner, ['url' => "/api/advertisements/{$ad->id}/responses", 'body' => ['message' => 'Concurrent business write']], fn () => $this->assertDatabaseCount('advertisement_responses', 1)];
        }
        if ($operation === 'accept') {
            return [$owner, $author, ['url' => "/api/advertisement-responses/{$response->id}/accept"], fn () => $this->assertSame(AdvertisementResponseStatus::SENT, $response->fresh()->status)];
        }
        if ($operation === 'approve') {
            $teacher = User::factory()->create(['user_type' => UserType::TEACHER]);
            $profile = $teacher->teacherProfile()->create(['surname' => 'Teacher', 'city_id' => City::factory()->create()->id, 'public_profile_requested' => true, 'public_profile_status' => TeacherProfileVisibilityStatus::PENDING]);

            return [User::factory()->create(['user_type' => UserType::ADMIN]), $teacher, ['url' => "/api/admin/teacher-profiles/{$teacher->id}/approve"], fn () => $this->assertSame(TeacherProfileVisibilityStatus::PENDING, $profile->fresh()->public_profile_status)];
        }
        $response->update(['status' => AdvertisementResponseStatus::ACCEPTED]);

        return [$author, $operation === 'message_author' ? $author : $owner,
            ['url' => "/api/conversations/{$conversation->id}/messages", 'body' => ['message' => 'Concurrent business write']], fn () => $this->assertDatabaseCount('messages', 0)];
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
