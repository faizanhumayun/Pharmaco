<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What the counter was asked to show when this bill was rung up.
         *
         * Presentation, not money — but worth keeping for two reasons. Reopening
         * a bill printed as a net bill should give back a net bill rather than
         * the plain one, and when someone says "it did not show me the balance",
         * there is otherwise nothing on the record to tell them what the till
         * was actually asked for.
         */
        Schema::table('pos_bills', function (Blueprint $table) {
            $table->boolean('as_net_bill')->default(false)->after('note');
            $table->boolean('asked_for_print')->default(false)->after('as_net_bill');
            $table->boolean('asked_for_balance')->default(false)->after('asked_for_print');
        });
    }

    public function down(): void
    {
        Schema::table('pos_bills', fn (Blueprint $t) => $t->dropColumn([
            'as_net_bill', 'asked_for_print', 'asked_for_balance',
        ]));
    }
};
