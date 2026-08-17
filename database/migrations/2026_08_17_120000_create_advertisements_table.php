<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advertisements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('status')->default('draft');
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('metro_station_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('participant_age_from');
            $table->unsignedTinyInteger('participant_age_to');
            $table->text('description');
            $table->timestamp('published_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['type', 'status']);
        });

        Schema::create('advertisement_child', function (Blueprint $table) {
            $table->foreignId('advertisement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('child_id')->constrained()->cascadeOnDelete();
            $table->primary(['advertisement_id', 'child_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advertisement_child');
        Schema::dropIfExists('advertisements');
    }
};
