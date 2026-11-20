<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods brought back to the counter, and bills corrected after the fact.
 *
 * A return is its own document against the bill it came from. A revision is
 * the bill as it stood before an edit and as it stood after, kept so that
 * "what was on this bill last Tuesday" always has an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('pharmacy_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('daily_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->date('business_date');
            $table->unsignedInteger('return_no');
            // What the goods coming back were sold for, and what they cost.
            $table->decimal('total', 18, 2);
            $table->decimal('cost', 18, 2)->default(0);
            // Handed back in cash. The rest comes off what the customer owes.
            $table->decimal('refunded', 18, 2)->default(0);
            $table->string('reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'return_no']);
            $table->index(['business_id', 'business_date']);
        });

        Schema::create('pos_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pos_bill_line_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 200);
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('loose_quantity')->default(0);
            $table->unsignedInteger('units_per_pack')->default(1);
            $table->decimal('unit_price', 18, 2);
            $table->decimal('unit_cost', 18, 2)->default(0);
            $table->decimal('line_total', 18, 2);
            $table->timestamps();
        });

        // The day's side of a return: who it was for and how it was settled.
        Schema::create('return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('daily_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pharmacy_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('pharmacy_name', 160)->nullable();
            $table->string('reference', 60)->nullable();
            $table->decimal('amount', 18, 2);
            $table->decimal('refunded', 18, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('pos_bill_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('pos_bill_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('revision_no');
            $table->string('reason', 255);
            // The bill before and after, and the difference in plain terms.
            $table->json('before');
            $table->json('after');
            $table->json('changes');
            $table->foreignId('edited_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['pos_bill_id', 'revision_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_bill_revisions');
        Schema::dropIfExists('return_lines');
        Schema::dropIfExists('pos_return_lines');
        Schema::dropIfExists('pos_returns');
    }
};
