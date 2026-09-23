<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional detail beneath the day's purchase total. When a line names a
        // company, its share of what is still owed posts to that company's own
        // ledger instead of the unallocated pool.
        Schema::create('purchase_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('company_name', 160)->nullable();
            $table->string('invoice_no', 60)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('paid', 18, 2)->default(0);
            $table->timestamps();

            $table->index('company_id');
        });

        Schema::table('daily_entries', function (Blueprint $table) {
            // A distributor thinks "I bought this much, paid this much, owe the
            // rest" — not "credit purchases" and "cash purchases".
            $table->decimal('purchase_total', 18, 2)->default(0)->after('status');
            $table->decimal('purchase_paid', 18, 2)->default(0)->after('purchase_total');
        });

        DB::table('daily_entries')->update([
            'purchase_total' => DB::raw('purchase_credit + purchase_cash'),
            'purchase_paid' => DB::raw('purchase_cash'),
        ]);

        Schema::table('daily_entries', function (Blueprint $table) {
            $table->dropColumn(['purchase_credit', 'purchase_cash']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_entries', function (Blueprint $table) {
            $table->decimal('purchase_credit', 18, 2)->default(0);
            $table->decimal('purchase_cash', 18, 2)->default(0);
        });

        DB::table('daily_entries')->update([
            'purchase_cash' => DB::raw('purchase_paid'),
            'purchase_credit' => DB::raw('purchase_total - purchase_paid'),
        ]);

        Schema::table('daily_entries', function (Blueprint $table) {
            $table->dropColumn(['purchase_total', 'purchase_paid']);
        });

        Schema::dropIfExists('purchase_lines');
    }
};
