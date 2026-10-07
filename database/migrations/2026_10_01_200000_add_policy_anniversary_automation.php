<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Policy anniversary emails to the Policy Owner. Starts disabled, like the other automations. */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('automations')->insertOrIgnore([
            'key' => 'policy_anniversary',
            'enabled' => false,
            'email_template_id' => DB::table('email_templates')->where('name', 'Policy Anniversary')->value('id'),
            'send_time' => '08:00:00',
            'sender_user_id' => DB::table('users')->where('role', 'admin')->orderBy('id')->value('id'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('automations')->where('key', 'policy_anniversary')->delete();
    }
};
