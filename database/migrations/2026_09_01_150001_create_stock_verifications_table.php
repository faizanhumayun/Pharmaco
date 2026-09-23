<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The control that bounds stock drift.
         *
         * Stock is the only headline balance with no independent check: cash is
         * counted nightly, receivables are agreed against statements, and stock
         * is derived from a profit figure produced by another system. Every
         * error in it is permanent and cumulative until somebody counts.
         */
        Schema::create('stock_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->date('business_date');

            $table->decimal('book_value', 18, 2);
            $table->decimal('counted_value', 18, 2);
            $table->decimal('variance', 18, 2);
            $table->decimal('variance_pct', 8, 4)->default(0);

            $table->string('reason', 500)->nullable();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('verified_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'business_date']);
            $table->index(['business_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_verifications');
    }
};
