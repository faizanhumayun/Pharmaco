<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            /*
             * How this business counts what it holds: whole packs, or the loose
             * items inside them. It changes no figure — only what every screen
             * calls a quantity, which is the difference between a stock list a
             * pharmacy can read and one it has to translate.
             */
            $table->string('stock_unit', 10)->default('pack')->after('currency');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('stock_unit');
        });
    }
};
