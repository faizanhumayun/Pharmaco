<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The staging area between "we read a PDF" and "these are the products".
        // Nothing here is trusted: cells holds what came out of the file, values
        // holds what the operator confirmed, and the two stay side by side so a
        // wrong reading can always be traced back to the line it came from.
        Schema::create('company_product_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_product_import_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('page_no')->default(1);
            $table->unsignedInteger('line_no');

            $table->json('cells');
            $table->json('values')->nullable();
            $table->json('issues')->nullable();

            $table->boolean('included')->default(true);
            $table->boolean('edited')->default(false);

            $table->timestamps();

            $table->index(['company_product_import_id', 'page_no', 'line_no'], 'import_rows_position_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_product_import_rows');
    }
};
