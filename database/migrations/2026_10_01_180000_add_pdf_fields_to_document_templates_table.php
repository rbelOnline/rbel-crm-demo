<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Placeholders positioned on a PDF template with the in-app PDF editor:
 * [{key, page, x, y, w, h, size, align}], x/y/w/h as fractions of the page as
 * displayed (top-left origin). They are stamped with the client's details when
 * a document is added to a client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->json('pdf_fields')->nullable()->after('placeholders');
        });
    }

    public function down(): void
    {
        Schema::table('document_templates', function (Blueprint $table) {
            $table->dropColumn('pdf_fields');
        });
    }
};
