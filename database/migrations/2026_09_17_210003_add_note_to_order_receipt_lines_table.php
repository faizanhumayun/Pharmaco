<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_receipt_lines', function (Blueprint $table) {
            // Why the delivery differed: "out of stock until next month",
            // "two cartons damaged". The figure says what came; this says why.
            $table->string('note', 255)->nullable()->after('discount_percent');
        });

        // Zero becomes a recordable quantity, because "we checked, and none of
        // it came" is a different fact from "we never got to this line" — and
        // only the first one can carry a reason. No line at all still means
        // the second.
        DB::statement('ALTER TABLE order_receipt_lines DROP CHECK chk_receipt_lines_quantities');
        DB::statement('ALTER TABLE order_receipt_lines ADD CONSTRAINT chk_receipt_lines_quantities CHECK (
            packs >= 0
            AND rate >= 0
            AND (cartons IS NULL OR cartons > 0)
            AND (case_size IS NULL OR case_size > 0)
            AND (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))
        )');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_receipt_lines DROP CHECK chk_receipt_lines_quantities');
        DB::statement('ALTER TABLE order_receipt_lines ADD CONSTRAINT chk_receipt_lines_quantities CHECK (
            packs > 0
            AND rate >= 0
            AND (cartons IS NULL OR cartons > 0)
            AND (case_size IS NULL OR case_size > 0)
            AND (discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100))
        )');

        Schema::table('order_receipt_lines', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
