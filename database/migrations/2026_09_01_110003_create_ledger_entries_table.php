<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();

            // business_id and business_date are denormalized from the parent
            // transaction on purpose: it keeps the one hot query in this
            // application single-table.
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('transaction_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->date('business_date');

            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->string('memo', 255)->nullable();

            // No updated_at: rows are never updated.
            $table->timestamp('created_at')->useCurrent();

            // The hot path: sum entries for one business, one account, up to one
            // date. Every balance in the application is a variation on it.
            $table->index(['business_id', 'account_id', 'business_date'], 'ledger_entries_balance_index');
            $table->index(['business_id', 'business_date']);
            $table->index('transaction_id');
        });

        // A line is a debit or a credit, never both, and never negative.
        // Enforced by the database as well as the posting service, because the
        // service can be bypassed and the database cannot.
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_debit_non_negative CHECK (debit >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_credit_non_negative CHECK (credit >= 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_one_sided CHECK (debit = 0 OR credit = 0)');
        DB::statement('ALTER TABLE ledger_entries ADD CONSTRAINT chk_ledger_not_empty CHECK (debit > 0 OR credit > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
