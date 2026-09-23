<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A sale over the counter, item by item.
         *
         * The day's entry stays the financial document: every bill adds one
         * sale line to it, and the day posts as it always has. A bill is the
         * detail behind that line — what was sold, at what price and at what
         * cost — which is what makes the day's gross profit a fact rather than
         * a figure somebody types.
         */
        Schema::create('pos_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // The day it belongs to, in the business's own timezone, and the
            // entry it fed. Both are how a bill is found from the day.
            $table->date('business_date');
            $table->foreignId('daily_entry_id')->nullable()->constrained()->nullOnDelete();

            // Counts from 1 per business; shown on the receipt.
            $table->unsignedInteger('bill_no');

            $table->string('customer_name', 120)->nullable();

            $table->decimal('total', 18, 2);
            $table->decimal('discount', 18, 2)->default(0);

            // What was actually taken at the counter. Less than the total is
            // credit; the day's entry splits it exactly as it does for any
            // other invoice.
            $table->decimal('received', 18, 2)->default(0);

            // Sale value less what the goods cost, from the product's own
            // purchase rate at the time of sale.
            $table->decimal('cost', 18, 2)->default(0);

            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'bill_no']);
            $table->index(['business_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_bills');
    }
};
