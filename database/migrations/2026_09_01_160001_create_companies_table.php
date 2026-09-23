<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->string('name', 160);
            $table->string('code', 40)->nullable();

            // Its child account under 2000. The control balance is then the sum
            // of the children by construction rather than by maintenance.
            $table->foreignId('account_id')->nullable()->constrained()->restrictOnDelete();

            $table->unsignedSmallInteger('credit_days')->nullable();
            $table->string('contact', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['business_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
