<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's token, kept (encrypted with the app key) so the back office can
 * show it again — for a replacement computer, which takes over with the same
 * token rather than a new one.
 *
 * Only the hash was kept before. A shop registered then has no token here
 * until "New token" is used once.
 */
return new class extends Migration
{
    protected $connection = 'main';

    public function up(): void
    {
        Schema::connection('main')->table('shop_mirrors', function (Blueprint $table) {
            if (! Schema::connection('main')->hasColumn('shop_mirrors', 'token')) {
                $table->text('token')->nullable()->after('token_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('main')->table('shop_mirrors', fn (Blueprint $table) => $table->dropColumn('token'));
    }
};
