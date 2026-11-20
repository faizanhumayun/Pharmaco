<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * When this batch goes out of date.
         *
         * It belongs to the delivery, not to the product: the same medicine
         * bought twice has two expiries, and the one on the shelf is whichever
         * batch was put there. Recording it against the goods as they arrive is
         * the only moment it is known for certain — the carton is in someone's
         * hands and the date is printed on it.
         *
         * Nullable, because not everything expires: surgical items, devices and
         * sundries carry no date, and a delivery of them must not be held up.
         */
        Schema::table('order_receipt_lines', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->after('packs');
        });

        // And on the movement, so the count itself knows what expires when
        // rather than having to trace every pack back to its delivery.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->date('expires_on')->nullable()->after('packs');
            $table->index(['business_id', 'expires_on']);
        });
    }

    public function down(): void
    {
        Schema::table('order_receipt_lines', fn (Blueprint $t) => $t->dropColumn('expires_on'));
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex(['business_id', 'expires_on']);
            $table->dropColumn('expires_on');
        });
    }
};
