<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * A count, batch by batch.
         *
         * When the shelf disagrees with the books, the counter goes through the
         * product's batches as a delivery is checked in: each batch the books
         * say is there, what was found of it, and any batch on the shelf the
         * books did not know about. The count's own totals are the sum of these.
         *
         * Evidence only, like the count itself: nothing here moves stock.
         */
        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();

            $table->string('batch_no', 60)->nullable();
            $table->date('expires_on')->nullable();

            // What the books put in this batch at the moment of counting, and
            // what was on the shelf. A batch found but not on the books
            // expected nothing.
            $table->integer('expected_packs')->default(0);
            $table->integer('expected_loose')->default(0);
            $table->integer('counted_packs')->default(0);
            $table->integer('counted_loose')->default(0);
            $table->boolean('is_new')->default(false);

            $table->timestamps();

            $table->index(['business_id', 'stock_count_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
    }
};
