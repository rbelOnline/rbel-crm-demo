<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suitability is now one of three: conservative, moderate, aggressive.
 * The removed in-between levels fold into the nearer end of the scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('fund_types')->where('suitability', 'moderately_conservative')->update(['suitability' => 'conservative']);
        DB::table('fund_types')->where('suitability', 'moderately_aggressive')->update(['suitability' => 'aggressive']);

        DB::statement("ALTER TABLE fund_types MODIFY suitability ENUM('conservative', 'moderate', 'aggressive') NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE fund_types MODIFY suitability ENUM('conservative', 'moderately_conservative', 'moderate', 'moderately_aggressive', 'aggressive') NULL");
    }
};
