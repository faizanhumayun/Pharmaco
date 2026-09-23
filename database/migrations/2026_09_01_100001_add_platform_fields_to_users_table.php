<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // The App Owner operates the platform and is a member of no business.
            // Platform scope is deliberately separate from business-scoped roles.
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_platform_admin');
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->index('is_platform_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_platform_admin']);
            $table->dropColumn(['is_platform_admin', 'is_active', 'last_login_at']);
        });
    }
};
