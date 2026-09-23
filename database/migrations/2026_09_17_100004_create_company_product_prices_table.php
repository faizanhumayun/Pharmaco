<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What a product cost on a given day, written once per import that
        // changed it. company_products carries the current figures for speed;
        // this table is the record, and nothing ever rewrites a row of it.
        Schema::create('company_product_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_product_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_product_import_id')->nullable()
                ->constrained()->restrictOnDelete();

            $table->date('business_date');

            $table->decimal('mrp', 18, 2)->nullable();
            $table->decimal('trade_price', 18, 2)->nullable();
            $table->decimal('purchase_rate', 18, 2)->nullable();
            $table->unsignedInteger('case_size')->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['company_product_id', 'business_date'], 'product_prices_history_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_product_prices');
    }
};
