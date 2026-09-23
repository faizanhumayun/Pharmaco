<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each line carries its own copy of what was ordered and at what rate.
        // The catalogue moves with every price list; an order sent in March has
        // to keep saying what it said in March, so it does not read its figures
        // back out of a product that has since been repriced.
        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // Kept for the link back to the catalogue, never for its values.
            $table->foreignId('company_product_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            $table->string('code', 60)->nullable();
            $table->string('brand_name', 200);
            $table->string('generic_name', 255)->nullable();
            $table->string('strength', 80)->nullable();
            $table->string('dosage_form', 40)->nullable();
            $table->string('pack_size', 60)->nullable();

            // The carton size at the time of ordering, so "4 cartons" still
            // means the same number of packs however the catalogue changes.
            $table->unsignedInteger('case_size')->nullable();

            $table->unsignedInteger('cartons')->nullable();
            $table->unsignedInteger('packs');
            $table->decimal('rate', 18, 2);

            // Null means "whatever the order says". A figure here is a product
            // the company priced differently from the rest of the order, and it
            // overrides the order's rate for this line alone.
            $table->decimal('discount_percent', 6, 3)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'position']);
        });

        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT chk_order_lines_quantities CHECK (
            packs > 0
            AND rate >= 0
            AND (cartons IS NULL OR cartons > 0)
            AND (case_size IS NULL OR case_size > 0)
            AND (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
