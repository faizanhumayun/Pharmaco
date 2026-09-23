<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * The closed day's figures — and also the snapshot table, because a
         * closed day and a snapshot are the same thing.
         *
         * Every column here is DERIVED. `closings:rebuild` must reproduce all
         * of them exactly from the ledger; if it cannot, this table has become
         * a second source of truth and the core requirement is broken.
         */
        Schema::create('daily_closings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->string('status', 20)->default('finalized');

            foreach ([
                'opening_stock', 'opening_cash', 'opening_receivable', 'opening_payable',
                'purchases', 'sales', 'cogs', 'gross_profit', 'expenses', 'net_profit',
                'collections', 'company_payments', 'credit_sales', 'cash_sales',
                'closing_stock', 'closing_cash', 'closing_receivable', 'closing_payable',
                'receivable_delta', 'payable_delta', 'net_position',
            ] as $column) {
                $table->decimal($column, 18, 2)->default(0);
            }

            // The physical count, and any difference between it and the ledger.
            $table->decimal('counted_cash', 18, 2)->nullable();
            $table->decimal('cash_variance', 18, 2)->nullable();
            $table->string('variance_reason', 500)->nullable();

            $table->foreignId('finalized_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('built_at')->nullable();
            $table->string('reopen_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'business_date']);
            $table->index(['business_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_closings');
    }
};
