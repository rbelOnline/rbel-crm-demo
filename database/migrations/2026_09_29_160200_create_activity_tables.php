<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->date('appointment_date');
            $table->time('appointment_time');
            $table->string('location', 191)->nullable();
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'rescheduled'])->default('scheduled');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['appointment_date', 'appointment_time']);
            $table->index(['status', 'appointment_date']);
        });

        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->enum('category', ['sales', 'clients', 'savings', 'retirement', 'education', 'other'])->default('sales');
            $table->decimal('target_amount', 16, 2);
            $table->decimal('current_amount', 16, 2)->default(0);
            $table->date('target_date');
            $table->enum('status', ['not_started', 'in_progress', 'achieved', 'cancelled'])->default('not_started');
            $table->timestamps();

            $table->index(['status', 'target_date']);
        });

        DB::statement('ALTER TABLE goals
            ADD CONSTRAINT goals_target_amount_chk CHECK (target_amount > 0),
            ADD CONSTRAINT goals_current_amount_chk CHECK (current_amount >= 0)');

        Schema::create('reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('persons')->cascadeOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('policies')->cascadeOnDelete();
            $table->enum('type', ['follow_up', 'payment', 'delivery', 'birthday', 'other'])->default('follow_up');
            $table->string('title', 150);
            $table->text('notes')->nullable();
            $table->date('due_date');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['due_date', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminders');
        Schema::dropIfExists('goals');
        Schema::dropIfExists('appointments');
    }
};
