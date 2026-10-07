<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('subject', 200);
            $table->longText('body');
            $table->enum('status', ['active', 'draft', 'archived'])->default('draft')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('person_id')->nullable()->constrained('persons')->nullOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('policies')->nullOnDelete();
            // Only the address and subject are kept; the rendered body is not
            // stored so personal policy details do not accumulate in logs.
            $table->string('recipient', 191);
            $table->string('subject', 200);
            $table->enum('status', ['pending', 'sent', 'failed'])->default('pending');
            $table->string('error_message', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('module', 40);
            $table->unsignedBigInteger('record_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['module', 'record_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index('created_at');
        });

        // Configurable age buckets used by the age analytics (owner vs insured).
        Schema::create('age_ranges', function (Blueprint $table) {
            $table->id();
            $table->string('label', 40);
            $table->unsignedTinyInteger('min_age');
            $table->unsignedTinyInteger('max_age')->nullable(); // NULL = open-ended (e.g. 66+)
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('min_age');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('age_ranges');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_templates');
    }
};
