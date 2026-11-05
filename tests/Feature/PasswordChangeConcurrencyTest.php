<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class PasswordChangeConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Password races require independent PostgreSQL connections.');
        }
        $this->assertSame('family_education_test', config('database.connections.pgsql.database'));
        $this->artisan('migrate:fresh')->assertSuccessful();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && config('database.default') === 'pgsql') {
            $this->artisan('migrate:fresh')->assertSuccessful();
        }
        parent::tearDown();
    }

    public static function competitors(): array
    {
        return [['change'], ['reset'], ['login'], ['delete']];
    }

    #[DataProvider('competitors')]
    public function test_committed_change_rejects_waiting_old_password_and_reset_token(string $operation): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        $input = match ($operation) {
            'change' => ['url' => '/api/account/password', 'method' => 'PUT', 'body' => ['current_password' => 'Password123!', 'password' => 'RacingPassword123!', 'password_confirmation' => 'RacingPassword123!']],
            'reset' => ['guest' => true, 'url' => '/reset-password', 'body' => ['email' => $user->email, 'token' => $token, 'password' => 'RacingPassword123!', 'password_confirmation' => 'RacingPassword123!']],
            'login' => ['guest' => true, 'url' => '/login', 'body' => ['email' => $user->email, 'password' => 'Password123!', 'cf-turnstile-response' => 'fixture']],
            'delete' => ['url' => '/api/account', 'method' => 'DELETE', 'account_deletion_registry' => true, 'body' => ['current_password' => 'Password123!', 'request_token' => str_repeat('b', 64)]],
        };
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            app(UpdateUserPassword::class)->update($user, ['current_password' => 'Password123!', 'password' => 'CommittedPassword123!', 'password_confirmation' => 'CommittedPassword123!']);
            $worker = $this->start($input + ['actor_id' => $user->id, 'barrier' => $barrier]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $result = $this->workerResult($worker);
            $this->assertSame($operation === 'change' ? 401 : 422, $result['status']);
            $this->assertTrue(Hash::check('CommittedPassword123!', $user->fresh()->password));
            $this->assertSame(1, $user->fresh()->session_version);
            $this->assertFalse($user->fresh()->isAccountDeleted());
            $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $worker?->stop(0);
            File::deleteDirectory($barrier);
        }
    }

    public function test_reset_winning_the_lock_invalidates_current_password_for_waiting_change(): void
    {
        $user = User::factory()->create();
        $barrier = $this->barrier();
        $worker = null;
        try {
            DB::beginTransaction();
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(ResetUserPassword::class)->reset($locked, ['password' => 'RecoveredPassword123!', 'password_confirmation' => 'RecoveredPassword123!']);
            $worker = $this->start(['actor_id' => $user->id, 'barrier' => $barrier, 'url' => '/api/account/password', 'method' => 'PUT', 'body' => ['current_password' => 'Password123!', 'password' => 'RacingPassword123!', 'password_confirmation' => 'RacingPassword123!']]);
            $pid = $this->ready($worker, $barrier);
            file_put_contents($barrier.'/release', 'go');
            $this->waitForLock($worker, $pid);
            DB::commit();
            $this->assertSame(422, $this->workerResult($worker)['status']);
            $this->assertTrue(Hash::check('RecoveredPassword123!', $user->fresh()->password));
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
