<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The market, named. Deliberately the mirror image of `companies`: a
        // pharmacy owes the distributor exactly as the distributor owes a
        // company, so the two sides of the book stay the same shape.
        Schema::create('pharmacies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 40)->nullable();

            // Its child account under 1100, so the receivables control balance
            // is the sum of the pharmacies by construction.
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('credit_days')->nullable();
            $table->string('contact', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('area', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pharmacies');
    }
};
