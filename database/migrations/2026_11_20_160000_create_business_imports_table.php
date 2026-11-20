<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A business moved in from its old POS: the file that was uploaded, what was
 * chosen from it, and what the import did. Not a financial table — the money
 * it brings over is posted to the ledger like everything else — so a
 * discarded upload is simply marked discarded and its file removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('original_name');
            // Null once the file has been removed, which is as soon as it is
            // imported or discarded: the old database holds more than we take.
            $table->string('path')->nullable();
            $table->string('status', 20)->default('pending');
            $table->json('options')->nullable();
            $table->json('result')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('imported_by')->nullable()->constrained('users');
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_imports');
    }
};
