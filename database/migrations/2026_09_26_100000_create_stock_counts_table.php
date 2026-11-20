<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * One product, counted on one day.
         *
         * A shop with a thousand lines cannot count them all in an afternoon,
         * so it counts a few every day and comes round again. This is the
         * record of each of those counts: what the books said, what was on the
         * shelf, and what was done about the difference.
         *
         * Counting and correcting are two hands. A count is written here by
         * whoever walked the shelf and changes nothing; the stock figure only
         * moves when someone with the authority approves it, and then through
         * the one class that writes stock movements.
         */
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_product_id')->constrained()->cascadeOnDelete();
            $table->date('business_date');

            // What the books held at the moment of counting, kept as it was:
            // stock moves on, and a finding has to be judged against the figure
            // the counter was actually disagreeing with.
            $table->integer('expected_packs');
            $table->integer('counted_packs');

            $table->string('status', 20)->default('found');
            $table->string('note', 255)->nullable();

            $table->foreignId('counted_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();

            // The movement that put it right, when one was made.
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamps();

            $table->index(['business_id', 'business_date']);
            $table->index(['business_id', 'company_product_id']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_counts');
    }
};
