<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shelf holds whole packs and, where packs are opened, the pieces left in
 * the open one. A count now records both, on each side: what the books said
 * and what was found.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->unsignedInteger('expected_loose')->default(0)->after('expected_packs');
            $table->unsignedInteger('counted_loose')->default(0)->after('counted_packs');
        });
    }

    public function down(): void
    {
        Schema::table('stock_counts', fn (Blueprint $t) => $t->dropColumn(['expected_loose', 'counted_loose']));
    }
};
