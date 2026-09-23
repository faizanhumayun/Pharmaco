<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What actually turned up, recorded against what was asked for.
        //
        // A separate table rather than a "received" column on the order line,
        // for the same reason a correction is a new transaction: the order is
        // frozen the moment it is sent and has to keep saying what was ordered.
        // The receipt says what came. The difference between the two is the
        // whole point, and it is derived, never stored.
        //
        // order_line_id is null for something the company sent that was never
        // ordered — which is exactly the case a column on the order line could
        // not have represented at all.
        Schema::create('order_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_line_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('company_product_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedSmallInteger('position')->default(0);

            $table->string('code', 60)->nullable();
            $table->string('brand_name', 200);
            $table->string('generic_name', 255)->nullable();
            $table->string('strength', 80)->nullable();
            $table->string('dosage_form', 40)->nullable();
            $table->string('pack_size', 60)->nullable();
            $table->unsignedInteger('case_size')->nullable();

            $table->unsignedInteger('cartons')->nullable();
            $table->unsignedInteger('packs');
            $table->decimal('rate', 18, 2);
            $table->decimal('discount_percent', 6, 3)->nullable();

            $table->timestamps();

            $table->index(['order_id', 'position']);
            // One receipt line per ordered line; the extras are the null ones.
            $table->unique(['order_id', 'order_line_id']);
        });

        DB::statement('ALTER TABLE order_receipt_lines ADD CONSTRAINT chk_receipt_lines_quantities CHECK (
            packs > 0
            AND rate >= 0
            AND (cartons IS NULL OR cartons > 0)
            AND (case_size IS NULL OR case_size > 0)
            AND (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_receipt_lines');
    }
};
