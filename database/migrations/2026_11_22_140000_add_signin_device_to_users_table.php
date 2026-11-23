<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which device a person is signed in on.
 *
 * One person is signed in on one device at a time: signing in somewhere else
 * moves them there. The device is named by the code its browser carries
 * (devices.uuid) rather than by a row id, because the sign-in happens before
 * that browser has a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'signin_device')) {
            Schema::table('users', function (Blueprint $table) {
                $table->uuid('signin_device')->nullable()->after('remember_token');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'signin_device')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('signin_device');
            });
        }
    }
};
