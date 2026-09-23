<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The document: what the operator typed, stored verbatim.
         *
         * This matters more than it looks. Keeping the raw inputs means that if
         * the posting rules are later found to be wrong, days can be re-posted
         * from the original data rather than reconstructed from memory. The
         * document is the evidence; the ledger is the interpretation.
         */
        Schema::create('daily_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 20)->default('draft');

            // Purchases — split, because a purchase either raises a payable or
            // lowers cash and one field cannot express both.
            $table->decimal('purchase_credit', 18, 2)->default(0);
            $table->decimal('purchase_cash', 18, 2)->default(0);
            $table->decimal('purchase_discount', 18, 2)->default(0);
            $table->decimal('purchase_return', 18, 2)->default(0);

            // Sales — cash and credit are entered; the total is computed.
            $table->decimal('sale_cash', 18, 2)->default(0);
            $table->decimal('sale_credit', 18, 2)->default(0);
            $table->decimal('sales_return', 18, 2)->default(0);
            $table->decimal('gross_profit', 18, 2)->default(0);

            // Market
            $table->decimal('collection_cash', 18, 2)->default(0);
            $table->decimal('discount_allowed', 18, 2)->default(0);
            $table->decimal('bad_debt', 18, 2)->default(0);

            // Companies
            $table->decimal('company_payment_cash', 18, 2)->default(0);
            $table->decimal('discount_received', 18, 2)->default(0);
            // Free text until Phase 9 gives companies their own ledgers; kept so
            // there is history to allocate rather than a blank.
            $table->string('company_note', 255)->nullable();

            // Cash movements
            $table->decimal('expenses_cash', 18, 2)->default(0);
            $table->decimal('owner_drawing', 18, 2)->default(0);
            $table->decimal('owner_capital', 18, 2)->default(0);

            // Stored for audit: net sales less gross profit, as it was computed
            // on the day.
            $table->decimal('derived_cogs', 18, 2)->default(0);

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            // The duplicate-day guard. This constraint, not UI discipline, is
            // what stops a day being entered twice and doubling every balance.
            $table->unique(['business_id', 'business_date']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_entries');
    }
};
