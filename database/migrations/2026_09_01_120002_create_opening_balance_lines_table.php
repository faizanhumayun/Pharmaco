<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Header plus lines rather than four fixed columns: adding a fifth
        // opening figure later is then data entry, not a migration.
        Schema::create('opening_balance_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_balance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2)->default(0);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            $table->unique(['opening_balance_id', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balance_lines');
    }
};
