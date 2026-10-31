<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        $id = match ($driver) {
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => throw new RuntimeException('Admin actions require PostgreSQL or SQLite.'),
        };
        $stateCheck = $driver === 'pgsql'
            ? "json_typeof(before_state) = 'object' AND json_typeof(after_state) = 'object'"
            : "json_valid(before_state) AND json_type(before_state) = 'object' AND json_valid(after_state) AND json_type(after_state) = 'object'";

        DB::statement(<<<SQL
            CREATE TABLE admin_actions (
                id {$id},
                actor_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                target_type VARCHAR(32) NOT NULL,
                target_id BIGINT NOT NULL,
                action VARCHAR(64) NOT NULL,
                reason_code VARCHAR(64) NOT NULL,
                reason TEXT NOT NULL,
                before_state JSON NOT NULL,
                after_state JSON NOT NULL,
                report_id BIGINT NULL REFERENCES reports(id) ON DELETE RESTRICT,
                related_action_id BIGINT NULL REFERENCES admin_actions(id) ON DELETE RESTRICT,
                occurred_at TIMESTAMP NOT NULL,
                CONSTRAINT admin_actions_target_check CHECK (
                    target_type IN ('user', 'advertisement', 'report') AND target_id > 0
                ),
                CONSTRAINT admin_actions_action_check CHECK (
                    (target_type = 'user' AND action IN ('user_blocked', 'user_unblocked'))
                    OR (target_type = 'advertisement' AND action IN ('advertisement_hidden', 'advertisement_restored', 'advertisement_expired'))
                    OR (target_type = 'report' AND action IN ('report_resolved', 'report_dismissed'))
                    OR (target_type IN ('user', 'advertisement') AND action = 'appeal_rejected' AND related_action_id IS NOT NULL)
                ),
                CONSTRAINT admin_actions_reason_check CHECK (
                    length(trim(reason_code)) BETWEEN 1 AND 64 AND length(trim(reason)) > 0
                ),
                CONSTRAINT admin_actions_state_check CHECK ({$stateCheck}),
                CONSTRAINT admin_actions_related_check CHECK (related_action_id IS NULL OR related_action_id <> id)
            )
            SQL);
        DB::statement('CREATE INDEX admin_actions_target_time_index ON admin_actions (target_type, target_id, occurred_at, id)');
        DB::statement('CREATE INDEX admin_actions_actor_time_index ON admin_actions (actor_id, occurred_at, id)');
        DB::statement('CREATE INDEX admin_actions_report_index ON admin_actions (report_id, id)');
        DB::statement('CREATE INDEX admin_actions_related_index ON admin_actions (related_action_id)');

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION admin_actions_reject_mutation() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'Administrative actions are immutable' USING ERRCODE = '23514';
                END;
                $$ LANGUAGE plpgsql;
                CREATE TRIGGER admin_actions_immutable
                    BEFORE UPDATE OR DELETE OR TRUNCATE ON admin_actions
                    FOR EACH STATEMENT EXECUTE FUNCTION admin_actions_reject_mutation();
                SQL);
        } else {
            foreach (['UPDATE', 'DELETE'] as $operation) {
                $name = 'admin_actions_no_'.strtolower($operation);
                DB::unprepared("CREATE TRIGGER {$name} BEFORE {$operation} ON admin_actions BEGIN SELECT RAISE(ABORT, 'Administrative actions are immutable'); END");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS admin_actions_reject_mutation()');
        }
    }
};
