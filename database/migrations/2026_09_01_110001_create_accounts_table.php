<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();

            // Referenced by code constant, never by name or id — ids differ per
            // business, and names are editable.
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('type', 20);

            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();

            // A control account is posted to directly only while it has no
            // children. Phase 9 gives 2000 per-company children and flips this.
            $table->boolean('is_control')->default(false);
            $table->boolean('is_postable')->default(true);

            // Links a sub-ledger account to the company or customer it represents.
            $table->nullableMorphs('subject');

            // Seeded accounts cannot be renamed or removed by users.
            $table->boolean('is_system')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('description', 255)->nullable();

            $table->timestamps();

            $table->unique(['business_id', 'code']);
            $table->index(['business_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
