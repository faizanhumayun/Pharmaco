<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A shop's copy can be kept in the platform's own database, beside the
 * businesses that work online, instead of in a database of its own.
 *
 * A business that is such a copy says which shop it came from (`mirror_id`),
 * and that is what makes it read-only. A login that arrived with a shop's
 * books says so too, so later copies can keep its name and password in step
 * without ever touching a login the platform made itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_mirrors', function (Blueprint $table) {
            // Empty: the copy is kept in the platform's own database.
            $table->string('database', 64)->nullable()->change();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->unsignedBigInteger('mirror_id')->nullable()->index()->after('id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('mirror_id')->nullable()->index()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('mirror_id'));
        Schema::table('businesses', fn (Blueprint $table) => $table->dropColumn('mirror_id'));
    }
};
