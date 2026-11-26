<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One company's rack, counted in one go.
         *
         * Shops keep each company's goods together, so the round is a company
         * at a time, each at least once a month. An audit is a set of ordinary
         * stock counts — one per product, written by the same action as any
         * other count — gathered under one heading so they can be read as one
         * report and approved together. Like a count, it moves no stock until
         * it is approved.
         */
        Schema::create('company_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');

            // counting → submitted → approved, or closed with no change.
            $table->string('status', 20)->default('counting');
            $table->boolean('by_batch')->default(true);
            $table->boolean('include_empty')->default(false);

            // The period the report reads the till against: from the last
            // audit of this company, else the business's opening.
            $table->date('period_from')->nullable();

            $table->foreignId('started_by')->constrained('users');
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->string('note', 255)->nullable();

            $table->timestamps();

            $table->index(['business_id', 'company_id', 'status']);
            $table->index(['business_id', 'business_date']);
        });

        /*
         * The products on the sheet, fixed when the audit starts so a product
         * sold out halfway through does not drop off it. Each points at its
         * latest count, and holds the prices it was valued at when counted.
         */
        Schema::create('company_audit_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_audit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_count_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('unit_cost', 18, 2)->nullable();
            $table->decimal('mrp', 18, 2)->nullable();

            $table->timestamps();

            $table->unique(['company_audit_id', 'company_product_id']);
            $table->index(['business_id', 'company_audit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_audit_items');
        Schema::dropIfExists('company_audits');
    }
};
