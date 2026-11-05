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
        Schema::table('account_deletions', function (Blueprint $table): void {
            $table->string('request_token_hash', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        if (DB::table('account_deletions')->whereNotNull('request_token_hash')->exists()) {
            throw new RuntimeException('Deletion status rollback requires preserving outstanding receipts.');
        }
        Schema::table('account_deletions', function (Blueprint $table): void {
            $table->dropUnique(['request_token_hash']);
            $table->dropColumn('request_token_hash');
        });
    }
};
