<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();

            // The business day this belongs to, in the business's own timezone.
            // A DATE, never a DATETIME: timezone drift on a datetime silently
            // moves transactions between days.
            $table->date('business_date');

            $table->string('type', 40);
            $table->string('reference_no', 60)->nullable();
            $table->string('narration', 255)->nullable();

            // The headline amount, for listing and search only. Never used in
            // balance arithmetic — that reads ledger_entries.
            $table->decimal('amount', 18, 2)->default(0);

            // The document that caused this posting.
            $table->nullableMorphs('source');

            $table->string('status', 20)->default('posted');

            $table->foreignId('reversal_of_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('reversed_by_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->string('correction_reason', 500)->nullable();

            // Set when a late document is posted to a later day than it belongs to.
            $table->date('original_business_date')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'business_date', 'status']);
            $table->index(['business_id', 'type', 'business_date']);
        });

        DB::statement('ALTER TABLE transactions ADD CONSTRAINT chk_transactions_amount CHECK (amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
