<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Money recovered from one named customer on one day.
         *
         * The day already carries a single `collection_cash` figure, and that
         * figure has nowhere to go but the unallocated ledger — which is why a
         * named customer's balance has only ever gone up. A line here says who
         * paid, so the credit lands on their own ledger and their statement
         * finally comes down.
         */
        Schema::create('collection_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->nullable()->constrained()->nullOnDelete();
            // Kept as typed, so a line survives a customer being renamed.
            $table->string('pharmacy_name', 160)->nullable();
            $table->decimal('amount', 18, 2);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->index(['daily_entry_id', 'pharmacy_id']);
        });

        /*
         * Which bills that money paid off. A collection is against the account,
         * not against one bill — the delivery boy clears what he can — so this
         * records how it was applied, oldest first. Money beyond every open
         * bill has no row here and simply sits on the account.
         */
        Schema::create('collection_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_line_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_bill_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 18, 2);
            $table->timestamps();

            $table->index('pos_bill_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_allocations');
        Schema::dropIfExists('collection_lines');
    }
};
