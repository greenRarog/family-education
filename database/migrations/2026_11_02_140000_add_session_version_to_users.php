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
            $table->unsignedBigInteger('session_version')->default(0);
        });
    }

    public function down(): void
    {
        if (DB::table('users')->where('session_version', '>', 0)->exists()) {
            throw new RuntimeException('Session revocation cannot be rolled back without a separate session invalidation plan.');
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('session_version'));
    }
};
