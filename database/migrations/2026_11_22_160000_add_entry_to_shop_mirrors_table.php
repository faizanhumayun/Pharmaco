<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a shop's books are being entered at the moment.
 *
 *   shop       on the shop's own computer — the ordinary state; the copy
 *              here is read-only
 *   pausing    the back office has asked for the books; waiting for the
 *              shop's computer to send its last entries and lock itself
 *   office     the shop's computer is locked; the books are entered here
 *   returning  the fix is done; waiting for the shop's computer to take the
 *              changes back and open again
 *
 * One place at a time, always: that is what the four states are for.
 */
return new class extends Migration
{
    protected $connection = 'main';

    public function up(): void
    {
        Schema::connection('main')->table('shop_mirrors', function (Blueprint $table) {
            if (! Schema::connection('main')->hasColumn('shop_mirrors', 'entry')) {
                $table->string('entry', 20)->default('shop');
                $table->timestamp('entry_changed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection('main')->table('shop_mirrors', fn (Blueprint $table) => $table->dropColumn(['entry', 'entry_changed_at']));
    }
};
