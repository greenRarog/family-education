<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountDeletion;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AccountRecoveryService;
use App\Services\DatabaseAccountDeletionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private string $registryFile;

    private array $registryConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registryFile = tempnam(sys_get_temp_dir(), 'fe-registry-');
        config(['account_deletion.registry.driver' => 'database', 'account_deletion.registry.namespace' => 'test-installation',
            'database.connections.deletion_registry' => ['driver' => 'sqlite', 'database' => $this->registryFile, 'prefix' => '']]);
        if (getenv('REGISTRY_TEST_PG') === '1') {
            config(['database.connections.deletion_registry' => array_replace(config('database.connections.pgsql'), [
                'database' => 'family_education_registry_test', 'url' => null,
            ])]);
        }
        $this->registryConfig = config('database.connections.deletion_registry');
        app(DatabaseAccountDeletionRegistry::class)->initialize();
    }

    protected function tearDown(): void
    {
        DB::purge('deletion_registry');
        if (getenv('REGISTRY_TEST_PG') === '1') {
            config(['database.connections.deletion_registry' => $this->registryConfig]);
            DB::connection('deletion_registry')->table('deletion_records')->where('namespace', 'test-installation')->delete();
            DB::purge('deletion_registry');
        }
        @unlink($this->registryFile);
        parent::tearDown();
    }

    private function record(int $userId, int $time): AccountDeletion
    {
        $record = new AccountDeletion;
        $record->forceFill(['user_id' => $userId, 'deleted_at' => $time, 'policy_version' => 'D02-2026-11-02',
            'request_token_hash' => str_repeat('a', 64)]);

        return $record;
    }

    public function test_registry_commit_survives_application_transaction_rollback(): void
    {
        $registry = app(DatabaseAccountDeletionRegistry::class);
        DB::beginTransaction();
        $this->assertTrue($registry->record($this->record(27, now()->timestamp)));
        DB::rollBack();
        $this->assertSame(27, iterator_to_array($registry->records())[0]['user_id']);
    }

    public function test_registry_is_idempotent_rejects_conflicts_and_exports_no_secret(): void
    {
        $registry = app(DatabaseAccountDeletionRegistry::class);
        $record = $this->record(15, now()->subDay()->timestamp);
        $this->assertTrue($registry->available());
        $this->assertTrue($registry->record($record));
        $this->assertTrue($registry->record($record));
        $this->assertFalse($registry->record($this->record(15, now()->timestamp)));
        $stored = DB::connection('deletion_registry')->table('deletion_records')->sole();
        $this->assertEqualsCanonicalizing(['namespace', 'user_id', 'deleted_at', 'policy_version', 'result'], array_keys((array) $stored));
        $this->assertSame($record->deleted_at->timestamp, (int) $stored->deleted_at);
    }

    public function test_registry_cannot_be_the_application_database_and_unavailable_is_not_success(): void
    {
        config(['database.connections.deletion_registry' => config('database.connections.'.config('database.default'))]);
        DB::purge('deletion_registry');
        $this->assertFalse(app(DatabaseAccountDeletionRegistry::class)->available());
        $this->expectException(RuntimeException::class);
        app(AccountRecoveryService::class)->reconcile();
    }

    public function test_recovery_reapplies_original_deletion_clears_access_and_prevents_id_reuse(): void
    {
        $chat = Conversation::factory()->create();
        $deleted = $chat->advertisementResponse->user;
        $survivor = $chat->advertisementResponse->advertisement->user;
        $time = now()->subMonths(2)->startOfSecond();
        $registry = app(DatabaseAccountDeletionRegistry::class);
        $registry->record($this->record($deleted->id, $time->timestamp));
        $registry->record($this->record(9000, $time->timestamp));
        $survivor->forceFill(['telegram_chat_id' => '123', 'telegram_bot_id' => '777', 'telegram_confirmed_at' => now(), 'remember_token' => 'old-token'])->save();
        DB::table('sessions')->insert(['id' => 'old', 'user_id' => $survivor->id, 'payload' => 'old', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $survivor->email, 'token' => 'old', 'created_at' => now()]);
        $this->assertSame(2, app(AccountRecoveryService::class)->reconcile());
        $this->assertSame($time->timestamp, $deleted->fresh()->account_deleted_at->timestamp);
        $this->assertSame($time->copy()->addMonthsNoOverflow(12)->timestamp, $chat->fresh()->expires_at->timestamp);
        $this->assertDatabaseCount('sessions', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->assertNull($survivor->fresh()->remember_token);
        $this->assertNull($survivor->fresh()->telegram_chat_id);
        $this->assertFalse($survivor->fresh()->isAccountDeleted());
        $this->assertGreaterThan(9000, User::factory()->create()->id);
        $this->assertSame(2, app(AccountRecoveryService::class)->reconcile());
        $this->assertSame($time->timestamp, $deleted->fresh()->account_deleted_at->timestamp);
    }

    public function test_invalid_registry_record_leaves_restored_account_untouched(): void
    {
        $user = User::factory()->create();
        DB::connection('deletion_registry')->table('deletion_records')->insert([
            'namespace' => 'test-installation', 'user_id' => $user->id, 'deleted_at' => now()->timestamp,
            'policy_version' => 'unknown', 'result' => 'applied',
        ]);
        try {
            app(AccountRecoveryService::class)->reconcile();
            $this->fail('Invalid registry should prevent reconciliation.');
        } catch (RuntimeException) {
            $this->assertFalse($user->fresh()->isAccountDeleted());
        }
    }

    public function test_recovery_command_refuses_an_open_or_unspecified_target(): void
    {
        $this->artisan('accounts:reconcile-restored')->assertFailed();
        $this->artisan('accounts:reconcile-restored', ['--database' => DB::connection()->getDatabaseName(), '--workers-stopped' => true])->assertFailed();
    }
}
