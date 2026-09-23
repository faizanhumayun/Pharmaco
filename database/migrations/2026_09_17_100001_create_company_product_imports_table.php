<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per price-list PDF a company sends. The import is the document
        // that explains why a product's price is what it is, so it is kept after
        // it has been applied rather than thrown away.
        Schema::create('company_product_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();

            // The day the list was taken in, in the business's own timezone.
            $table->date('business_date');

            $table->string('original_filename', 255);
            $table->string('stored_path', 255)->nullable();
            $table->char('file_hash', 64);
            $table->unsignedSmallInteger('page_count')->default(0);

            $table->string('status', 16)->default('draft');

            // Which extracted column feeds which product field. Held on the
            // import so a re-parse reproduces exactly what the operator saw.
            $table->json('column_map')->nullable();
            $table->json('warnings')->nullable();

            $table->unsignedInteger('rows_detected')->default(0);
            $table->unsignedInteger('products_created')->default(0);
            $table->unsignedInteger('products_updated')->default(0);
            $table->unsignedInteger('products_unchanged')->default(0);
            $table->unsignedInteger('rows_skipped')->default(0);

            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'company_id', 'status']);
            $table->index(['company_id', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_product_imports');
    }
};
