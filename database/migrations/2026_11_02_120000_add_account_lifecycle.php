<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('account_deleted_at')->nullable()->index();
            $table->unsignedBigInteger('purged_block_count')->default(0);
        });
        Schema::table('advertisements', function (Blueprint $table): void {
            $table->timestamp('account_deleted_at')->nullable()->index();
        });
        $this->cityNullable(true);
        Schema::table('conversations', function (Blueprint $table): void {
            $table->timestamp('account_deleted_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
        });
        Schema::table('reports', function (Blueprint $table): void {
            $table->string('resolution_outcome')->nullable();
        });
        // Transactional outbox. It is NOT the independent recovery registry.
        Schema::create('account_deletions', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->primary();
            $table->timestamp('deleted_at');
            $table->string('policy_version');
            $table->timestamp('registry_confirmed_at')->nullable();
            $table->timestamp('backups_cleared_at')->nullable();
        });
        // Short-lived, per-row permits written only by the retention service.
        Schema::create('moderation_purge_actions', function (Blueprint $table): void {
            $table->foreignId('admin_action_id')->primary()->constrained()->cascadeOnDelete();
        });
        // Even an accidental raw permit insert cannot authorize an open case or
        // a measure still applying to an existing account/advertisement.
        $activeCase = <<<'SQL'
            WITH RECURSIVE links(src, dst) AS (
                SELECT 'a' || CAST(id AS TEXT), 'r' || CAST(report_id AS TEXT) FROM admin_actions WHERE report_id IS NOT NULL
                UNION ALL SELECT 'a' || CAST(id AS TEXT), 'a' || CAST(related_action_id AS TEXT) FROM admin_actions WHERE related_action_id IS NOT NULL
                UNION ALL SELECT 'a' || CAST(admin_action_id AS TEXT), 'r' || CAST(report_id AS TEXT) FROM report_resolution_actions
            ), connected(node) AS (
                SELECT 'a' || CAST(NEW.admin_action_id AS TEXT)
                UNION SELECT CASE WHEN links.src = connected.node THEN links.dst ELSE links.src END
                    FROM links JOIN connected ON links.src = connected.node OR links.dst = connected.node
            )
            SELECT 1 FROM reports WHERE status = 'open' AND 'r' || CAST(id AS TEXT) IN (SELECT node FROM connected)
            UNION ALL SELECT 1 FROM users WHERE account_deleted_at IS NULL AND 'a' || CAST(block_action_id AS TEXT) IN (SELECT node FROM connected)
            UNION ALL SELECT 1 FROM advertisements WHERE account_deleted_at IS NULL AND 'a' || CAST(hide_action_id AS TEXT) IN (SELECT node FROM connected)
            SQL;
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<SQL
                CREATE OR REPLACE FUNCTION moderation_check_purge_permit() RETURNS trigger AS \$\$
                BEGIN
                    IF EXISTS ({$activeCase}) THEN
                        RAISE EXCEPTION 'Active moderation cases cannot be purged' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END;
                \$\$ LANGUAGE plpgsql;
                CREATE TRIGGER moderation_check_purge_permit BEFORE INSERT ON moderation_purge_actions
                    FOR EACH ROW EXECUTE FUNCTION moderation_check_purge_permit();
                SQL);
            DB::unprepared(<<<'SQL'
                DROP TRIGGER admin_actions_immutable ON admin_actions;
                CREATE TRIGGER admin_actions_immutable BEFORE UPDATE OR TRUNCATE ON admin_actions
                    FOR EACH STATEMENT EXECUTE FUNCTION admin_actions_reject_mutation();
                CREATE OR REPLACE FUNCTION admin_actions_check_retention_delete() RETURNS trigger AS $$
                BEGIN
                    IF NOT EXISTS (SELECT 1 FROM moderation_purge_actions WHERE admin_action_id = OLD.id) THEN
                        RAISE EXCEPTION 'Administrative actions are immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER admin_actions_retention_delete BEFORE DELETE ON admin_actions
                    FOR EACH ROW EXECUTE FUNCTION admin_actions_check_retention_delete();
                SQL);
        } else {
            DB::unprepared("CREATE TRIGGER moderation_check_purge_permit BEFORE INSERT ON moderation_purge_actions WHEN EXISTS ({$activeCase}) BEGIN SELECT RAISE(ABORT, 'Active moderation cases cannot be purged'); END");
            DB::unprepared(<<<'SQL'
                DROP TRIGGER admin_actions_no_delete;
                CREATE TRIGGER admin_actions_no_delete BEFORE DELETE ON admin_actions
                WHEN NOT EXISTS (SELECT 1 FROM moderation_purge_actions WHERE admin_action_id = OLD.id)
                BEGIN SELECT RAISE(ABORT, 'Administrative actions are immutable'); END;
                SQL);
        }
    }

    public function down(): void
    {
        // Removing the marker would revive tombstones and destroy retention dates.
        if (DB::table('users')->whereNotNull('account_deleted_at')->exists()
            || DB::table('users')->where('purged_block_count', '>', 0)->exists()
            || DB::table('account_deletions')->exists()
            || DB::table('reports')->whereNotNull('resolution_outcome')->exists()) {
            throw new RuntimeException('Account lifecycle rollback requires a separate data-preserving migration.');
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
                DROP TRIGGER admin_actions_retention_delete ON admin_actions;
                DROP FUNCTION admin_actions_check_retention_delete();
                DROP TRIGGER admin_actions_immutable ON admin_actions;
                CREATE TRIGGER admin_actions_immutable BEFORE UPDATE OR DELETE OR TRUNCATE ON admin_actions
                    FOR EACH STATEMENT EXECUTE FUNCTION admin_actions_reject_mutation();
                SQL);
        } else {
            DB::unprepared("DROP TRIGGER admin_actions_no_delete; CREATE TRIGGER admin_actions_no_delete BEFORE DELETE ON admin_actions BEGIN SELECT RAISE(ABORT, 'Administrative actions are immutable'); END");
        }
        Schema::drop('moderation_purge_actions');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION moderation_check_purge_permit()');
        }
        Schema::drop('account_deletions');
        Schema::table('reports', fn (Blueprint $table) => $table->dropColumn('resolution_outcome'));
        Schema::table('conversations', function (Blueprint $table): void {
            $table->dropIndex(['expires_at']);
            $table->dropColumn(['account_deleted_at', 'expires_at']);
        });
        Schema::table('advertisements', function (Blueprint $table): void {
            $table->dropIndex(['account_deleted_at']);
            $table->dropColumn('account_deleted_at');
        });
        $this->cityNullable(false);
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['account_deleted_at']);
            $table->dropColumn(['account_deleted_at', 'purged_block_count']);
        });
    }

    private function cityNullable(bool $nullable): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE advertisements ALTER COLUMN city_id '.($nullable ? 'DROP' : 'SET').' NOT NULL');

            return;
        }
        // SQLite's documented schema-only ALTER procedure avoids rebuilding the
        // parent table (which would cascade-delete existing responses/messages).
        // https://www.sqlite.org/lang_altertable.html#otheralter
        DB::transaction(function () use ($nullable): void {
            $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'advertisements')->value('sql');
            $old = $nullable ? '"city_id" integer not null' : '"city_id" integer';
            $new = $nullable ? '"city_id" integer' : '"city_id" integer not null';
            if (! is_string($sql) || substr_count($sql, $old) !== 1
                || (! $nullable && DB::table('advertisements')->whereNull('city_id')->exists())) {
                throw new RuntimeException('Unexpected advertisements schema; refusing unsafe SQLite alteration.');
            }
            $version = (int) DB::selectOne('PRAGMA schema_version')->schema_version;
            DB::statement('PRAGMA writable_schema=ON');
            try {
                DB::table('sqlite_master')->where('type', 'table')->where('name', 'advertisements')
                    ->update(['sql' => str_replace($old, $new, $sql)]);
                DB::statement('PRAGMA schema_version='.($version + 1));
            } finally {
                DB::statement('PRAGMA writable_schema=OFF');
            }
            if (DB::selectOne('PRAGMA integrity_check')->integrity_check !== 'ok'
                || DB::select('PRAGMA foreign_key_check') !== []) {
                throw new RuntimeException('SQLite integrity check failed after account lifecycle migration.');
            }
        });
    }
};
