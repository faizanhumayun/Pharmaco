<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The site-wide access gate.
 *
 * `security_codes` belongs to the platform, not to a business: one set of
 * codes stands in front of the whole site. A code lets someone in only while
 * its status is on. `system_settings` holds the platform's own switches — the
 * first of them is whether the gate is on at all.
 *
 * Neither table is financial and neither carries a business_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label')->nullable();
            $table->boolean('status')->default(true); // 1 = active, 0 = inactive
            $table->timestamps();
        });

        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // Default access codes, so the gate can be switched on straight away.
        $now = now();
        DB::table('security_codes')->insert([
            ['code' => '1000', 'label' => 'Default code', 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '2000', 'label' => 'Default code', 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '3000', 'label' => 'Default code', 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => '4000', 'label' => 'Default code', 'status' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('system_settings');
        Schema::dropIfExists('security_codes');
    }
};
