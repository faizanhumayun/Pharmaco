<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional detail beneath the day's sales total, mirroring purchase
        // lines. When a line names a pharmacy, its share of what is still owed
        // posts to that pharmacy's own ledger instead of the unallocated pool.
        Schema::create('sale_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('pharmacy_name', 160)->nullable();
            $table->string('invoice_no', 60)->nullable();
            $table->decimal('amount', 18, 2);

            // What came back over the counter. More than the invoice settles
            // that pharmacy's earlier credit, exactly as on the purchase side.
            $table->decimal('received', 18, 2)->default(0);
            $table->timestamps();

            $table->index('pharmacy_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_lines');
    }
};
