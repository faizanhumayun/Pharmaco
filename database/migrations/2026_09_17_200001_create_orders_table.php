<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An order form is a request sent to a company, not a transaction. It
        // never posts: nothing has been bought, nothing is owed, and no figure
        // on any other screen moves because one exists. When the goods and the
        // invoice arrive, the purchase is entered in the daily entry as usual.
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            // Sequential per business, and what you quote on the phone.
            $table->string('reference', 20);

            $table->date('business_date');
            $table->string('status', 16)->default('draft');

            // The extra discount a company commits to on an order — "13% on
            // everything". It applies to every line that does not name its own,
            // and is held as a rate rather than as an amount so the arithmetic
            // on the form is the same arithmetic the company will invoice.
            $table->decimal('discount_percent', 6, 3)->nullable();

            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'reference']);
            $table->index(['business_id', 'company_id', 'status']);
            $table->index(['business_id', 'business_date']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_discount CHECK (
            discount_percent IS NULL OR (discount_percent >= 0 AND discount_percent <= 100)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
