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
        Schema::create('teacher_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('surname');
            $table->foreignId('city_id')->constrained()->restrictOnDelete();
            $table->foreignId('district_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('metro_station_id')->nullable()->constrained()->nullOnDelete();
            $table->text('about')->nullable();
            $table->text('education')->nullable();
            $table->boolean('public_profile_requested')->default(false);
            $table->string('public_profile_status')->default('private');
            $table->timestamp('public_profile_reviewed_at')->nullable();
            $table->foreignId('public_profile_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('teacher_profile_subject', function (Blueprint $table) {
            $table->foreignId('teacher_profile_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->primary(['teacher_profile_id', 'subject_id']);
        });

        Schema::table('advertisements', function (Blueprint $table) {
            $table->string('lesson_type')->nullable();
            $table->unsignedTinyInteger('participant_age_from')->nullable()->change();
            $table->unsignedTinyInteger('participant_age_to')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('advertisements')
            ->where('type', 'teacher_to_service')
            ->update([
                'participant_age_from' => 0,
                'participant_age_to' => 0,
            ]);

        Schema::table('advertisements', function (Blueprint $table) {
            $table->dropColumn('lesson_type');
            $table->unsignedTinyInteger('participant_age_from')->nullable(false)->change();
            $table->unsignedTinyInteger('participant_age_to')->nullable(false)->change();
        });

        Schema::dropIfExists('teacher_profile_subject');
        Schema::dropIfExists('teacher_profiles');
    }
};
