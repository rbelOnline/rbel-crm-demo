<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app editor's working copy of a client document (sanitized HTML).
 * Once a document has been edited in the CRM it is reopened from this copy,
 * and the .docx file is regenerated from it on every save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->longText('content_html')->nullable()->after('unfilled');
            $table->timestamp('edited_at')->nullable()->after('content_html');
        });
    }

    public function down(): void
    {
        Schema::table('client_documents', function (Blueprint $table) {
            $table->dropColumn(['content_html', 'edited_at']);
        });
    }
};
