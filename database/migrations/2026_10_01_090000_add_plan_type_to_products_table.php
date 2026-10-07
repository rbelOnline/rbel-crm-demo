<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products become a managed module: Plan Name (`name`) and Plan Type
 * (VUL = variable universal life, TRAD = traditional). `code` and `category`
 * are kept for existing data but are no longer required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('plan_type', ['VUL', 'TRAD'])->default('TRAD')->after('name');
            $table->string('code', 30)->nullable()->change();
            $table->index(['plan_type', 'name']);
        });

        // Existing plans: investment-linked ones are VUL, the rest traditional.
        DB::table('products')->where('category', 'investment')->orWhere('name', 'like', '%VUL%')->update(['plan_type' => 'VUL']);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['plan_type', 'name']);
            $table->dropColumn('plan_type');
        });
    }
};
