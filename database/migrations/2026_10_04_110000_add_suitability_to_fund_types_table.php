<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suitability of a fund type: the investor risk profile it fits (App\Models\FundType::SUITABILITIES).
 * Required when a fund type is saved; nullable here only so existing rows can be filled in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fund_types', function (Blueprint $table) {
            $table->enum('suitability', ['conservative', 'moderately_conservative', 'moderate', 'moderately_aggressive', 'aggressive'])
                ->nullable()->after('name');
            $table->index('suitability');
        });
    }

    public function down(): void
    {
        Schema::table('fund_types', function (Blueprint $table) {
            $table->dropIndex(['suitability']);
            $table->dropColumn('suitability');
        });
    }
};
