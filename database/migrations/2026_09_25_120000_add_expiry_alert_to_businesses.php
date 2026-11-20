<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * How long before a batch expires it should start being flagged.
         *
         * A trade decision, not a technical one: a distributor will not take
         * short-dated goods and wants months of warning, while a busy counter
         * may only care once it is weeks away. Null means say nothing.
         */
        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedSmallInteger('expiry_alert_months')->nullable()->default(6)->after('receipt_format');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', fn (Blueprint $t) => $t->dropColumn('expiry_alert_months'));
    }
};
