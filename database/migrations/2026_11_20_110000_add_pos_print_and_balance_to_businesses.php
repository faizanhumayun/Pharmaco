<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the till does after a sale, as a setting of the business: show the
 * bill ready to print, and show the customer's account. Like the net bill,
 * the till can still switch either for a single bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('pos_print')->default(false)->after('pos_net_bill');
            $table->boolean('pos_balance')->default(false)->after('pos_print');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn(['pos_print', 'pos_balance']));
    }
};
