<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One item on a bill. The name and both prices are copied as they were
         * at the moment of sale: a price list changes, a sold bill does not.
         */
        Schema::create('pos_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_bill_id')->constrained()->cascadeOnDelete();

            // Kept even if the product is later removed from the catalogue.
            $table->foreignId('company_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);

            $table->integer('quantity');
            $table->decimal('unit_price', 18, 2);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();

            $table->index(['business_id', 'company_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_bill_lines');
    }
};
