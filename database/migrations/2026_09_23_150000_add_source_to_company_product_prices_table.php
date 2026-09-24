<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What moved this price.
         *
         * A price list was the only thing that could, so the import id was
         * enough. A delivery can move one too — the company invoiced at a rate
         * their list does not say — and that is worth being able to point at
         * later, so the source is recorded the same way a stock movement
         * records what caused it.
         */
        Schema::table('company_product_prices', function (Blueprint $table) {
            $table->nullableMorphs('source');
        });
    }

    public function down(): void
    {
        Schema::table('company_product_prices', function (Blueprint $table) {
            $table->dropMorphs('source');
        });
    }
};
