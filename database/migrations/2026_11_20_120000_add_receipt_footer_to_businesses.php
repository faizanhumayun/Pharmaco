<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The line at the foot of a till slip — "Thank you — get well soon." unless
 * the business writes its own. Null means the usual line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('receipt_footer', 160)->nullable()->after('receipt_format');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('receipt_footer'));
    }
};
