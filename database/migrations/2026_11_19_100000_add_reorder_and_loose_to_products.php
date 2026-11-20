<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reorder levels, loose selling, and batch numbers.
 *
 * A pack can be broken open: units_per_pack says how many pieces are inside,
 * and a movement or a bill line can carry loose pieces beside whole packs.
 * Stock is still the sum of its movements — packs × pieces per pack + loose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_products', function (Blueprint $table) {
            // Zero means "no reorder level set": nothing is flagged.
            $table->unsignedInteger('reorder_level')->default(0)->after('case_size');
            $table->unsignedInteger('units_per_pack')->default(1)->after('reorder_level');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('loose')->default(0)->after('packs');
            $table->string('batch_no', 60)->nullable()->after('expires_on');
        });

        // A movement of loose pieces alone moves no whole pack.
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_stock_movements');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements CHECK (
            (packs <> 0 OR loose <> 0) AND (unit_cost IS NULL OR unit_cost >= 0)
        )');

        Schema::table('order_receipt_lines', function (Blueprint $table) {
            $table->string('batch_no', 60)->nullable()->after('expires_on');
        });

        Schema::table('pos_bill_lines', function (Blueprint $table) {
            $table->unsignedInteger('loose_quantity')->default(0)->after('quantity');
            $table->unsignedInteger('units_per_pack')->default(1)->after('loose_quantity');
        });

        DB::statement('ALTER TABLE company_products ADD CONSTRAINT chk_company_products_units CHECK (units_per_pack >= 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE company_products DROP CONSTRAINT chk_company_products_units');

        Schema::table('pos_bill_lines', fn (Blueprint $table) => $table->dropColumn(['loose_quantity', 'units_per_pack']));
        Schema::table('order_receipt_lines', fn (Blueprint $table) => $table->dropColumn('batch_no'));

        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT chk_stock_movements');
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn(['loose', 'batch_no']));
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements CHECK (
            packs <> 0 AND (unit_cost IS NULL OR unit_cost >= 0)
        )');

        Schema::table('company_products', fn (Blueprint $table) => $table->dropColumn(['reorder_level', 'units_per_pack']));
    }
};
