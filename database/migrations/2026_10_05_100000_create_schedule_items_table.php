<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal schedule items on the Calendar (blocks of time by the hour, besides
 * appointments). Private: each user sees only their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 150);
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time')->nullable();
            $table->enum('label', ['green', 'blue', 'yellow', 'red'])->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'date', 'start_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_items');
    }
};
