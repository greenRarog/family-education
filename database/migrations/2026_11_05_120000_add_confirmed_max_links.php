<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('max_user_id')->nullable()->unique();
            $table->timestamp('max_confirmed_at')->nullable();
            $table->string('max_bot_id')->nullable();
            $table->unsignedBigInteger('max_binding_version')->default(0);
        });
        Schema::create('max_link_attempts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nonce_hash', 64)->nullable()->unique();
            $table->string('session_hash', 64);
            $table->unsignedBigInteger('session_version');
            $table->string('bot_id');
            $table->string('candidate_chat_id')->nullable();
            $table->string('code_hash', 64)->nullable();
            $table->unsignedSmallInteger('wrong_codes')->default(0);
            $table->unsignedBigInteger('confirmed_version')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
        });
        Schema::create('max_processed_updates', function (Blueprint $table): void {
            $table->string('bot_id');
            $table->string('update_id', 64);
            $table->timestamp('received_at')->index();
            $table->primary(['bot_id', 'update_id']);
        });
        Schema::table('notification_settings', fn (Blueprint $table) => $table->boolean('max_enabled')->default(false));

    }

    public function down(): void
    {
        Schema::table('notification_settings', fn (Blueprint $table) => $table->dropColumn('max_enabled'));
        Schema::dropIfExists('max_processed_updates');
        Schema::dropIfExists('max_link_attempts');
        Schema::table('users', fn (Blueprint $table) => $table->dropUnique(['max_user_id']));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['max_user_id', 'max_confirmed_at', 'max_bot_id', 'max_binding_version']));
    }
};
