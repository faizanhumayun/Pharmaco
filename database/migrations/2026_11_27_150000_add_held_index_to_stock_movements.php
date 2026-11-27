<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What is held is a sum over every movement of every product. With the
 * quantities in the index the database answers it from the index alone,
 * which for a pharmacy with a year of bills is a third of the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->index(['business_id', 'company_product_id', 'packs', 'loose'], 'stock_movements_held_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_held_index');
        });
    }
};
