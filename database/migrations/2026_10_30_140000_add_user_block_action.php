<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Native ADD COLUMN preserves existing users and their dependants on
        // SQLite as well as PostgreSQL; no users-table rebuild is needed.
        DB::statement('ALTER TABLE users ADD COLUMN block_action_id BIGINT NULL REFERENCES admin_actions(id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX users_block_action_id_index ON users (block_action_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_block_action_id_index');
        DB::statement('ALTER TABLE users DROP COLUMN block_action_id');
    }
};
