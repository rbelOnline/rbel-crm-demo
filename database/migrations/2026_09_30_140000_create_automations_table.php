<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Daily email automations (birthday greetings, premium due reminders).
 * email_logs gains `automation` and a unique `dedupe_key` so an automated
 * email is sent at most once per recipient/policy per day, however often
 * the job runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->boolean('enabled')->default(false);
            $table->foreignId('email_template_id')->nullable()->constrained('email_templates')->nullOnDelete();
            $table->time('send_time')->default('08:00:00');
            $table->foreignId('sender_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_run_summary')->nullable();
            $table->timestamps();
        });

        Schema::table('email_logs', function (Blueprint $table) {
            $table->string('automation', 40)->nullable()->after('policy_id');
            // Held only by successfully sent automated emails; a failed attempt releases it so a later run can retry.
            $table->string('dedupe_key', 191)->nullable()->unique()->after('automation');
            $table->index(['automation', 'created_at']);
        });

        // Start disabled, pointed at the matching templates when they exist.
        $template = fn (string $name) => DB::table('email_templates')->where('name', $name)->value('id');
        $admin = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
        $now = now();

        DB::table('automations')->insert([
            ['key' => 'birthday_greeting', 'enabled' => false, 'email_template_id' => $template('Birthday Greeting'), 'send_time' => '08:00:00', 'sender_user_id' => $admin, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'premium_due', 'enabled' => false, 'email_template_id' => $template('Premium Due Reminder'), 'send_time' => '08:00:00', 'sender_user_id' => $admin, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::table('email_logs', function (Blueprint $table) {
            $table->dropIndex(['automation', 'created_at']);
            $table->dropUnique(['dedupe_key']);
            $table->dropColumn(['automation', 'dedupe_key']);
        });

        Schema::dropIfExists('automations');
    }
};
