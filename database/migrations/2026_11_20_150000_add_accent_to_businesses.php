<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The colour a business has chosen for its own screens, from a fixed set
 * (App\Enums\AccentColour). Null means the usual one for its kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('accent', 20)->nullable()->after('receipt_format');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('accent'));
    }
};
