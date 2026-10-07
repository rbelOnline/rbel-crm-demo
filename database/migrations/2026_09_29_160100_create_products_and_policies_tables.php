<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120)->unique();
            $table->enum('category', ['life', 'health', 'investment', 'education', 'retirement', 'other'])->default('life');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('policies', function (Blueprint $table) {
            $table->id();
            $table->string('policy_number', 40)->unique();

            // Two separate relationships to persons. They may point at the same
            // person (self-insured) or at different people (e.g. parent owns,
            // child is insured). Never collapse these into one client_id.
            $table->foreignId('policy_owner_id')->constrained('persons')->restrictOnDelete();
            $table->foreignId('policy_insured_id')->constrained('persons')->restrictOnDelete();

            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->decimal('ape', 14, 2);
            $table->date('issued_date');
            $table->enum('mode_of_payment', ['annual', 'semi_annual', 'quarterly', 'monthly', 'single']);
            $table->decimal('sum_assured', 16, 2);
            $table->enum('status', ['pending', 'active', 'lapsed', 'surrendered', 'matured', 'cancelled'])->default('pending');
            $table->timestamp('status_changed_at')->nullable();
            $table->date('policy_delivery_date')->nullable();
            $table->boolean('is_orphan')->default(false);

            // Coverage document (Laravel Storage, private disk).
            $table->string('insurance_coverage_path')->nullable();
            $table->string('coverage_original_name')->nullable();
            $table->string('coverage_mime', 100)->nullable();
            $table->unsignedInteger('coverage_size')->nullable();
            $table->timestamp('coverage_uploaded_at')->nullable();

            $table->text('remarks')->nullable();
            $table->timestamps();

            // Composite indexes lead with the owner/insured key so role-scoped
            // lookups ("policies owned by X in year Y") are index range scans.
            $table->index(['policy_owner_id', 'issued_date']);
            $table->index(['policy_insured_id', 'issued_date']);
            $table->index(['status', 'issued_date']);
            $table->index('issued_date');
            $table->index(['product_id', 'issued_date']);
            $table->index('mode_of_payment');
            $table->index(['policy_delivery_date', 'status']);
            $table->index('is_orphan');
        });

        DB::statement('ALTER TABLE policies
            ADD CONSTRAINT policies_ape_chk CHECK (ape >= 0),
            ADD CONSTRAINT policies_sum_assured_chk CHECK (sum_assured >= 0),
            ADD COLUMN issued_month TINYINT UNSIGNED GENERATED ALWAYS AS (MONTH(issued_date)) STORED AFTER issued_date,
            ADD INDEX policies_issued_month_index (issued_month, status)');

        Schema::create('policy_beneficiaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_id')->constrained('policies')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->restrictOnDelete();
            $table->string('relationship', 40);
            $table->enum('beneficiary_type', ['primary', 'contingent'])->default('primary');
            $table->enum('designation', ['revocable', 'irrevocable'])->default('revocable');
            $table->decimal('allocation_percentage', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['policy_id', 'person_id']);
            $table->index('person_id');
        });

        DB::statement('ALTER TABLE policy_beneficiaries
            ADD CONSTRAINT policy_beneficiaries_allocation_chk
            CHECK (allocation_percentage IS NULL OR (allocation_percentage > 0 AND allocation_percentage <= 100))');
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_beneficiaries');
        Schema::dropIfExists('policies');
        Schema::dropIfExists('products');
    }
};
