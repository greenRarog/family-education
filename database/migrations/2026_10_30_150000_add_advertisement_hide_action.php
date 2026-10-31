<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE advertisements ADD COLUMN hide_action_id BIGINT NULL REFERENCES admin_actions(id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX advertisements_hide_action_id_index ON advertisements (hide_action_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX advertisements_hide_action_id_index');
        DB::statement('ALTER TABLE advertisements DROP COLUMN hide_action_id');
    }
};
