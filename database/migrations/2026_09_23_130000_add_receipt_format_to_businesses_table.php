<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * What this business prints a bill on. Null means "whatever suits the
         * kind of business", so an existing business keeps behaving sensibly
         * without anyone being asked to choose.
         */
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('receipt_format', 20)->nullable()->after('stock_unit');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('receipt_format');
        });
    }
};
