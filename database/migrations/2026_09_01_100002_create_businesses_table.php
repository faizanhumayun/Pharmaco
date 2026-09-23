<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->string('slug', 160)->unique();
            $table->string('business_type', 30)->default('distributor');
            $table->string('status', 30)->default('setup');
            $table->char('currency', 3)->default('PKR');
            $table->string('timezone', 64)->default('Asia/Karachi');

            // Null until the opening balance is finalized (Phase 4).
            $table->date('opening_date')->nullable();
            // Last closed business day. Written only by the closing service (Phase 6).
            $table->date('locked_through_date')->nullable();

            $table->string('address', 255)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 160)->nullable();
            $table->string('ntn', 40)->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index('business_type');
        });

        // NOTE: no balance columns here, ever. Balances are derived from the ledger.
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
