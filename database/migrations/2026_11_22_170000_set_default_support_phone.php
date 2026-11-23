<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The number a shop paused for a fix is told to call. A starting value only:
 * it is kept under Shop copies in the back office from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('system_settings') && ! DB::table('system_settings')->where('key', 'support_phone')->exists()) {
            DB::table('system_settings')->insert([
                'key' => 'support_phone',
                'value' => json_encode('03350533049'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        //
    }
};
