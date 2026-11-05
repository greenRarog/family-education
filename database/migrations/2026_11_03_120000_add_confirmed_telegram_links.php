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
            $table->timestamp('telegram_confirmed_at')->nullable();
            $table->string('telegram_bot_id')->nullable();
            $table->unsignedBigInteger('telegram_binding_version')->default(0);
        });
        Schema::create('telegram_link_attempts', function (Blueprint $table): void {
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
        Schema::create('telegram_processed_updates', function (Blueprint $table): void {
            $table->string('bot_id');
            $table->unsignedBigInteger('update_id');
            $table->timestamp('received_at')->index();
            $table->primary(['bot_id', 'update_id']);
        });
        Schema::create('telegram_polling_cursors', function (Blueprint $table): void {
            $table->string('bot_id')->primary();
            $table->unsignedBigInteger('next_update_id')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_polling_cursors');
        Schema::dropIfExists('telegram_processed_updates');
        Schema::dropIfExists('telegram_link_attempts');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['telegram_confirmed_at', 'telegram_bot_id', 'telegram_binding_version']));
    }
};
