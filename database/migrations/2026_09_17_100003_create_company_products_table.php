<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company's catalogue: what it sells, and the three prices that matter
        // — what the patient pays (mrp), what the pharmacy pays (trade_price),
        // and what this business pays the company (purchase_rate).
        Schema::create('company_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            $table->string('code', 60)->nullable();
            $table->string('brand_name', 200);
            $table->string('generic_name', 255)->nullable();
            $table->string('strength', 80)->nullable();
            $table->string('dosage_form', 40)->nullable();
            $table->string('pack_size', 60)->nullable();
            $table->string('pack_type', 40)->nullable();

            $table->decimal('mrp', 18, 2)->nullable();
            $table->decimal('trade_price', 18, 2)->nullable();
            $table->decimal('purchase_rate', 18, 2)->nullable();

            // Packs to a carton.
            $table->unsignedInteger('case_size')->nullable();

            // How a re-imported list recognises a product it has seen before:
            // the company's own code when it gives one, otherwise the brand,
            // strength and pack size normalised together.
            $table->string('match_key', 191);

            $table->boolean('is_active')->default(true);

            $table->foreignId('first_import_id')->nullable()
                ->constrained('company_product_imports')->nullOnDelete();
            $table->foreignId('last_import_id')->nullable()
                ->constrained('company_product_imports')->nullOnDelete();
            $table->date('priced_on')->nullable();

            $table->timestamps();

            $table->unique(['company_id', 'match_key']);
            $table->index(['business_id', 'brand_name']);
            $table->index(['business_id', 'generic_name']);
        });

        // The service normalises these before it writes. The constraints are
        // here so a price stays impossible to store wrong even if it does not.
        DB::statement('ALTER TABLE company_products ADD CONSTRAINT chk_company_products_prices CHECK (
            (mrp IS NULL OR mrp >= 0)
            AND (trade_price IS NULL OR trade_price >= 0)
            AND (purchase_rate IS NULL OR purchase_rate >= 0)
            AND (case_size IS NULL OR case_size > 0)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('company_products');
    }
};
