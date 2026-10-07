<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDF editor on a client document: the fields placed on it (same format as
 * document_templates.pdf_fields) and the unfilled original they are stamped onto.
 * Every save re-stamps the original, so fields can be moved or removed later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->json('pdf_fields')->nullable()->after('edited_at');
            $table->string('base_path', 255)->nullable()->after('pdf_fields');
        });
    }

    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dropColumn(['pdf_fields', 'base_path']);
        });
    }
};
