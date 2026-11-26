<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What each batch cost and sells for, as counted. A batch bought last
         * month at the old rate is worth that rate, not today's: a shortage is
         * valued batch by batch at its own price.
         */
        Schema::table('stock_count_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 18, 2)->nullable()->after('counted_loose');
            $table->decimal('mrp', 18, 2)->nullable()->after('unit_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_lines', fn (Blueprint $table) => $table->dropColumn(['unit_cost', 'mrp']));
    }
};
