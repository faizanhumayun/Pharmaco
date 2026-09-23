<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();

            // The position is as at the close of business on this date.
            $table->date('opening_date');
            $table->string('status', 20)->default('draft');

            // Snapshotted at finalization, so a later dispute can compare what
            // was confirmed against what the ledger says today.
            $table->decimal('total_assets', 18, 2)->default(0);
            $table->decimal('total_liabilities', 18, 2)->default(0);
            $table->decimal('net_position', 18, 2)->default(0);
            $table->decimal('balancing_figure', 18, 2)->default(0);

            $table->string('confirmation_text', 500)->nullable();

            // Where an unusual net position gets explained rather than ignored.
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();

            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();

            $table->timestamps();

            // One opening balance per business, enforced by the database rather
            // than by hope. There is only ever one starting point.
            $table->unique('business_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
