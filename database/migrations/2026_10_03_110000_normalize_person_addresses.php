<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalize addresses: each distinct address is stored once in `addresses` and
 * shared by everyone who lives there (e.g. a household's owner and insured),
 * instead of being repeated as text on every person. persons.address becomes
 * persons.address_id; the Person model still exposes `address` as text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            // Binary collation: "1 Mabini St." and "1 mabini st." stay distinct, exactly as typed.
            $table->string('address', 255)->collation('utf8mb4_bin')->unique();
            $table->timestamps();
        });

        $now = now()->toDateTimeString();
        DB::statement("INSERT INTO addresses (address, created_at, updated_at)
            SELECT DISTINCT TRIM(address) COLLATE utf8mb4_bin, '{$now}', '{$now}' FROM persons
            WHERE address IS NOT NULL AND TRIM(address) <> ''");

        Schema::table('persons', function (Blueprint $table) {
            $table->foreignId('address_id')->nullable()->after('mobile_number')->constrained('addresses')->nullOnDelete();
        });

        DB::statement('UPDATE persons p JOIN addresses a ON a.address = TRIM(p.address) COLLATE utf8mb4_bin SET p.address_id = a.id');

        Schema::table('persons', function (Blueprint $table) {
            $table->dropColumn('address');
        });
    }

    public function down(): void
    {
        Schema::table('persons', function (Blueprint $table) {
            $table->string('address', 255)->nullable()->after('mobile_number');
        });

        DB::statement('UPDATE persons p JOIN addresses a ON a.id = p.address_id SET p.address = a.address');

        Schema::table('persons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('address_id');
        });

        Schema::dropIfExists('addresses');
    }
};
