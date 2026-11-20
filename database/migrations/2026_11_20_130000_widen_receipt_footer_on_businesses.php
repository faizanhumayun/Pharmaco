<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The foot of the slip is now written in an editor — several lines, with bold,
 * italic and underline — so it needs more room than one short line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->text('receipt_footer')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->string('receipt_footer', 160)->nullable()->change());
    }
};
