<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The transaction that put an approved company audit into the books.
        Schema::table('company_audits', function (Blueprint $table) {
            $table->foreignId('transaction_id')->nullable()->after('note')->constrained()->nullOnDelete();
        });

        /*
         * A day's stock found short or over on counts, at cost: positive is
         * stock lost, negative stock found. Kept on the closing beside the
         * other figures so a closed day says why its net profit moved.
         */
        Schema::table('daily_closings', function (Blueprint $table) {
            $table->decimal('stock_variance', 18, 2)->default(0)->after('net_profit');
        });
    }

    public function down(): void
    {
        Schema::table('daily_closings', fn (Blueprint $table) => $table->dropColumn('stock_variance'));
        Schema::table('company_audits', function (Blueprint $table) {
            $table->dropConstrainedForeignId('transaction_id');
        });
    }
};
