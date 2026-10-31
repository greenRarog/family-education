<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_resolution_actions', function (Blueprint $table): void {
            $table->foreignId('report_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_action_id')->constrained()->restrictOnDelete();
            $table->primary(['report_id', 'admin_action_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_resolution_actions');
    }
};
