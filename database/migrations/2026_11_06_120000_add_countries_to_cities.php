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
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique();
            $table->string('name');
            $table->timestamps();
        });
        // All cities in the pre-country schema belong to the existing Russian directory.
        $russia = DB::table('countries')->insertGetId([
            'code' => 'RU', 'name' => 'Россия', 'created_at' => now(), 'updated_at' => now(),
        ]);
        Schema::table('cities', function (Blueprint $table) {
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete();
        });
        DB::table('cities')->update(['country_id' => $russia]);
        Schema::table('cities', function (Blueprint $table) {
            $table->unsignedBigInteger('country_id')->nullable(false)->change();
            $table->dropUnique(['name']);
            $table->unique(['country_id', 'name']);
        });
    }

    public function down(): void
    {
        $russia = DB::table('countries')->where('code', 'RU')->value('id');
        if (DB::table('cities')->where('country_id', '!=', $russia ?? 0)->exists()) {
            throw new LogicException('Cannot remove countries while non-Russian cities exist.');
        }
        Schema::table('cities', function (Blueprint $table) {
            $table->dropUnique(['country_id', 'name']);
            $table->dropConstrainedForeignId('country_id');
            $table->unique('name');
        });
        Schema::dropIfExists('countries');
    }
};
