<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The bill's number within its day, counting from 1 each morning, so
         * a reference reads as the date it was sold on: 2910260001 is the
         * first bill of 29 Oct 2026.
         *
         * Null on bills issued before this existed. Those keep the reference
         * they were printed with — it is on paper the customer is holding and
         * on the day's entry — rather than being renumbered after the fact.
         */
        Schema::table('pos_bills', function (Blueprint $table) {
            $table->unsignedSmallInteger('daily_no')->nullable()->after('bill_no');

            $table->unique(['business_id', 'business_date', 'daily_no']);
        });
    }

    public function down(): void
    {
        Schema::table('pos_bills', function (Blueprint $table) {
            $table->dropUnique(['business_id', 'business_date', 'daily_no']);
            $table->dropColumn('daily_no');
        });
    }
};
