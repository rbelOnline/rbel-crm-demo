<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A single normalized table for every human the CRM knows about.
 *
 * The same row can act as Policy Owner, Policy Insured and/or Beneficiary;
 * roles are expressed by the foreign keys that point at it, never by
 * duplicating the person's details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('persons', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 80);
            $table->string('middle_name', 80)->nullable();
            $table->string('last_name', 80);
            $table->date('birthdate')->nullable();
            $table->enum('gender', ['male', 'female', 'other'])->nullable();
            $table->string('email', 191)->nullable();
            $table->string('mobile_number', 30)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('occupation', 120)->nullable();
            $table->text('notes')->nullable();
            // A beneficiary-only person is stored here too, but is not listed as a client.
            $table->boolean('is_client')->default(true);
            $table->timestamps();

            $table->index(['last_name', 'first_name']);
            $table->index('first_name');
            $table->index('birthdate');
            $table->index('email');
            $table->index('mobile_number');
            $table->index(['is_client', 'last_name']);
        });

        // Generated columns: a searchable/sortable display name and a birthday
        // key (MMDD) so "birthdays this month" is an index range scan.
        DB::statement("ALTER TABLE persons
            ADD COLUMN full_name VARCHAR(245)
                GENERATED ALWAYS AS (CONCAT_WS(' ', first_name, middle_name, last_name)) STORED AFTER last_name,
            ADD COLUMN birth_md CHAR(4)
                GENERATED ALWAYS AS (DATE_FORMAT(birthdate, '%m%d')) STORED AFTER birthdate,
            ADD INDEX persons_full_name_index (full_name),
            ADD INDEX persons_birth_md_index (birth_md)");
    }

    public function down(): void
    {
        Schema::dropIfExists('persons');
    }
};
