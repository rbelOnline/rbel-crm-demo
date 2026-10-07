<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The age graphs now group clients by generation (birth year; App\Support\Generations,
 * computed in AnalyticsService) instead of configurable age ranges, so the age_ranges
 * table and the sp_age_distribution procedure that used it are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP PROCEDURE IF EXISTS sp_age_distribution');
        Schema::dropIfExists('age_ranges');
    }

    public function down(): void
    {
        throw new RuntimeException('Age ranges were replaced by generations; restore them from an earlier migration state if needed.');
    }
};
