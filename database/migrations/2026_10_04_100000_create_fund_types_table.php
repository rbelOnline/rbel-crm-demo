<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fund Types: a managed list (Fund Types module, e.g. "Peso Equity Fund"), chosen per policy from a
 * dropdown on the Clients form. Optional on a policy; a fund type used by a
 * policy cannot be deleted (mark it inactive instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('policies', function (Blueprint $table) {
            $table->foreignId('fund_type_id')->nullable()->after('sum_assured')->constrained('fund_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('policies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fund_type_id');
        });

        Schema::dropIfExists('fund_types');
    }
};
