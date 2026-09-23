<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A third state, so "what have I ordered that has not arrived?" has an
        // answer. It still posts nothing: receiving records that the delivery
        // turned up, while the money is entered in the daily entry as before.
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('received_at')->nullable()->after('sent_at');
            $table->foreignId('received_by')->nullable()->after('received_at')
                ->constrained('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('received_by');
            $table->dropColumn('received_at');
        });
    }
};
