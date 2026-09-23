<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which order form this invoice answers, when it came in through a
        // recorded delivery. Only a link: the money still lives on the purchase
        // line and still reaches the ledger through the daily entry, so there
        // is exactly one route in and this column does not create a second.
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('company_name')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
