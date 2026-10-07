<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repeating personal schedule items. A series is one row: `date` is its first day,
 * occurrences are worked out per requested range (App\Models\ScheduleItem::occurrences),
 * and single days removed from a series are listed in `skip_dates`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->enum('repeat', ['none', 'daily', 'weekly', 'monthly', 'yearly'])->default('none')->after('end_time');
            $table->date('repeat_until')->nullable()->after('repeat');
            $table->json('skip_dates')->nullable()->after('repeat_until');
            $table->index(['user_id', 'repeat']);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_items', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'repeat']);
            $table->dropColumn(['repeat', 'repeat_until', 'skip_dates']);
        });
    }
};
