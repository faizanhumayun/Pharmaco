<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * How far past the recorded stock this line went.
         *
         * A counter sells what is on the shelf, and the shelf is sometimes
         * ahead of the books — a delivery not yet entered, a miscount. The
         * sale is allowed, because refusing it only means the bill is written
         * on paper and never reaches the system at all. What is not allowed is
         * for it to go unnoticed: this column is what turns "someone sold
         * something we had no record of" into a list the owner can work
         * through.
         *
         * 0 for an ordinary line. Counted in packs, not money.
         */
        Schema::table('pos_bill_lines', function (Blueprint $table) {
            $table->unsignedInteger('short_by')->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('pos_bill_lines', function (Blueprint $table) {
            $table->dropColumn('short_by');
        });
    }
};
