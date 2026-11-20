<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the till starts each bill as the net bill — the one quoted before
 * the trade discount. A setting of the business; the till can still switch it
 * for a single bill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('pos_net_bill')->default(false)->after('receipt_format');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('pos_net_bill'));
    }
};
