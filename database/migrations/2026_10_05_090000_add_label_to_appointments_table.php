<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colour label of an appointment (Calendar colour coding). The name shown for each
 * colour is a setting (App\Services\CalendarLabels), so labels can be renamed freely.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('label', ['green', 'blue', 'yellow', 'red'])->nullable()->after('status');
            $table->index(['label', 'appointment_date']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['label', 'appointment_date']);
            $table->dropColumn('label');
        });
    }
};
