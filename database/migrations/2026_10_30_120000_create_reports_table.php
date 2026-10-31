<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The project runs PostgreSQL and SQLite. Inline CHECKs also protect
        // SQLite writes, where ALTER TABLE ADD CONSTRAINT is unavailable.
        $driver = DB::connection()->getDriverName();
        $id = match ($driver) {
            'pgsql' => 'BIGSERIAL PRIMARY KEY',
            'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
            default => throw new RuntimeException('Reports require PostgreSQL or SQLite.'),
        };
        $snapshotCheck = $driver === 'pgsql'
            ? "json_typeof(snapshot) = 'object'"
            : "json_valid(snapshot) AND json_type(snapshot) = 'object'";

        DB::statement(<<<SQL
            CREATE TABLE reports (
                id {$id},
                reporter_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                target_type VARCHAR(32) NOT NULL,
                target_id BIGINT NOT NULL,
                context_type VARCHAR(32) NOT NULL,
                context_id BIGINT NOT NULL,
                reason VARCHAR(32) NOT NULL,
                comment TEXT NULL,
                snapshot JSON NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'open',
                resolved_by BIGINT NULL REFERENCES users(id) ON DELETE RESTRICT,
                resolution_note TEXT NULL,
                resolved_at TIMESTAMP NULL,
                created_at TIMESTAMP NOT NULL,
                updated_at TIMESTAMP NOT NULL,
                CONSTRAINT reports_target_check CHECK (
                    target_type IN ('advertisement', 'user') AND target_id > 0
                    AND (target_type <> 'user' OR target_id <> reporter_id)
                ),
                CONSTRAINT reports_context_check CHECK (
                    context_id > 0 AND (
                        context_type = 'conversation'
                        OR (target_type = 'advertisement' AND context_type = 'advertisement' AND context_id = target_id)
                        OR (target_type = 'user' AND context_type = 'teacher_profile' AND context_id = target_id)
                    )
                ),
                CONSTRAINT reports_reason_check CHECK (
                    reason IN ('spam', 'fraud', 'abuse', 'unsafe_content', 'personal_data', 'off_topic', 'other')
                    AND (reason <> 'off_topic' OR target_type = 'advertisement')
                ),
                CONSTRAINT reports_comment_check CHECK (
                    (comment IS NULL OR length(comment) <= 2000)
                    AND (reason <> 'other' OR (comment IS NOT NULL AND length(trim(comment)) >= 10))
                ),
                CONSTRAINT reports_snapshot_check CHECK ({$snapshotCheck}),
                CONSTRAINT reports_status_check CHECK (status IN ('open', 'resolved', 'dismissed')),
                CONSTRAINT reports_resolution_check CHECK (
                    (status = 'open' AND resolved_by IS NULL AND resolution_note IS NULL AND resolved_at IS NULL)
                    OR (status IN ('resolved', 'dismissed') AND resolved_by IS NOT NULL
                        AND resolution_note IS NOT NULL AND length(trim(resolution_note)) > 0
                        AND resolved_at IS NOT NULL AND resolved_at >= created_at)
                )
            )
            SQL);

        // Target/context identifiers intentionally survive deletion of their
        // objects. Existence and access are validated by the future C09 API.
        DB::statement("CREATE UNIQUE INDEX reports_one_open_per_reporter_target ON reports (reporter_id, target_type, target_id) WHERE status = 'open'");
        DB::statement('CREATE INDEX reports_status_created_index ON reports (status, created_at, id)');
        DB::statement('CREATE INDEX reports_target_index ON reports (target_type, target_id)');
        DB::statement('CREATE INDEX reports_context_index ON reports (context_type, context_id)');
        DB::statement('CREATE INDEX reports_resolved_by_index ON reports (resolved_by)');
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
