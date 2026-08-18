<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('advertisement_subject', function (Blueprint $table) {
            $table->foreignId('advertisement_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('subject_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unique(['advertisement_id', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::drop('advertisement_subject');
    }
};
