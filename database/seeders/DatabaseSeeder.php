<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\AnalyticsCache;
use App\Support\AuditLogger;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with synthetic demo data.
     * All people, emails and phone numbers are fictional.
     */
    public function run(): void
    {
        AuditLogger::withoutAuditing(function () {
            User::updateOrCreate(['email' => 'admin@rbel-crm.test'], [
                'name' => 'Andrea L. Navarro',
                'password' => 'password',
                'role' => 'admin',
                'job_title' => 'Senior Financial Advisor',
                'license_number' => 'IC-LIC-000001',
                'phone' => '+63 917 000 0001',
                'bio' => 'Unit manager focusing on family protection and retirement planning.',
                'email_verified_at' => now(),
            ]);

            User::updateOrCreate(['email' => 'advisor@rbel-crm.test'], [
                'name' => 'Marco Villanueva',
                'password' => 'password',
                'role' => 'advisor',
                'job_title' => 'Financial Advisor',
                'license_number' => 'IC-LIC-000002',
                'email_verified_at' => now(),
            ]);

            User::updateOrCreate(['email' => 'assistant@rbel-crm.test'], [
                'name' => 'Joy Santiago',
                'password' => 'password',
                'role' => 'assistant',
                'job_title' => 'Client Service Assistant',
                'email_verified_at' => now(),
            ]);

            $this->call([
                ReferenceDataSeeder::class,
                DemoDataSeeder::class,
            ]);
        });

        AnalyticsCache::flush();
    }
}
