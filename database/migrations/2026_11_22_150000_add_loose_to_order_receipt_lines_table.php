<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part of a pack on a delivery.
 *
 * A pharmacy opens packs, and what arrives is not always whole ones: ten
 * tablets left of a batch whose pack holds thirty. The line keeps the loose
 * pieces beside the whole packs, and how many pieces the pack held when it
 * was recorded, so its value can always be worked out the same way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_receipt_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('order_receipt_lines', 'loose')) {
                $table->unsignedInteger('loose')->default(0)->after('packs');
            }
            if (! Schema::hasColumn('order_receipt_lines', 'units_per_pack')) {
                $table->unsignedInteger('units_per_pack')->default(1)->after('loose');
            }
        });
    }

    public function down(): void
    {
        Schema::table('order_receipt_lines', fn (Blueprint $table) => $table->dropColumn(['loose', 'units_per_pack']));
    }
};
