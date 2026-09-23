<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A purchase now splits between what was paid at the time and what was
        // left owing, and the closing screen has to show both for its cash and
        // company sections to add up.
        Schema::table('daily_closings', function (Blueprint $table) {
            $table->decimal('purchases_paid', 18, 2)->default(0)->after('purchases');
            $table->decimal('purchases_on_account', 18, 2)->default(0)->after('purchases_paid');
        });
    }

    public function down(): void
    {
        Schema::table('daily_closings', function (Blueprint $table) {
            $table->dropColumn(['purchases_paid', 'purchases_on_account']);
        });
    }
};
