<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Images uploaded for email templates. A template body references one with
 * the token {{image:ID}}; the file itself lives on the private disk and is
 * embedded inline (cid:) when the email is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_images', function (Blueprint $table) {
            $table->id();
            $table->string('path', 255);
            $table->string('original_name', 191);
            $table->string('mime', 60);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_images');
    }
};
