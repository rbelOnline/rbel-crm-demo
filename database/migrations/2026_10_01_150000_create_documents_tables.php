<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documents module:
 *  - document_templates: reusable files (Word .docx templates are filled with a
 *    client's details using {{placeholders}}; other formats are copied as-is);
 *  - client_documents: files attached to a client record (policy), created from a
 *    template or uploaded directly.
 * Files live on the private disk and are only served through authenticated routes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150)->unique();
            $table->string('description', 500)->nullable();
            $table->string('path', 255);
            $table->string('original_name', 191);
            $table->string('extension', 10);
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            // Placeholders found in a .docx, e.g. ["full_name","policy_number"].
            $table->json('placeholders')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::create('client_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('document_template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            $table->string('name', 150);
            $table->enum('source', ['template', 'upload']);
            $table->string('path', 255);
            $table->string('original_name', 191);
            $table->string('extension', 10);
            $table->string('mime', 120);
            $table->unsignedInteger('size');
            // Template placeholders that had no value when the document was generated.
            $table->json('unfilled')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['policy_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_documents');
        Schema::dropIfExists('document_templates');
    }
};
