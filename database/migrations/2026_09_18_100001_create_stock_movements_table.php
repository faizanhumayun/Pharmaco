<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every pack that has come in or gone out, one row apiece.
        //
        // This is to quantities what ledger_entries is to money: the quantity
        // held is the sum of these and is never stored anywhere, so a product's
        // count cannot drift from the events that produced it. Deliveries write
        // here today; the point of sale will write here too, and until it does
        // the figure counts what has arrived and what has been adjusted.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_product_id')->constrained()->restrictOnDelete();

            $table->date('business_date');
            $table->string('type', 20);

            // Signed: positive came in, negative went out. One column rather
            // than a quantity and a direction, so no row can contradict itself.
            $table->integer('packs');

            // What a pack was worth at the time, for valuing what is held.
            $table->decimal('unit_cost', 18, 2)->nullable();

            // The document that caused it — a delivery line today, a sale later.
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['business_id', 'company_product_id', 'business_date'], 'stock_movements_product_index');
            $table->index(['source_type', 'source_id']);
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT chk_stock_movements CHECK (
            packs <> 0 AND (unit_cost IS NULL OR unit_cost >= 0)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
