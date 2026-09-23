<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                // Scoped so the audit trail can be read per tenant, and indexed
                // because it is read by business and date, never by id.
                $table->foreignId('business_id')->nullable()->after('id');

                // Required on any correction. "User X updated record 42" is not
                // an audit trail; "changed 50,000 to 5,000 because …" is.
                $table->string('reason', 500)->nullable()->after('properties');
                $table->string('ip_address', 45)->nullable()->after('reason');

                $table->index(['business_id', 'created_at']);
            });
    }

    public function down(): void
    {
        Schema::connection(config('activitylog.database_connection'))
            ->table(config('activitylog.table_name'), function (Blueprint $table) {
                $table->dropIndex(['business_id', 'created_at']);
                $table->dropColumn(['business_id', 'reason', 'ip_address']);
            });
    }
};
