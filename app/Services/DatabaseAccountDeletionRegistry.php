<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AccountDeletionRegistry;
use App\Models\AccountDeletion;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** A separately provisioned database, deliberately excluded from application migrations/restores. */
class DatabaseAccountDeletionRegistry implements AccountDeletionRegistry
{
    public function connection(): Connection
    {
        if (config('account_deletion.registry.driver') !== 'database'
            || ! preg_match('/^[a-zA-Z0-9_-]{8,100}$/D', (string) config('account_deletion.registry.namespace'))) {
            throw new RuntimeException('Independent deletion registry is not configured.');
        }
        $db = DB::connection('deletion_registry');
        if ($db->getDatabaseName() === DB::connection()->getDatabaseName()
            || ($db->getDriverName() !== 'pgsql' && ! app()->environment('testing'))) {
            throw new RuntimeException('Registry must use an independent PostgreSQL database.');
        }

        return $db;
    }

    public function initialize(): void
    {
        $schema = $this->connection()->getSchemaBuilder();
        if (! $schema->hasTable('deletion_records')) {
            $schema->create('deletion_records', function (Blueprint $table): void {
                $table->string('namespace', 100);
                $table->unsignedBigInteger('user_id');
                $table->unsignedBigInteger('deleted_at');
                $table->string('policy_version');
                $table->string('result');
                $table->primary(['namespace', 'user_id']);
            });
        }
    }

    public function available(): bool
    {
        try {
            return $this->connection()->getSchemaBuilder()->hasTable('deletion_records');
        } catch (Throwable) {
            return false;
        }
    }

    public function record(AccountDeletion $deletion): bool
    {
        $db = $this->connection();
        $data = ['namespace' => config('account_deletion.registry.namespace'), 'user_id' => $deletion->user_id,
            'deleted_at' => $deletion->deleted_at->timestamp, 'policy_version' => $deletion->policy_version, 'result' => 'applied'];
        $this->validate($data);

        return $db->transaction(function () use ($db, $data): bool {
            if ($db->getDriverName() === 'pgsql') {
                $db->statement('SET LOCAL synchronous_commit = on');
            }
            $db->table('deletion_records')->insertOrIgnore($data);
            $stored = $db->table('deletion_records')->where('namespace', $data['namespace'])->where('user_id', $data['user_id'])->first();

            return $stored !== null && (int) $stored->deleted_at === $data['deleted_at']
                && $stored->policy_version === $data['policy_version'] && $stored->result === 'applied';
        });
    }

    /** @return iterable<array{user_id: int, deleted_at: int, policy_version: string}> */
    public function records(): iterable
    {
        foreach ($this->connection()->table('deletion_records')->where('namespace', config('account_deletion.registry.namespace'))
            ->orderBy('deleted_at')->orderBy('user_id')->cursor() as $record) {
            $data = (array) $record;
            $this->validate($data);
            yield ['user_id' => (int) $record->user_id, 'deleted_at' => (int) $record->deleted_at, 'policy_version' => $record->policy_version];
        }
    }

    private function validate(array $data): void
    {
        if (filter_var($data['user_id'], FILTER_VALIDATE_INT) === false || (int) $data['user_id'] < 1
            || filter_var($data['deleted_at'], FILTER_VALIDATE_INT) === false || (int) $data['deleted_at'] < 1
            || (int) $data['deleted_at'] > now()->timestamp || $data['policy_version'] !== 'D02-2026-11-02'
            || $data['result'] !== 'applied') {
            throw new RuntimeException('Invalid or unsupported independent deletion record.');
        }
    }
}
